<script setup lang="ts">
import { ref, computed, watch, onMounted, nextTick } from 'vue';
import type { GridApi, GridReadyEvent, ColDef, RowClassParams } from 'ag-grid-community';
import { AllCommunityModule, ModuleRegistry, themeAlpine } from 'ag-grid-community';
import { AG_GRID_LOCALE_CN } from '@ag-grid-community/locale';
import { AgGridVue } from 'ag-grid-vue3';

import { NButton, NInput, NCheckbox, NCheckboxGroup, NSpace, NDatePicker, NSpin, NModal, NSelect } from 'naive-ui';
import { useThemeStore } from '@/store/modules/theme';
import { WORKBENCH_CONFIG } from '@/config/workbench';
import WorkbenchSelectAllHeader from '@/views/menu-bridge/modules/components/WorkbenchSelectAllHeader.vue';
import type { MatchModuleData } from '@/hooks/business/use-match-store';

ModuleRegistry.registerModules([AllCommunityModule]);

const themeStore = useThemeStore();
const isDarkMode = computed(() => themeStore.darkMode);

const lightGridTheme = themeAlpine.withParams(WORKBENCH_CONFIG.GRID_THEME.LIGHT);
const darkGridTheme = themeAlpine.withParams(WORKBENCH_CONFIG.GRID_THEME.DARK);

const gridTheme = computed(() => (isDarkMode.value ? darkGridTheme : lightGridTheme));

interface Props {
  side: 'A' | 'B';
  data: MatchModuleData;
  onlyUnmatched: boolean;
  selectedKeys: string[];
  matchedKeys: Map<string, string[]>;
  otherMatchedKeys: Map<string, string[]>;
  quickKeyword?: string;
}

const props = defineProps<Props>();

const emit = defineEmits<{
  'update:selected': [keys: string[]];
  'set-grid-api': [api: GridApi<any>];
}>();

const gridApi = ref<GridApi<any> | null>(null);
const loading = computed(() => props.data.loading);

const fieldSelectorVisible = ref(false);
const pinColumnVisible = ref(false);
const selectedVisibleFields = ref<string[]>([]);
const pinColumnFields = ref<string[]>([]);
const pinDirection = ref<'left' | 'right' | null>('left');

const fieldOptions = computed(() => {
  if (!props.data.columns) return [];
  return props.data.columns
    .filter(col => col.field)
    .map(col => ({ label: col.title || col.field, value: col.field }));
});

const pinDirectionOptions = [
  { label: '左侧固定', value: 'left' },
  { label: '右侧固定', value: 'right' }
];

const keyField = computed(() => {
  if (props.data.matchCols.key) return props.data.matchCols.key;
  if (props.data.columns && props.data.columns.length > 0) {
    return props.data.columns[0].field || '';
  }
  return '';
});

const defaultColDef = {
  width: 120,
  minWidth: 0,
  resizable: true,
  filter: true,
  filterParams: {
    maxNumConditions: 5
  }
};

const displayedRows = computed(() => {
  let rows = props.data.rows;

  if (props.onlyUnmatched) {
    const kf = keyField.value;
    rows = rows.filter(row => {
      const key = String(row[kf] ?? '');
      const targets = props.matchedKeys.get(key) || [];
      return targets.length === 0;
    });
  }

  if (props.quickKeyword) {
    const kw = props.quickKeyword.toLowerCase();
    rows = rows.filter(row => {
      return Object.values(row).some(v => 
        String(v ?? '').toLowerCase().includes(kw)
      );
    });
  }

  return rows;
});

const columnDefs = computed<ColDef[]>(() => {
  if (!props.data.columns || props.data.columns.length === 0) return [];

  const cols: ColDef[] = [];

  for (const col of props.data.columns) {
    const isGuidColumn = 
      String(col.field || '').trim().toUpperCase() === 'GUID' ||
      String(col.title || '').trim().toUpperCase() === 'GUID';

    const colDef: ColDef = {
      headerName: col.title || col.field,
      field: col.field,
      width: col.width || 120,
      sortable: col.sortable !== false,
      editable: false,
      resizable: true,
      hide: isGuidColumn
    };

    if (col.type === '数值') {
      colDef.type = 'numericColumn';
    }

    if (col.field === props.data.matchCols.key) {
      colDef.pinned = 'left';
    }

    if (col.field === props.data.matchCols.target) {
      colDef.pinned = 'right';
    }

    cols.push(colDef);
  }

  cols.push({
    headerName: '匹配状态',
    field: '__matchStatus',
    width: 100,
    pinned: 'right',
    sortable: false,
    filter: false,
    cellRenderer: (params: any) => {
      const kf = keyField.value;
      const key = String(params.data[kf] ?? '');
      const targets = props.matchedKeys.get(key) || [];
      if (targets.length > 0) {
        return '<span style="color:#52c41a;">● 已匹配</span>';
      }
      return '<span style="color:#bfbfbf;">○ 未匹配</span>';
    }
  });

  return cols;
});

function onGridReady(event: GridReadyEvent) {
  gridApi.value = event.api;
  emit('set-grid-api', event.api);
}

function onSelectionChanged() {
  if (!gridApi.value) return;
  const selectedRows = gridApi.value.getSelectedRows();
  const kf = keyField.value;
  const keys = selectedRows.map(row => String(row[kf] ?? ''));
  emit('update:selected', keys);
}

function getRowId(params: any) {
  const kf = keyField.value;
  return String(params.data[kf] ?? '');
}

function getRowClass(params: RowClassParams) {
  const kf = keyField.value;
  const key = String(params.data[kf] ?? '');
  
  if (props.selectedKeys.includes(key)) {
    return 'match-selected-row';
  }

  const otherKeys = props.otherMatchedKeys.get(key);
  if (otherKeys && otherKeys.length > 0) {
    return 'match-highlight-row';
  }

  const targets = props.matchedKeys.get(key) || [];
  if (targets.length > 0) {
    return 'match-matched-row';
  }

  return '';
}

function scrollToMatched() {
  if (!gridApi.value || props.selectedKeys.length === 0) return;
  
  const firstKey = props.selectedKeys[0];
  const node = gridApi.value.getRowNode(firstKey);
  if (node) {
    gridApi.value.ensureNodeVisible(node);
  }
}

function clearSelection() {
  emit('update:selected', []);
  if (gridApi.value) {
    gridApi.value.deselectAll();
  }
}

function clearFilter() {
  if (gridApi.value) {
    gridApi.value.setFilterModel(null);
  }
}

function clearSort() {
  if (gridApi.value) {
    gridApi.value.applyColumnState({ defaultState: { sort: null } });
  }
}

function openFieldSelector() {
  if (!gridApi.value) return;
  const columns = gridApi.value.getColumns() || [];
  selectedVisibleFields.value = columns
    .filter(col => col.isVisible())
    .map(col => col.getColId())
    .filter(id => id !== '__matchStatus');
  fieldSelectorVisible.value = true;
}

function applyFieldSelector() {
  if (!gridApi.value) return;
  const allFields = gridApi.value.getColumns()?.map(col => col.getColId()) || [];
  const visibleSet = new Set(selectedVisibleFields.value);
  allFields.forEach(field => {
    if (field === '__matchStatus') return;
    gridApi.value?.setColumnsVisible([field], visibleSet.has(field));
  });
}

function openPinColumnSelector() {
  if (!gridApi.value) return;
  const columns = gridApi.value.getColumns() || [];
  const pinnedCols = columns.filter(col => col.getPinned());
  pinColumnFields.value = pinnedCols.map(col => col.getColId());
  pinDirection.value = (pinnedCols[0]?.getPinned() as 'left' | 'right' | null) || 'left';
  pinColumnVisible.value = true;
}

function applyPinColumnState() {
  if (!gridApi.value) return;
  gridApi.value.applyColumnState({ defaultState: { pinned: null } });
  if (pinDirection.value && pinColumnFields.value.length > 0) {
    gridApi.value.applyColumnState({
      state: pinColumnFields.value.map(field => ({ colId: field, pinned: pinDirection.value }))
    });
  }
}

defineExpose({
  scrollToMatched,
  clearSelection,
  clearFilter,
  clearSort,
  openFieldSelector,
  openPinColumnSelector
});

watch(() => props.selectedKeys, () => {
  if (!gridApi.value) return;
  nextTick(() => {
    gridApi.value!.refreshCells();
  });
});

watch(() => props.data.rows, () => {
  if (!gridApi.value) return;
  nextTick(() => {
    gridApi.value!.refreshCells();
  });
}, { deep: true });

watch([() => props.onlyUnmatched, () => props.quickKeyword], () => {
  if (!gridApi.value) return;
  nextTick(() => {
    gridApi.value!.refreshCells();
  });
});
</script>

<template>
  <div class="match-table-panel" :class="{ 'system-dark': isDarkMode }">
    <div class="match-table-container">
      <div v-if="loading" class="match-table-loading">
        <NSpin size="large" />
      </div>
      <AgGridVue
        :theme="gridTheme"
        class="match-grid"
        :column-defs="columnDefs"
        :row-data="displayedRows"
        :default-col-def="defaultColDef"
        :row-selection="{ mode: 'multiRow', checkboxes: true, headerCheckbox: false, selectAll: 'filtered' }"
        :selection-column-def="{
          width: 37,
          minWidth: 37,
          resizable: false,
          headerClass: 'selection-header-left',
          headerComponent: WorkbenchSelectAllHeader
        }"
        :get-row-id="getRowId"
        :get-row-class="getRowClass"
        :locale-text="AG_GRID_LOCALE_CN"
        :row-height="35"
        :header-height="40"
        :animate-rows="false"
        overlay-no-rows-template="<span style='padding: 20px; display: block; text-align: center;'>无数据</span>"
        overlay-loading-template="<span style='padding: 20px; display: block; text-align: center;'>正在加载数据，请稍候...</span>"
        @grid-ready="onGridReady"
        @selection-changed="onSelectionChanged"
      />
    </div>

    <NModal
      v-model:show="fieldSelectorVisible"
      title="字段选择"
      preset="card"
      :style="{ width: '400px' }"
      :mask-closable="true"
    >
      <NCheckboxGroup v-model:value="selectedVisibleFields" @update:value="applyFieldSelector">
        <NSpace vertical>
          <NCheckbox
            v-for="option in fieldOptions"
            :key="option.value"
            :value="option.value"
            :label="option.label"
          />
        </NSpace>
      </NCheckboxGroup>
    </NModal>

    <NModal
      v-model:show="pinColumnVisible"
      title="固定列"
      preset="card"
      :style="{ width: '400px' }"
      :mask-closable="true"
    >
      <NSpace vertical>
        <NSelect
          v-model:value="pinDirection"
          :options="pinDirectionOptions"
          placeholder="固定方向"
          @update:value="applyPinColumnState"
        />
        <NCheckboxGroup v-model:value="pinColumnFields" @update:value="applyPinColumnState">
          <NSpace vertical>
            <NCheckbox
              v-for="option in fieldOptions"
              :key="option.value"
              :value="option.value"
              :label="option.label"
            />
          </NSpace>
        </NCheckboxGroup>
      </NSpace>
    </NModal>
  </div>
</template>

<style lang="scss" scoped>
@use '@/styles/scss/ag-grid-shared' as *;

.match-table-panel {
  display: flex;
  flex-direction: column;
  height: 100%;
  width: 100%;
}

.match-table-container {
  flex: 1;
  overflow: hidden;
  min-height: 0;
  position: relative;
  width: 100%;
}

.match-table-loading {
  position: absolute;
  inset: 0;
  z-index: 100;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  background: rgba(255, 255, 255, 0.95);

  .system-dark & {
    background: rgba(16, 22, 29, 0.95);
  }
}

.match-grid {
  --wb-grid-surface: transparent;
  --wb-grid-text: #1f2937;
  --wb-grid-border: #d9d9d9;
  --wb-grid-input-bg: #ffffff;
  --wb-grid-input-border: #d9d9d9;
  width: 100%;
  height: 100%;

  .system-dark & {
    --wb-grid-surface: rgb(var(--container-bg-color));
    --wb-grid-text: rgb(var(--base-text-color));
    --wb-grid-border: rgb(var(--container-bg-color) / 0.4);
    --wb-grid-input-bg: rgb(var(--container-bg-color) / 0.6);
    --wb-grid-input-border: rgb(var(--container-bg-color) / 0.5);
    --ag-background-color: rgb(var(--container-bg-color));
    --ag-header-background-color: rgb(var(--container-bg-color));
    --ag-data-background-color: rgb(var(--container-bg-color));
    --ag-control-panel-background-color: rgb(var(--container-bg-color));
    --ag-panel-background-color: rgb(var(--container-bg-color));
    --ag-subheader-background-color: rgb(var(--container-bg-color));
    --ag-odd-row-background-color: rgb(var(--container-bg-color));
    --ag-foreground-color: var(--wb-grid-text);
    --ag-secondary-foreground-color: var(--wb-grid-text);
    --ag-border-color: var(--wb-grid-border);
    --ag-row-border-color: var(--wb-grid-border);
    --ag-input-background-color: var(--wb-grid-input-bg);
    --ag-input-border-color: var(--wb-grid-input-border);
  }
}

// ============ ag-grid 共享样式（来自 _ag-grid-shared.scss）============
@include ag-grid-base-layout('match-grid');
@include ag-grid-cell-borders('match-grid');
@include ag-grid-cell-focus('match-grid');
@include ag-grid-selection-column('match-grid');
@include ag-grid-checkbox-theme('match-grid');
@include ag-grid-checkbox-dark('match-grid');
@include ag-grid-base-dark('match-grid');

/* Row highlight classes (component-specific) */
:deep(.match-selected-row) {
  background-color: #e6f7ff !important;
}

:deep(.match-highlight-row) {
  background-color: #fff7e6 !important;
}

:deep(.match-matched-row) {
  background-color: #f6ffed !important;
}

/* Dark mode row highlight (component-specific) */
.system-dark {
  :deep(.match-selected-row) {
    background-color: rgba(99, 179, 255, 0.2) !important;
  }

  :deep(.match-highlight-row) {
    background-color: rgba(255, 193, 7, 0.2) !important;
  }

  :deep(.match-matched-row) {
    background-color: rgba(76, 175, 80, 0.2) !important;
  }
}
</style>
