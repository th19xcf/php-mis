import { ref, computed } from 'vue';
import type { Ref } from 'vue';
import type { GridApi } from 'ag-grid-community';

import { fetchMatchPage, buildMatchRelation, revokeMatchRelation, type MatchConfig, type MatchColumn } from '@/service/api/match';

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

  loadData: (
    functionCode: string,
    aModule: string, bModule: string,
    aConfig: MatchConfig, bConfig: MatchConfig,
    aColumns: MatchColumn[], bColumns: MatchColumn[],
    aMatchCols: MatchColumnRole, bMatchCols: MatchColumnRole,
    aRows: any[], bRows: any[]
  ) => void;
  setAGridApi: (api: GridApi<any>) => void;
  setBGridApi: (api: GridApi<any>) => void;
  updateASelected: (keys: string[]) => void;
  updateBSelected: (keys: string[]) => void;
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

  const aMatchedKeysComputed = computed(() => buildMatchMapping(aData.value.rows, aData.value.matchCols));
  const bMatchedKeysComputed = computed(() => buildMatchMapping(bData.value.rows, bData.value.matchCols));

  function loadData(
    fc: string,
    aModule: string, bModule: string,
    aConfig: MatchConfig, bConfig: MatchConfig,
    aColumns: MatchColumn[], bColumns: MatchColumn[],
    aMatchCols: MatchColumnRole, bMatchCols: MatchColumnRole,
    aRows: any[], bRows: any[]
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
      data.bData.rows
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
    loadData,
    setAGridApi,
    setBGridApi,
    updateASelected,
    updateBSelected,
    buildRelation,
    revokeRelation,
    refreshData,
    refreshSide,
    get aMatchedKeys() { return aMatchedKeysComputed.value; },
    get bMatchedKeys() { return bMatchedKeysComputed.value; }
  };
}
