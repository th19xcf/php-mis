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
  loading: boolean;
  gridApi: GridApi<any> | null;
}

export interface MatchStore {
  aData: Ref<MatchModuleData>;
  bData: Ref<MatchModuleData>;
  onlyUnmatched: Ref<boolean>;
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
    matchConditions?: MatchCondition[]
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

export function useMatchStore(): MatchStore {
  const aData = ref<MatchModuleData>({
    functionCode: '',
    moduleName: '',
    config: null,
    columns: [],
    rows: [],
    total: 0,
    matchCols: { key: '', label: '', amount: '', target: '' },
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
    loading: false,
    gridApi: null
  });

  const onlyUnmatched = ref(true);
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
    console.log('[MATCH] bCandidateKeys - aSelectedKeys:', aSelectedKeys.value);
    console.log('[MATCH] bCandidateKeys - selectedConditionIndices:', selectedConditionIndices.value);
    console.log('[MATCH] bCandidateKeys - matchConditions:', matchConditions.value);
    if (aSelectedKeys.value.length === 0 || selectedConditionIndices.value.length === 0) {
      console.log('[MATCH] bCandidateKeys - 条件不满足，返回空');
      return new Set<string>();
    }
    const conditions = selectedConditionIndices.value
      .map(i => matchConditions.value[i])
      .filter(Boolean);
    console.log('[MATCH] bCandidateKeys - 选中条件:', conditions);
    if (conditions.length === 0) {
      console.log('[MATCH] bCandidateKeys - 条件解析为空');
      return new Set<string>();
    }

    let aKeyField = aData.value.matchCols.key;
    if (!aKeyField && aData.value.columns.length > 0) {
      aKeyField = aData.value.columns[0].field || '';
    }
    console.log('[MATCH] bCandidateKeys - aKeyField:', aKeyField);
    console.log('[MATCH] bCandidateKeys - aData.rows:', aData.value.rows.length);
    const aRows = aData.value.rows.filter(row =>
      aSelectedKeys.value.includes(String(row[aKeyField] ?? ''))
    );
    console.log('[MATCH] bCandidateKeys - 筛选后的aRows:', aRows.length);

    let bKey = bData.value.matchCols.key;
    if (!bKey && bData.value.columns.length > 0) {
      bKey = bData.value.columns[0].field || '';
    }
    console.log('[MATCH] bCandidateKeys - bKey:', bKey, 'bData.columns[0].field:', bData.value.columns[0]?.field);
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
          console.log('[MATCH] 条件:', cond.text, 'A字段:', aField, 'A值:', aValue, 'B字段:', bField, 'B值:', bValue, '相等:', String(aValue ?? '') === String(bValue ?? ''));
          return String(aValue ?? '') === String(bValue ?? '');
        });
        if (allMatch) {
          const candidateKey = String(bRow[bKey] ?? '');
          console.log('[MATCH] 匹配成功，bKey:', bKey, 'bRow[bKey]:', candidateKey, 'bRow keys:', Object.keys(bRow).slice(0, 5));
          result.add(candidateKey);
          break;
        }
      }
    }
    console.log('[MATCH] bCandidateKeys:', Array.from(result));
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
    if (!bKeyField && bData.value.columns.length > 0) {
      bKeyField = bData.value.columns[0].field || '';
    }
    const bRows = bData.value.rows.filter(row =>
      bSelectedKeys.value.includes(String(row[bKeyField] ?? ''))
    );

    let aKey = aData.value.matchCols.key;
    if (!aKey && aData.value.columns.length > 0) {
      aKey = aData.value.columns[0].field || '';
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
          console.log('[MATCH] 条件:', cond.text, 'A字段:', aField, 'A值:', aValue, 'B字段:', bField, 'B值:', bValue, '相等:', String(aValue ?? '') === String(bValue ?? ''));
          return String(aValue ?? '') === String(bValue ?? '');
        });
        if (allMatch) {
          result.add(String(aRow[aKey] ?? ''));
          break;
        }
      }
    }
    console.log('[MATCH] aCandidateKeys:', Array.from(result));
    return result;
  });

  function loadData(
    fc: string,
    aModule: string, bModule: string,
    aConfig: MatchConfig, bConfig: MatchConfig,
    aColumns: MatchColumn[], bColumns: MatchColumn[],
    aMatchCols: MatchColumnRole, bMatchCols: MatchColumnRole,
    aRows: any[], bRows: any[],
    conditions?: MatchCondition[]
  ) {
    functionCode.value = fc;

    aData.value = {
      functionCode: '',
      moduleName: aModule,
      config: aConfig,
      columns: aColumns,
      rows: aRows,
      total: aRows.length,
      matchCols: aMatchCols,
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
      aData.value = {
        functionCode: '',
        moduleName: data.meta.aModule,
        config: data.meta.aConfig,
        columns: data.meta.aColumns,
        rows: data.aData.rows,
        total: data.aData.rows.length,
        matchCols: data.meta.aMatchCols,
        loading: false,
        gridApi: aData.value.gridApi
      };
      aSelectedKeys.value = [];
    } else {
      bData.value = {
        functionCode: '',
        moduleName: data.meta.bModule,
        config: data.meta.bConfig,
        columns: data.meta.bColumns,
        rows: data.bData.rows,
        total: data.bData.rows.length,
        matchCols: data.meta.bMatchCols,
        loading: false,
        gridApi: bData.value.gridApi
      };
      bSelectedKeys.value = [];
    }
  }

  return {
    aData,
    bData,
    onlyUnmatched,
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
