import { ref, computed } from 'vue';
import type { Ref } from 'vue';
import type { GridApi } from 'ag-grid-community';

import { fetchMatchPage, buildMatchRelation, revokeMatchRelation, type MatchConfig, type MatchColumn, type MatchCondition } from '@/service/api/match';

export interface MatchColumnRole {
  key: string;
  label: string;
  amount: string;
  target: string;
}

export interface MatchModuleData {
  functionCode: string;
  moduleName: string;
  config: MatchConfig | null;
  columns: MatchColumn[];
  rows: any[];
  total: number;
  matchCols: MatchColumnRole;
  calcFields: string[];
  loading: boolean;
  gridApi: GridApi<any> | null;
}

export interface MatchStore {
  aData: Ref<MatchModuleData>;
  bData: Ref<MatchModuleData>;
  displayFilter: Ref<'all' | 'matched' | 'unmatched' | 'candidate'>;
  aSelectedKeys: Ref<string[]>;
  bSelectedKeys: Ref<string[]>;
  isSaving: Ref<boolean>;
  matchConditions: Ref<MatchCondition[]>;
  selectedConditionIndices: Ref<number[]>;
  aCandidateKeys: Set<string>;
  bCandidateKeys: Set<string>;

  loadData: (
    functionCode: string,
    aModule: string, bModule: string,
    aConfig: MatchConfig, bConfig: MatchConfig,
    aColumns: MatchColumn[], bColumns: MatchColumn[],
    aMatchCols: MatchColumnRole, bMatchCols: MatchColumnRole,
    aRows: any[], bRows: any[],
    matchConditions?: MatchCondition[],
    aCalcFields?: string[],
    bCalcFields?: string[]
  ) => void;
  setAGridApi: (api: GridApi<any>) => void;
  setBGridApi: (api: GridApi<any>) => void;
  updateASelected: (keys: string[]) => void;
  updateBSelected: (keys: string[]) => void;
  updateSelectedConditions: (indices: number[]) => void;
  buildRelation: () => Promise<void>;
  revokeRelation: () => Promise<void>;
  refreshData: () => Promise<void>;
  refreshSide: (side: 'A' | 'B') => Promise<void>;

  aMatchedKeys: Map<string, string[]>;
  bMatchedKeys: Map<string, string[]>;
}

function buildMatchMapping(
  rows: any[],
  matchCols: MatchColumnRole
): Map<string, string[]> {
  const map = new Map<string, string[]>();
  for (const row of rows) {
    const key = String(row[matchCols.key] ?? '');
    if (!key) continue;
    const targets = row[matchCols.target] ?? '';
    const targetArray = targets ? String(targets).split(',').filter(Boolean) : [];
    map.set(key, targetArray);
  }
  return map;
}

/**
 * 取列定义中第一个业务字段（跳过序号列），用作主键回退
 */
function firstBizField(columns: MatchColumn[]): string {
  const col = columns.find(c => {
    const f = String(c.field ?? '');
    return f && f !== '序号';
  });
  return col ? String(col.field) : '';
}

export function useMatchStore(): MatchStore {
  const aData = ref<MatchModuleData>({
    functionCode: '',
    moduleName: '',
    config: null,
    columns: [],
    rows: [],
    total: 0,
    matchCols: { key: '', label: '', amount: '', target: '' },
    calcFields: [],
    loading: false,
    gridApi: null
  });

  const bData = ref<MatchModuleData>({
    functionCode: '',
    moduleName: '',
    config: null,
    columns: [],
    rows: [],
    total: 0,
    matchCols: { key: '', label: '', amount: '', target: '' },
    calcFields: [],
    loading: false,
    gridApi: null
  });

  const displayFilter = ref<'all' | 'matched' | 'unmatched' | 'candidate'>('all');
  const aSelectedKeys = ref<string[]>([]);
  const bSelectedKeys = ref<string[]>([]);
  const isSaving = ref(false);
  const functionCode = ref('');
  const matchConditions = ref<MatchCondition[]>([]);
  const selectedConditionIndices = ref<number[]>([]);

  const aMatchedKeysComputed = computed(() => buildMatchMapping(aData.value.rows, aData.value.matchCols));
  const bMatchedKeysComputed = computed(() => buildMatchMapping(bData.value.rows, bData.value.matchCols));

  function buildFieldNameMap(columns: MatchColumn[]): Map<string, string> {
    const map = new Map<string, string>();
    for (const col of columns) {
      const fieldName = String(col['字段名'] ?? '');
      if (!fieldName) continue;
      const colName = String(col['列名'] ?? '');
      const queryName = String(col['查询名'] ?? '');
      if (colName) map.set(colName, fieldName);
      if (queryName) map.set(queryName, fieldName);
      map.set(fieldName, fieldName);
    }
    return map;
  }

  const aFieldNameMap = computed(() => buildFieldNameMap(aData.value.columns));
  const bFieldNameMap = computed(() => buildFieldNameMap(bData.value.columns));

  // 根据选中的匹配条件和选中的行，计算另一侧的候选 key
  // 勾选 A 表数据 -> 在 B 表中找出满足选中条件的记录
  const bCandidateKeysComputed = computed<Set<string>>(() => {
    if (aSelectedKeys.value.length === 0 || selectedConditionIndices.value.length === 0) {
      return new Set<string>();
    }
    const conditions = selectedConditionIndices.value
      .map(i => matchConditions.value[i])
      .filter(Boolean);
    if (conditions.length === 0) {
      return new Set<string>();
    }

    let aKeyField = aData.value.matchCols.key;
    if (!aKeyField) {
      aKeyField = firstBizField(aData.value.columns);
    }
    const aRows = aData.value.rows.filter(row =>
      aSelectedKeys.value.includes(String(row[aKeyField] ?? ''))
    );

    let bKey = bData.value.matchCols.key;
    if (!bKey) {
      bKey = firstBizField(bData.value.columns);
    }
    const result = new Set<string>();
    for (const bRow of bData.value.rows) {
      for (const aRow of aRows) {
        const allMatch = conditions.every(cond => {
          let aField = cond.aField;
          let bField = cond.bField;
          if (aRow[cond.aField] === undefined) {
            const mapped = aFieldNameMap.value.get(cond.aField);
            if (mapped) aField = mapped;
          }
          if (bRow[cond.bField] === undefined) {
            const mapped = bFieldNameMap.value.get(cond.bField);
            if (mapped) bField = mapped;
          }
          const aValue = aRow[aField];
          const bValue = bRow[bField];
          return String(aValue ?? '') === String(bValue ?? '');
        });
        if (allMatch) {
          const candidateKey = String(bRow[bKey] ?? '');
          result.add(candidateKey);
          break;
        }
      }
    }
    return result;
  });

  // 勾选 B 表数据 -> 在 A 表中找出满足选中条件的记录
  const aCandidateKeysComputed = computed<Set<string>>(() => {
    if (bSelectedKeys.value.length === 0 || selectedConditionIndices.value.length === 0) {
      return new Set<string>();
    }
    const conditions = selectedConditionIndices.value
      .map(i => matchConditions.value[i])
      .filter(Boolean);
    if (conditions.length === 0) return new Set<string>();

    let bKeyField = bData.value.matchCols.key;
    if (!bKeyField) {
      bKeyField = firstBizField(bData.value.columns);
    }
    const bRows = bData.value.rows.filter(row =>
      bSelectedKeys.value.includes(String(row[bKeyField] ?? ''))
    );

    let aKey = aData.value.matchCols.key;
    if (!aKey) {
      aKey = firstBizField(aData.value.columns);
    }
    const result = new Set<string>();
    for (const aRow of aData.value.rows) {
      for (const bRow of bRows) {
        const allMatch = conditions.every(cond => {
          let aField = cond.aField;
          let bField = cond.bField;
          if (aRow[cond.aField] === undefined) {
            const mapped = aFieldNameMap.value.get(cond.aField);
            if (mapped) aField = mapped;
          }
          if (bRow[cond.bField] === undefined) {
            const mapped = bFieldNameMap.value.get(cond.bField);
            if (mapped) bField = mapped;
          }
          const aValue = aRow[aField];
          const bValue = bRow[bField];
          return String(aValue ?? '') === String(bValue ?? '');
        });
        if (allMatch) {
          result.add(String(aRow[aKey] ?? ''));
          break;
        }
      }
    }
    return result;
  });

  function loadData(
    fc: string,
    aModule: string, bModule: string,
    aConfig: MatchConfig, bConfig: MatchConfig,
    aColumns: MatchColumn[], bColumns: MatchColumn[],
    aMatchCols: MatchColumnRole, bMatchCols: MatchColumnRole,
    aRows: any[], bRows: any[],
    conditions?: MatchCondition[],
    aCalcFields?: string[],
    bCalcFields?: string[]
  ) {
    functionCode.value = fc;

    // 为行分配序号（与普通工作台一致，序号列由后端列定义提供）
    aRows.forEach((row, index) => { row['序号'] = index + 1; });
    bRows.forEach((row, index) => { row['序号'] = index + 1; });

    aData.value = {
      functionCode: '',
      moduleName: aModule,
      config: aConfig,
      columns: aColumns,
      rows: aRows,
      total: aRows.length,
      matchCols: aMatchCols,
      calcFields: aCalcFields || [],
      loading: false,
      gridApi: aData.value.gridApi
    };

    bData.value = {
      functionCode: '',
      moduleName: bModule,
      config: bConfig,
      columns: bColumns,
      rows: bRows,
      total: bRows.length,
      matchCols: bMatchCols,
      calcFields: bCalcFields || [],
      loading: false,
      gridApi: bData.value.gridApi
    };

    aSelectedKeys.value = [];
    bSelectedKeys.value = [];
    matchConditions.value = conditions || [];
    selectedConditionIndices.value = [];
  }

  function setAGridApi(api: GridApi<any>) {
    aData.value.gridApi = api;
  }

  function setBGridApi(api: GridApi<any>) {
    bData.value.gridApi = api;
  }

  function updateASelected(keys: string[]) {
    aSelectedKeys.value = keys;
  }

  function updateBSelected(keys: string[]) {
    bSelectedKeys.value = keys;
  }

  function updateSelectedConditions(indices: number[]) {
    selectedConditionIndices.value = indices;
  }

  async function buildRelation() {
    if (aSelectedKeys.value.length === 0 || bSelectedKeys.value.length === 0) {
      throw new Error('请选择要匹配的记录');
    }

    isSaving.value = true;
    try {
      await buildMatchRelation({
        functionCode: functionCode.value,
        aModule: aData.value.moduleName,
        bModule: bData.value.moduleName,
        aKeys: aSelectedKeys.value,
        bKeys: bSelectedKeys.value
      });

      await refreshData();
      aSelectedKeys.value = [];
      bSelectedKeys.value = [];
    } finally {
      isSaving.value = false;
    }
  }

  async function revokeRelation() {
    if (aSelectedKeys.value.length === 0 && bSelectedKeys.value.length === 0) {
      throw new Error('请选择要撤销匹配的记录');
    }

    isSaving.value = true;
    try {
      await revokeMatchRelation({
        functionCode: functionCode.value,
        aModule: aData.value.moduleName,
        bModule: bData.value.moduleName,
        aKeys: aSelectedKeys.value,
        bKeys: bSelectedKeys.value,
        mode: 'all'
      });

      await refreshData();
      aSelectedKeys.value = [];
      bSelectedKeys.value = [];
    } finally {
      isSaving.value = false;
    }
  }

  async function refreshData() {
    if (!functionCode.value) return;

    const response = await fetchMatchPage(functionCode.value);
    const data = response.data;
    if (!data) {
      throw new Error('接口返回数据为空');
    }

    loadData(
      functionCode.value,
      data.meta.aModule,
      data.meta.bModule,
      data.meta.aConfig,
      data.meta.bConfig,
      data.meta.aColumns,
      data.meta.bColumns,
      data.meta.aMatchCols,
      data.meta.bMatchCols,
      data.aData.rows,
      data.bData.rows,
      data.meta.matchConditions
    );
  }

  /**
   * 仅刷新一侧数据（A 或 B）
   *
   * 后端 fetchMatchPage 一次返回两侧数据，这里只替换对应侧的 rows/columns/matchCols，
   * 另一侧保持不变，实现"刷新只针对本表"的体验。
   * 同时清空对应侧的选中状态，避免选中 key 与新数据不匹配。
   */
  async function refreshSide(side: 'A' | 'B') {
    if (!functionCode.value) return;

    const response = await fetchMatchPage(functionCode.value);
    const data = response.data;
    if (!data) {
      throw new Error('接口返回数据为空');
    }

    if (side === 'A') {
      const aRows = data.aData.rows;
      aRows.forEach((row, index) => { row['序号'] = index + 1; });
      aData.value = {
        functionCode: '',
        moduleName: data.meta.aModule,
        config: data.meta.aConfig,
        columns: data.meta.aColumns,
        rows: aRows,
        total: aRows.length,
        matchCols: data.meta.aMatchCols,
        calcFields: data.meta.aCalcFields || aData.value.calcFields,
        loading: false,
        gridApi: aData.value.gridApi
      };
      aSelectedKeys.value = [];
    } else {
      const bRows = data.bData.rows;
      bRows.forEach((row, index) => { row['序号'] = index + 1; });
      bData.value = {
        functionCode: '',
        moduleName: data.meta.bModule,
        config: data.meta.bConfig,
        columns: data.meta.bColumns,
        rows: bRows,
        total: bRows.length,
        matchCols: data.meta.bMatchCols,
        calcFields: data.meta.bCalcFields || bData.value.calcFields,
        loading: false,
        gridApi: bData.value.gridApi
      };
      bSelectedKeys.value = [];
    }
  }

  return {
    aData,
    bData,
    displayFilter,
    aSelectedKeys,
    bSelectedKeys,
    isSaving,
    matchConditions,
    selectedConditionIndices,
    loadData,
    setAGridApi,
    setBGridApi,
    updateASelected,
    updateBSelected,
    updateSelectedConditions,
    buildRelation,
    revokeRelation,
    refreshData,
    refreshSide,
    get aMatchedKeys() { return aMatchedKeysComputed.value; },
    get bMatchedKeys() { return bMatchedKeysComputed.value; },
    get aCandidateKeys() { return aCandidateKeysComputed.value; },
    get bCandidateKeys() { return bCandidateKeysComputed.value; }
  };
}
