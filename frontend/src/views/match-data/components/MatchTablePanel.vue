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
$wb-checkbox-check-icon: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Cpath fill='%23ffffff' d='M50.42,16.76L22.34,39.45l-8.1-11.46c-1.12-1.58-3.3-1.96-4.88-0.84c-1.58,1.12-1.95,3.3-0.84,4.88l10.26,14.51c0.56,0.79,1.42,1.31,2.38,1.45c0.16,0.02,0.32,0.03,0.48,0.03c0.8,0,1.57-0.27,2.2-0.78l30.99-25.03c1.5-1.21,1.74-3.42,0.52-4.92C54.13,15.78,51.93,15.55,50.42,16.76z'/%3E%3C/svg%3E");
$wb-checkbox-line-icon: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Cpath fill='%23ffffff' d='M80.2,55.5H21.4c-2.8,0-5.1-2.5-5.1-5.5l0,0c0-3,2.3-5.5,5.1-5.5h58.7c2.8,0,5.1,2.5,5.1,5.5l0,0C85.2,53.1,82.9,55.5,80.2,55.5z'/%3E%3C/svg%3E");

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

:deep(.match-grid .ag-root-wrapper),
:deep(.match-grid .ag-root),
:deep(.match-grid .ag-header),
:deep(.match-grid .ag-body),
:deep(.match-grid .ag-floating-top),
:deep(.match-grid .ag-floating-bottom),
:deep(.match-grid .ag-row),
:deep(.match-grid .ag-row-odd),
:deep(.match-grid .ag-row-even),
:deep(.match-grid .ag-header-row) {
  background-color: var(--wb-grid-surface) !important;
}

:deep(.match-grid .ag-header-cell),
:deep(.match-grid .ag-cell),
:deep(.match-grid .ag-header-cell-text),
:deep(.match-grid .ag-cell-value) {
  color: var(--wb-grid-text);
}

:deep(.match-grid .ag-cell-wrapper),
:deep(.match-grid .ag-header-cell-comp-wrapper) {
  height: 100%;
  align-items: center;
}

:deep(.match-grid .ag-cell-value),
:deep(.match-grid .ag-header-cell-text) {
  display: inline-flex;
  align-items: center;
}

:deep(.match-grid .ag-header-cell),
:deep(.match-grid .ag-cell) {
  border-right: 1px dotted #d9d9d9 !important;
}

:deep(.match-grid .ag-row),
:deep(.match-grid .ag-header-row) {
  border-bottom: 1px dotted #d9d9d9 !important;
}

:deep(.match-grid .ag-row-hover .ag-cell) {
  background-color: rgba(24, 144, 255, 0.04) !important;
}

/* Selection column alignment */
:deep(.match-grid .ag-selection-checkbox),
:deep(.match-grid .ag-checkbox-input-wrapper) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

:deep(.match-grid .ag-selection-checkbox),
:deep(.match-grid .ag-header-select-all) {
  width: 100%;
  height: 100%;
}

:deep(.match-grid .ag-header-select-all) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding-left: 0;
}

:deep(.match-grid .selection-header-left .ag-header-cell-comp-wrapper) {
  width: 100%;
  justify-content: center !important;
  padding-left: 0 !important;
}

:deep(.match-grid .selection-header-left .ag-header-select-all) {
  margin: 0 auto;
  width: auto !important;
}

:deep(.match-grid .selection-header-left .ag-header-select-all .ag-selection-checkbox) {
  justify-content: center !important;
  width: auto !important;
  padding-left: 0 !important;
}

:deep(.match-grid .selection-header-left .ag-header-cell-label) {
  width: 100% !important;
  justify-content: center !important;
  padding-left: 0 !important;
  gap: 0 !important;
}

:deep(.match-grid .selection-header-left .ag-header-cell-label .ag-checkbox-input-wrapper) {
  margin-left: 0 !important;
  margin-right: 0 !important;
}

:deep(.match-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn']) {
  position: relative;
  padding-left: 0 !important;
  padding-right: 0 !important;
}

:deep(.match-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-header-cell-comp-wrapper) {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
}

:deep(.match-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-header-cell-label) {
  justify-content: center !important;
  padding-left: 0 !important;
}

:deep(.match-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-header-select-all) {
  position: absolute !important;
  left: 50% !important;
  top: 50% !important;
  transform: translate(-50%, -50%) !important;
  width: 16px !important;
  height: 16px !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
}

:deep(.match-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-selection-checkbox),
:deep(.match-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-checkbox-input-wrapper) {
  margin: 0 !important;
  padding: 0 !important;
}

:deep(.match-grid .ag-cell[col-id='ag-Grid-SelectionColumn']) {
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  padding-left: 0 !important;
  padding-right: 0 !important;
}

:deep(.match-grid .ag-cell[col-id='ag-Grid-SelectionColumn'] .ag-cell-wrapper) {
  display: flex;
  align-items: center;
  justify-content: center;
}

:deep(.match-grid .ag-cell[col-id='ag-Grid-SelectionColumn'] .ag-selection-checkbox),
:deep(.match-grid .ag-cell[col-id='ag-Grid-SelectionColumn'] .ag-checkbox-input-wrapper) {
  position: relative;
  width: 16px;
  height: 16px;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
}

:deep(.match-grid .ag-cell[col-id='ag-Grid-SelectionColumn'] .ag-cell-wrapper .ag-selection-checkbox) {
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  margin-left: auto !important;
  margin-right: auto !important;
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
}

/* Align checkbox visual with Naive UI NTree style */
:deep(.match-grid .ag-checkbox-input-wrapper) {
  position: relative;
  width: 16px;
  height: 16px;
  border: 1px solid #95a6b8;
  border-radius: 2px;
  background-color: #ffffff;
  line-height: 16px;
}

:deep(.match-grid .ag-checkbox-input-wrapper::before) {
  display: none !important;
}

:deep(.match-grid .ag-checkbox-input-wrapper::after) {
  content: '';
  position: absolute;
  left: 50%;
  top: 50%;
  width: 0;
  height: 0;
  border: 0;
  transform: translate(-50%, -50%);
}

:deep(.match-grid .ag-checkbox-input-wrapper.ag-checked) {
  border-color: #2a90e8;
  background-color: #2a90e8;
}

:deep(.match-grid .ag-checkbox-input-wrapper.ag-checked::after) {
  content: '';
  width: 12px;
  height: 12px;
  background-image: $wb-checkbox-check-icon;
  background-size: contain;
  background-repeat: no-repeat;
  background-position: center;
}

:deep(.match-grid .ag-checkbox-input-wrapper.ag-indeterminate) {
  border-color: #2a90e8;
  background-color: #2a90e8;
}

:deep(.match-grid .ag-checkbox-input-wrapper.ag-indeterminate::after) {
  content: '';
  width: 10px;
  height: 10px;
  background-image: $wb-checkbox-line-icon;
  background-size: contain;
  background-repeat: no-repeat;
  background-position: center;
}

/* Cell focus */
:deep(.match-grid .ag-cell.ag-cell-focus),
:deep(.match-grid .ag-cell.ag-cell-range-selected) {
  border: none !important;
  outline: 2px solid #40a9ff !important;
  outline-offset: -2px !important;
  box-shadow: none !important;
  z-index: 2 !important;
}

/* Row highlight classes */
:deep(.match-selected-row) {
  background-color: #e6f7ff !important;
}

:deep(.match-highlight-row) {
  background-color: #fff7e6 !important;
}

:deep(.match-matched-row) {
  background-color: #f6ffed !important;
}

/* Dark mode */
.system-dark {
  :deep(.match-grid .ag-header-cell),
  :deep(.match-grid .ag-cell) {
    border-right-color: rgba(255, 255, 255, 0.12) !important;
  }

  :deep(.match-grid .ag-row),
  :deep(.match-grid .ag-header-row) {
    border-bottom-color: rgba(255, 255, 255, 0.12) !important;
  }

  :deep(.match-grid .ag-checkbox-input-wrapper) {
    border-color: #6f859b;
    background-color: var(--wb-grid-surface);
  }

  :deep(.match-grid .ag-checkbox-input-wrapper.ag-checked),
  :deep(.match-grid .ag-checkbox-input-wrapper.ag-indeterminate) {
    border-color: #4ea4f3;
    background-color: #2f7fc5;
  }

  :deep(.match-grid .ag-checkbox-input-wrapper.ag-checked::after) {
    content: '';
    width: 12px;
    height: 12px;
    background-image: $wb-checkbox-check-icon;
    background-size: contain;
    background-repeat: no-repeat;
    background-position: center;
  }

  :deep(.match-grid .ag-checkbox-input-wrapper.ag-indeterminate::after) {
    content: '';
    width: 10px;
    height: 10px;
    background-image: $wb-checkbox-line-icon;
    background-size: contain;
    background-repeat: no-repeat;
    background-position: center;
    background-color: transparent;
    box-shadow: none;
  }

  :deep(.match-grid .ag-row-hover::before) {
    background-color: rgba(122, 167, 214, 0.18) !important;
  }

  :deep(.match-grid .ag-header-cell .ag-icon),
  :deep(.match-grid .ag-header-cell .ag-header-icon),
  :deep(.match-grid .ag-header-cell-menu-button),
  :deep(.match-grid .ag-header-cell-filter-button) {
    color: var(--wb-grid-text) !important;
    opacity: 0.95;
  }

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
