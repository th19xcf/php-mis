<script setup lang="ts">
import { computed, ref, h } from 'vue';
import { useRouter } from 'vue-router';

import { AG_GRID_LOCALE_CN } from '@ag-grid-community/locale';
import {
  AllCommunityModule,
  ModuleRegistry,
  themeAlpine,
  type ColDef,
  type GridApi,
  type GridReadyEvent
} from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';
import { NButton, NRadio, NRadioGroup } from 'naive-ui';

import { fetchWorkbenchPage, fetchWorkbenchQuery, fetchWorkbenchDrill } from '@/service/api/workbench';
import { useThemeStore } from '@/store/modules/theme';

const router = useRouter();

ModuleRegistry.registerModules([AllCommunityModule]);

interface MenuBridgeMeta {
  functionCode?: string;
  menu1?: string;
  menu2?: string;
  module?: string;
  params?: string;
}

type ConditionOperator = 'contains' | 'equals' | 'startsWith';
type QueryFilter = NonNullable<Api.Workbench.QueryPayload['filters']>[number];

function isGuidColumn(field: string, label: string) {
  return field.trim().toUpperCase() === 'GUID' || label.trim().toUpperCase() === 'GUID';
}

const props = defineProps<{
  meta: MenuBridgeMeta;
  nativeOnly?: boolean;
  dynamicLike?: boolean;
}>();

const lightGridTheme = themeAlpine.withParams({
  browserColorScheme: 'light',
  rowBorder: { style: 'dotted', width: 1, color: '#c1ccc7' },
  columnBorder: { style: 'dotted', width: 1, color: '#c1ccc7' }
});

const darkGridTheme = themeAlpine.withParams({
  browserColorScheme: 'dark',
  rowBorder: { style: 'dotted', width: 1, color: '#4b5965' },
  columnBorder: { style: 'dotted', width: 1, color: '#4b5965' }
});

const themeStore = useThemeStore();
const isDarkMode = computed(() => themeStore.darkMode);
const activeGridTheme = computed(() => (isDarkMode.value ? darkGridTheme : lightGridTheme));

const loading = ref(false);
const pageMeta = ref<Api.Workbench.PageMeta | null>(null);
const serverRows = ref<Api.Workbench.QueryRecord[]>([]);
const total = ref(0);
const page = ref(1);
const PAGE_SIZE_OPTIONS = [500, 1000, 2000] as const;
const paginationPageSizeSelector = [...PAGE_SIZE_OPTIONS];
const pageSize = ref<number>(PAGE_SIZE_OPTIONS[0]);

const quickKeyword = ref('');
const conditionVisible = ref(false);
const fieldColumnVisible = ref(false);
const visibleFieldColumns = ref<string[]>([]);
const pinColumnVisible = ref(false);
const pinTargetFields = ref<string[]>([]);
const selectedField = ref('');
const selectedOperator = ref<ConditionOperator>('contains');
const selectedValue = ref('');
const useLegacyTabHint = ref(false);
const gridApi = ref<GridApi<Api.Workbench.QueryRecord> | null>(null);

function normalizePageSize(size?: number) {
  return PAGE_SIZE_OPTIONS.includes(size as (typeof PAGE_SIZE_OPTIONS)[number]) ? size! : PAGE_SIZE_OPTIONS[0];
}

const defaultColDef = {
  width: 120,
  minWidth: 0,
  // 不设置 maxWidth，允许自适应到任意宽度
  resizable: true,
  editable: true,
  filter: true,
  filterParams: {
    maxNumConditions: 5
  }
} as const;

const gridColumns = computed<ColDef<Api.Workbench.QueryRecord>[]>(() => {
  return (pageMeta.value?.columns || []).map(column => {
    const headerClasses: string[] = [];

    if (column.editable) {
      // Keep legacy visual cue for editable columns.
      headerClasses.push('editable-column');
    }

    const definition: ColDef<Api.Workbench.QueryRecord> = {
      field: column.field,
      headerName: column.title,
      // 不使用后端返回的宽度，而是由前端自动调整
      hide: column.hidden,
      sortable: column.sortable,
      filter: true,
      resizable: true,
      headerClass: headerClasses
    };

    if (column.type === '数值') {
      definition.type = 'numericColumn';
    }

    return definition;
  });
});

const filterableFields = computed(() => {
  return (pageMeta.value?.conditions || []).filter(item => item.filterable).map(item => item.fieldKey);
});

const pinColumnOptions = computed(() => {
  const columns = gridApi.value?.getColumns() ?? [];

  if (columns.length > 0) {
    return columns
      .map(column => {
        const colDef = column.getColDef();
        const field = String(colDef.field || '');
        const headerName = String(colDef.headerName || field);

        return {
          label: headerName,
          value: field
        };
      })
      .filter(item => item.value !== '' && !isGuidColumn(item.value, item.label));
  }

  return (pageMeta.value?.columns || [])
    .filter(column => column.field !== '')
    .map(column => ({ label: column.title || column.field, value: column.field }))
    .filter(item => !isGuidColumn(String(item.value), String(item.label)));
});

const fieldColumnOptions = computed(() => {
  const columns = gridApi.value?.getColumns() ?? [];

  if (columns.length > 0) {
    return columns
      .map(column => {
        const colDef = column.getColDef();
        const field = String(colDef.field || '');
        const headerName = String(colDef.headerName || field);

        return {
          label: headerName,
          value: field
        };
      })
      .filter(
        item => item.value !== '' && item.value !== 'ag-Grid-ControlsColumn' && !isGuidColumn(item.value, item.label)
      );
  }

  return (pageMeta.value?.columns || [])
    .filter(column => column.field !== '')
    .map(column => ({ label: column.title || column.field, value: column.field }))
    .filter(item => !isGuidColumn(String(item.value), String(item.label)));
});

const filteredRows = computed(() => {
  let rows = [...serverRows.value];
  const keyword = quickKeyword.value.trim().toLowerCase();

  if (keyword) {
    rows = rows.filter(row =>
      Object.values(row).some(value =>
        String(value ?? '')
          .toLowerCase()
          .includes(keyword)
      )
    );
  }

  return rows;
});

async function loadPage() {
  const functionCode = String(props.meta.functionCode || '').trim();
  if (!functionCode) {
    pageMeta.value = null;
    serverRows.value = [];
    total.value = 0;
    return;
  }

  loading.value = true;
  const { data, error } = await fetchWorkbenchPage(functionCode);

  if (error) {
    loading.value = false;
    return;
  }

  pageMeta.value = data.meta;

  // 解析钻取参数
  const drillFilters: QueryFilter[] = [];
  let drillConditionSql = '';
  const drillParamsStr = String(props.meta.params || '').trim();
  if (drillParamsStr && drillParamsStr !== '') {
    try {
      const drillParams = JSON.parse(drillParamsStr);
      console.log('解析钻取参数:', drillParams);

      // 获取原始钻取条件 SQL（如：财务月份=`$本年度月份` or 工作预算月份=`$本年度月份`）
      const rawDrillCondition = drillParams['钻取条件'] || '';
      if (rawDrillCondition) {
        // 替换 SQL 变量为实际值
        drillConditionSql = rawDrillCondition;
        for (const [key, value] of Object.entries(drillParams)) {
          if (key === '钻取字段' || key === '钻取条件' || key === '字段选择') continue;
          const variable = `$${key}`;
          const valueStr = typeof value === 'string' ? value : JSON.stringify(value);
          drillConditionSql = drillConditionSql.replace(
            new RegExp(variable.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'),
            valueStr
          );
        }
        // 将反引号替换为单引号（SQL 语法）
        drillConditionSql = drillConditionSql.replace(/`/g, "'");
        console.log('钻取条件 SQL:', drillConditionSql);
      }

      // 从钻取参数中提取字段和值作为过滤条件（备用逻辑）
      const drillFieldsStr = drillParams['钻取字段'] || '';
      const nlArr = drillFieldsStr.split(';').filter((f: string) => f.trim());

      for (const field of nlArr) {
        const trimmedField = field.trim();
        if (trimmedField && drillParams[trimmedField] !== undefined && drillParams[trimmedField] !== '') {
          drillFilters.push({
            fieldKey: trimmedField,
            operator: 'equals',
            value: String(drillParams[trimmedField])
          });
        }
      }
      console.log('钻取过滤条件:', drillFilters);
    } catch (e) {
      console.error('解析钻取参数失败:', e);
    }
  }

  const allRows = await fetchAllRows(functionCode, drillFilters, drillConditionSql);
  if (!allRows) {
    loading.value = false;
    return;
  }

  serverRows.value = allRows;
  total.value = allRows.length;
  page.value = 1;
  pageSize.value = normalizePageSize(Number(data.size));
  selectedField.value = data.meta.conditions[0]?.fieldKey || '';
  selectedValue.value = '';
  loading.value = false;

  // 数据加载完成后，调整列宽度
  setTimeout(() => {
    const api = gridApi.value;
    if (!api) return;

    const columnState = api.getColumnState();
    if (!columnState || !Array.isArray(columnState)) return;

    const allColIds = columnState
      .map((state: any) => state.colId)
      .filter((colId: string) => {
        if (colId === 'ag-Grid-SelectionColumn') return false;

        const column = api.getColumn(colId);
        if (column) {
          const def = column.getColDef();
          if (isGuidColumn(String(def.field || ''), String(def.headerName || def.field || ''))) {
            return false;
          }
        }

        return true;
      });

    if (allColIds.length > 0) {
      api.autoSizeColumns(allColIds, false);

      const maxWidth = 300;
      allColIds.forEach((colId: string) => {
        const column = api.getColumn(colId);
        if (column) {
          const currentWidth = column.getActualWidth();
          if (currentWidth > maxWidth) {
            api.setColumnWidths([{ key: colId, newWidth: maxWidth }]);
          }
        }
      });
    }
  }, 300);
}

async function fetchAllRows(functionCode: string, filters: QueryFilter[], drillConditionSql?: string) {
  const result = await fetchWorkbenchQuery(functionCode, {
    current: 1,
    size: pageSize.value,
    all: true,
    filters,
    drillCondition: drillConditionSql || ''
  });

  if (result.error) {
    return null;
  }

  return result.data.records;
}

async function queryPage() {
  const functionCode = String(props.meta.functionCode || '').trim();
  if (!functionCode) {
    return;
  }

  const filters =
    selectedField.value && selectedValue.value.trim()
      ? [
          {
            fieldKey: selectedField.value,
            operator: selectedOperator.value,
            value: selectedValue.value.trim()
          }
        ]
      : [];

  loading.value = true;
  const allRows = await fetchAllRows(functionCode, filters);
  if (!allRows) {
    loading.value = false;
    return;
  }

  serverRows.value = allRows;
  total.value = allRows.length;
  page.value = 1;
  loading.value = false;
}

async function handleRefresh() {
  await loadPage();
  useLegacyTabHint.value = false;
  window.$message?.success('已刷新工作台数据');
}

function handleReset() {
  quickKeyword.value = '';
  selectedField.value = pageMeta.value?.conditions[0]?.fieldKey || '';
  selectedOperator.value = 'contains';
  selectedValue.value = '';
  useLegacyTabHint.value = false;
}

function handleOpenCondition() {
  conditionVisible.value = true;
}

function handleOpenFieldColumn() {
  if (gridApi.value) {
    const visibleFields = gridApi.value
      .getColumnState()
      .filter(item => item.colId !== 'ag-Grid-SelectionColumn' && item.hide !== true)
      .map(item => String(item.colId));

    visibleFieldColumns.value = visibleFields;
  } else if (visibleFieldColumns.value.length === 0) {
    visibleFieldColumns.value = fieldColumnOptions.value.map(item => String(item.value));
  }

  fieldColumnVisible.value = true;
}

function handleSelectAllFieldColumns() {
  handleFieldSelectionChange(fieldColumnOptions.value.map(item => String(item.value)));
}

function handleClearFieldColumns() {
  handleFieldSelectionChange([]);
}

function handleFieldSelectionChange(values: Array<string | number>) {
  const normalizedValues = values.map(value => String(value));
  visibleFieldColumns.value = normalizedValues;

  if (!gridApi.value) {
    return;
  }

  const allColumnFields = fieldColumnOptions.value.map(item => String(item.value));
  const selectedSet = new Set(normalizedValues);
  const toShow = allColumnFields.filter(field => selectedSet.has(field));
  const toHide = allColumnFields.filter(field => !selectedSet.has(field));

  if (toShow.length > 0) {
    gridApi.value.setColumnsVisible(toShow, true);
  }

  if (toHide.length > 0) {
    gridApi.value.setColumnsVisible(toHide, false);
  }
}

function handleOpenPinColumn() {
  if (gridApi.value) {
    const pinnedLeft = gridApi.value
      .getColumnState()
      .filter(item => item.pinned === 'left')
      .map(item => String(item.colId));

    pinTargetFields.value = pinnedLeft;
  } else if (pinTargetFields.value.length === 0) {
    pinTargetFields.value = [];
  }

  pinColumnVisible.value = true;
}

function handleClearPinColumns() {
  handlePinSelectionChange([]);
}

function handlePinSelectionChange(values: Array<string | number>) {
  const normalizedValues = values.map(value => String(value));
  pinTargetFields.value = normalizedValues;

  if (!gridApi.value) {
    return;
  }

  const allColumnFields = pinColumnOptions.value.map(item => String(item.value));
  const selectedSet = new Set(normalizedValues);
  const toPin = allColumnFields.filter(field => selectedSet.has(field));
  const toUnpin = allColumnFields.filter(field => !selectedSet.has(field));

  if (toPin.length > 0) {
    gridApi.value.setColumnsPinned(toPin, 'left');
  }

  if (toUnpin.length > 0) {
    gridApi.value.setColumnsPinned(toUnpin, null);
  }
}

async function handleApplyCondition() {
  conditionVisible.value = false;
  await queryPage();
  window.$message?.success('已应用筛选条件');
}

function handleExport() {
  window.$message?.info('当前仅完成统一查询协议，导出接口待接入后端动作协议');
}

function handleOpenLegacyHint() {
  if (props.nativeOnly) {
    window.$message?.info('当前功能已锁定新协议链路，不再回退旧页。');
    return;
  }

  useLegacyTabHint.value = true;
  window.$message?.info('复杂操作仍建议切换到“旧页回退”标签继续处理');
}

function handleDataDrill() {
  // 参考旧版 Vgrid_aggrid.php，必须先选择 1 条记录
  const selectedRows = gridApi.value?.getSelectedRows() || [];
  if (selectedRows.length === 0) {
    window.$message?.warning('请先选择要钻取的记录');
    return;
  }
  if (selectedRows.length > 1) {
    window.$message?.warning('只能选择 1 条记录');
    return;
  }

  const functionCode = String(props.meta.functionCode || '').trim();
  if (!functionCode) {
    window.$message?.error('功能编码不能为空');
    return;
  }

  const selectedRow = selectedRows[0];

  loading.value = true;
  fetchWorkbenchDrill(functionCode, {})
    .then(({ data, error }) => {
      loading.value = false;
      console.log('Drill response:', { data, error });
      if (error) {
        window.$message?.error('获取钻取选项失败');
        return;
      }

      if (data.debug) {
        console.log('=== 钻取调试信息 ===');
        console.log('functionCode:', data.debug.functionCode);
        console.log('queryModule:', data.debug.queryModule);
        console.log('drillModule:', data.debug.drillModule);
        console.log('options count:', data.options?.length || 0);
        console.log('options:', data.options);
        console.log('options type:', typeof data.options);
        console.log('options isArray:', Array.isArray(data.options));
        console.log('options length:', data.options?.length);
        console.log('condition result:', !!(data.options && data.options.length > 0));
        const debugInfo = data.debug as any;
        console.log('functionAuth (from current context):', debugInfo.functionAuth);
        console.log('drillOptionsRaw (from SQL):', debugInfo.drillOptionsRaw);
      }

      console.log('Checking if should show dialog...');
      console.log('data.options:', data.options);
      console.log('data.options?.length:', data.options?.length);

      if (data.options && data.options.length > 0) {
        // 参考旧版：显示钻取选项（单选按钮）
        let dialogInstance: any = null;
        const options = data.options.map((opt: Api.Workbench.DrillOption, index: number) => ({
          label: opt.label,
          value: `${opt.functionCode}_${index}`,
          functionCode: opt.functionCode,
          module: opt.module || '',
          drillFields: opt.drillFields || '',
          drillCondition: opt.drillCondition || '',
          menu1: opt.menu1 || '',
          menu2: opt.menu2 || '',
          raw: opt
        }));

        console.log('Opening drill dialog with options:', options);

        console.log('Options for dialog:', options);
        console.log('Options count:', options.length);
        console.log('First option:', options[0]);

        // 使用 ref 存储选中的选项
        const selectedOption = ref<(typeof options)[0] | null>(options[0] || null);

        // 先定义 handleDrillConfirm 函数
        const handleDrillConfirm = (selectedOpt: (typeof options)[0]) => {
          console.log('Selected drill option:', selectedOpt);
          console.log('Selected row data:', selectedRow);

          // 参考旧版 Vgrid_aggrid.php 的钻取参数构建逻辑
          const drillItem = selectedOpt.raw;
          const drillFieldsStr = drillItem.drillFields || '';
          const sendObj: Record<string, any> = {};

          console.log('Drill fields string:', drillFieldsStr);

          // 处理钻取字段：格式为 字段1;字段2;...
          const nlArr = drillFieldsStr.split(';').filter(f => f.trim());
          console.log('Drill fields array:', nlArr);

          let hasValidField = false;

          for (const field of nlArr) {
            const trimmedField = field.trim();
            console.log(`Checking field "${trimmedField}":`, {
              exists: trimmedField && selectedRow[trimmedField] !== undefined,
              value: selectedRow[trimmedField]
            });
            if (trimmedField && selectedRow[trimmedField] !== undefined && selectedRow[trimmedField] !== '') {
              sendObj[trimmedField] = selectedRow[trimmedField];
              hasValidField = true;
            }
          }

          if (!hasValidField) {
            window.$message?.warning('钻取字段为空，无法钻取');
            return;
          }

          sendObj['钻取字段'] = drillItem.drillFields || '';
          sendObj['钻取条件'] = drillItem.drillCondition || '';

          // 参考旧版：获取显示的列
          const visibleColumns: string[] = [];
          const columns = gridApi.value?.getColumns() || [];
          console.log(
            'All columns:',
            columns.map(c => ({ id: c.getColId(), visible: c.isVisible() }))
          );
          for (const col of columns) {
            if (col.getColId() === 'ag-Grid-SelectionColumn') continue;
            if (col.getColId() === '序号') continue;
            if (!col.isVisible()) continue;
            visibleColumns.push(col.getColId());
          }
          console.log('Visible columns:', visibleColumns);
          sendObj['字段选择'] = visibleColumns;

          console.log('Drill sendObj:', sendObj);

          // 参考旧版 parent.window.goto 跳转逻辑
          const targetFunctionCode = drillItem.functionCode;
          const targetModule = drillItem.module || '';
          const targetMenu1 = drillItem.menu1 || '';
          const targetMenu2 = drillItem.menu2 || '';

          // 跳转到目标功能页面，将所有钻取参数放到 query 中，避免 sessionStorage 时序问题
          router.push({
            path: `/menu-bridge`,
            query: {
              functionCode: targetFunctionCode,
              module: targetModule,
              menu1: targetMenu1,
              menu2: targetMenu2,
              params: JSON.stringify(sendObj)
            }
          });
        };

        // 定义 renderDrillDialogContent 函数（在 handleDrillConfirm 之后）
        // 使用 ref 存储选中值
        const drillSelectedValue = ref<string>(options[0]?.value || '');

        const renderDrillDialogContent = () => {
          console.log('renderDrillDialogContent called with options:', options);
          console.log('Initial drillSelectedValue:', drillSelectedValue.value);

          // 处理单选按钮点击
          const handleRadioClick = (value: string) => {
            console.log('Radio clicked:', value);
            drillSelectedValue.value = value;
            selectedOption.value = options.find(opt => opt.value === value) || null;
          };

          return h('div', { style: { display: 'flex', flexDirection: 'column', minHeight: '250px' } }, [
            // 选项区域
            h(
              NRadioGroup,
              {
                value: drillSelectedValue.value,
                'onUpdate:value': (value: string) => {
                  console.log('NRadioGroup value changed:', value);
                  handleRadioClick(value);
                },
                style: { flex: 1, overflow: 'auto', padding: '16px' }
              },
              {
                default: () =>
                  options.map(opt =>
                    h(
                      NRadio,
                      {
                        value: opt.value,
                        style: { display: 'flex', marginBottom: '8px', alignItems: 'center' }
                      },
                      { default: () => opt.label }
                    )
                  )
              }
            ),
            // 底部按钮区域
            h(
              'div',
              {
                style: { display: 'flex', justifyContent: 'flex-end', padding: '16px', borderTop: '1px solid #3d4f60' }
              },
              h(
                NButton,
                {
                  type: 'primary',
                  onClick: () => {
                    if (!selectedOption.value) {
                      window.$message?.warning('请选择钻取条件');
                      return;
                    }
                    // 关闭弹窗后再执行钻取确认
                    if (dialogInstance) {
                      dialogInstance.destroy();
                    }
                    handleDrillConfirm(selectedOption.value);
                  }
                },
                { default: () => '确定' }
              )
            )
          ]);
        };

        // 显示弹窗
        dialogInstance = window.$dialog?.info({
          title: '选择钻取条件',
          style: { width: '350px', minHeight: '300px' },
          content: renderDrillDialogContent
        });
      } else {
        const drillModule = data.debug?.drillModule || 'empty';
        const queryModule = data.debug?.queryModule || 'empty';
        console.log('No drill options found. Drill module:', drillModule, 'Query module:', queryModule);

        if (drillModule && drillModule !== 'empty' && drillModule !== queryModule) {
          window.$message?.warning(`钻取模块 [${drillModule}] 在 def_drill_config 表中未找到配置`);
        } else if (queryModule && queryModule !== 'empty') {
          window.$message?.warning(`查询模块 [${queryModule}] 未配置钻取模块，且 def_drill_config 表中也无对应配置`);
        } else {
          window.$message?.warning('当前功能未配置钻取模块，请联系管理员');
        }
      }
    })
    .catch(() => {
      loading.value = false;
      window.$message?.error('钻取操作失败');
    });
}

function handleGridReady(event: GridReadyEvent<Api.Workbench.QueryRecord>) {
  gridApi.value = event.api;
  visibleFieldColumns.value = fieldColumnOptions.value.map(item => String(item.value));

  console.log('Grid ready, API initialized:', !!gridApi.value);
}

import { onMounted, onActivated } from 'vue';

// 缓存数据，避免重复请求
const isDataLoaded = ref(false);

// 记录当前已加载的 functionCode 和 params，用于检测是否真的需要重新加载
const loadedFunctionCode = ref<string>('');
const loadedParams = ref<string>('');

onMounted(() => {
  const currentFunctionCode = String(props.meta.functionCode || '');
  const currentParams = String(props.meta.params || '');

  // 只有当数据未加载，或者 functionCode/params 发生变化时才加载
  if (!isDataLoaded.value || currentFunctionCode !== loadedFunctionCode.value || currentParams !== loadedParams.value) {
    console.log('onMounted: loading page', {
      currentFunctionCode,
      currentParams,
      loadedFunctionCode: loadedFunctionCode.value,
      loadedParams: loadedParams.value
    });
    loadedFunctionCode.value = currentFunctionCode;
    loadedParams.value = currentParams;
    loadPage();
    isDataLoaded.value = true;
  } else {
    console.log('onMounted: using cached data');
  }
});

onActivated(() => {
  const currentFunctionCode = String(props.meta.functionCode || '');
  const currentParams = String(props.meta.params || '');

  // 组件被激活时，检查是否需要重新加载数据
  if (currentFunctionCode !== loadedFunctionCode.value || currentParams !== loadedParams.value) {
    console.log('onActivated: meta changed, reloading page', {
      currentFunctionCode,
      currentParams,
      loadedFunctionCode: loadedFunctionCode.value,
      loadedParams: loadedParams.value
    });
    loadedFunctionCode.value = currentFunctionCode;
    loadedParams.value = currentParams;
    loadPage();
  } else {
    console.log('Component activated, using cached data');
  }
});
</script>

<template>
  <div class="generic-query-workbench" :class="{ 'system-dark': isDarkMode }">
    <NCard
      :bordered="false"
      :content-style="{ padding: '8px 10px' }"
      class="toolbar-card mb-6px rounded-12px shadow-sm"
    >
      <div class="flex flex-wrap items-center justify-between gap-12px">
        <NSpace>
          <NButton @click="handleRefresh">刷新</NButton>
          <NButton @click="handleOpenPinColumn">固定列</NButton>
          <NButton @click="handleOpenFieldColumn">字段选择</NButton>
          <NButton @click="handleOpenCondition">条件面板</NButton>
          <NButton @click="handleDataDrill">数据钻取</NButton>
          <NButton @click="handleReset">重置</NButton>
          <NButton :disabled="!pageMeta?.toolbar.export" @click="handleExport">导出</NButton>
          <NButton secondary :disabled="props.nativeOnly" @click="handleOpenLegacyHint">复杂操作提示</NButton>
        </NSpace>
        <div class="flex items-center gap-12px">
          <NInput v-model:value="quickKeyword" clearable placeholder="快速检索当前结果" class="w-280px" />
          <NTag type="success" size="small">{{ String(pageMeta?.functionCode || props.meta.functionCode || '') }}</NTag>
        </div>
      </div>
    </NCard>

    <NAlert v-if="pageMeta?.fallbackHint" type="warning" class="mb-6px">
      {{ pageMeta.fallbackHint }}
    </NAlert>

    <NAlert v-if="useLegacyTabHint && !props.nativeOnly" type="info" class="mb-6px">
      表格、工具栏、条件面板已切到 Vue
      原生协议页；批量修改、备注、图形钻取、导出等深层能力建议暂时继续走“旧页回退”标签，直到对应动作接口补齐。
    </NAlert>

    <NCard
      :bordered="false"
      :content-style="{ padding: '0' }"
      class="grid-card rounded-12px shadow-sm workbench-grid-card"
    >
      <div class="ag-theme-shell" :class="{ 'ag-theme-shell-dynamic': props.dynamicLike }">
        <div v-if="loading" class="grid-loading">
          <NSpin size="large" />
        </div>
        <AgGridVue
          :theme="activeGridTheme"
          :column-defs="gridColumns"
          :default-col-def="defaultColDef"
          :row-height="38"
          :header-height="40"
          :read-only-edit="true"
          :row-data="filteredRows"
          :locale-text="AG_GRID_LOCALE_CN"
          :pagination="true"
          :pagination-page-size="pageSize"
          :pagination-page-size-selector="paginationPageSizeSelector"
          :row-selection="{ mode: 'multiRow', checkboxes: true, headerCheckbox: true }"
          :selection-column-def="{
            width: 37,
            minWidth: 37,
            // 不设置 maxWidth，选择列也允许自适应（但宽度已足够）
            resizable: false,
            headerClass: 'selection-header-left'
          }"
          class="query-grid"
          @grid-ready="handleGridReady"
        />
      </div>
    </NCard>

    <NModal
      v-model:show="pinColumnVisible"
      preset="card"
      title="固定列"
      class="w-420px pin-column-modal"
      :class="{ 'pin-column-modal-dark': isDarkMode }"
      :mask-closable="true"
    >
      <NSpace vertical :size="16">
        <div class="pin-column-select-panel">
          <div class="pin-column-actions">
            <NCheckbox
              :checked="pinTargetFields.length === 0"
              @update:checked="checked => checked && handleClearPinColumns()"
            >
              全不选
            </NCheckbox>
          </div>

          <NCheckboxGroup
            v-model:value="pinTargetFields"
            class="pin-column-group"
            @update:value="handlePinSelectionChange"
          >
            <NSpace vertical :size="10">
              <NCheckbox v-for="item in pinColumnOptions" :key="String(item.value)" :value="String(item.value)">
                {{ item.label }}
              </NCheckbox>
            </NSpace>
          </NCheckboxGroup>
        </div>
      </NSpace>
    </NModal>

    <NModal
      v-model:show="fieldColumnVisible"
      preset="card"
      title="字段选择"
      class="w-420px pin-column-modal"
      :class="{ 'pin-column-modal-dark': isDarkMode }"
      :mask-closable="true"
    >
      <NSpace vertical :size="16">
        <div class="pin-column-select-panel">
          <div class="pin-column-actions">
            <NCheckbox
              :checked="visibleFieldColumns.length === fieldColumnOptions.length && fieldColumnOptions.length > 0"
              @update:checked="checked => (checked ? handleSelectAllFieldColumns() : handleClearFieldColumns())"
            >
              全选
            </NCheckbox>
            <NCheckbox
              :checked="visibleFieldColumns.length === 0"
              @update:checked="checked => checked && handleClearFieldColumns()"
            >
              全不选
            </NCheckbox>
          </div>

          <NCheckboxGroup
            v-model:value="visibleFieldColumns"
            class="pin-column-group"
            @update:value="handleFieldSelectionChange"
          >
            <NSpace vertical :size="10">
              <NCheckbox v-for="item in fieldColumnOptions" :key="String(item.value)" :value="String(item.value)">
                {{ item.label }}
              </NCheckbox>
            </NSpace>
          </NCheckboxGroup>
        </div>
      </NSpace>
    </NModal>

    <NDrawer v-model:show="conditionVisible" :width="420" placement="right">
      <NDrawerContent title="条件面板" closable>
        <NSpace vertical :size="16">
          <NForm label-placement="top">
            <NFormItem label="字段">
              <NSelect
                v-model:value="selectedField"
                :options="filterableFields.map(field => ({ label: field, value: field }))"
              />
            </NFormItem>
            <NFormItem label="操作符">
              <NSelect
                v-model:value="selectedOperator"
                :options="[
                  { label: '包含', value: 'contains' },
                  { label: '等于', value: 'equals' },
                  { label: '前缀匹配', value: 'startsWith' }
                ]"
              />
            </NFormItem>
            <NFormItem label="取值">
              <NInput v-model:value="selectedValue" placeholder="输入筛选值" />
            </NFormItem>
          </NForm>

          <NAlert type="warning">
            当前已接到后端 JSON 协议；后续只需继续补齐新增、修改、删除、备注、钻取等动作接口。
          </NAlert>

          <NSpace justify="end">
            <NButton @click="conditionVisible = false">取消</NButton>
            <NButton type="primary" @click="handleApplyCondition">应用</NButton>
          </NSpace>
        </NSpace>
      </NDrawerContent>
    </NDrawer>
  </div>
</template>

<style scoped>
.generic-query-workbench {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

.system-dark.generic-query-workbench {
  --wb-dark-bg: rgb(var(--container-bg-color));
  background: var(--wb-dark-bg);
}

.toolbar-card,
.grid-card {
  background: #ffffff;
}

.ag-theme-shell {
  position: relative;
  height: 100%;
  min-height: 0;
}

.ag-theme-shell-dynamic {
  height: 100%;
  min-height: 0;
}

.workbench-grid-card {
  min-height: 0;
  overflow: hidden;
  flex: 1;
}

.query-grid {
  --wb-grid-surface: transparent;
  --wb-grid-text: #1f2937;
  width: 100%;
  height: 100%;
}

.pin-column-select-panel {
  max-height: 320px;
  overflow: auto;
  border: 1px solid #d4dce5;
  border-radius: 8px;
  padding: 12px;
  background: #f7f9fc;
}

.pin-column-actions {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-bottom: 12px;
}

.pin-column-actions :deep(.n-checkbox .n-checkbox__label) {
  color: #334155;
}

.pin-column-group {
  padding-top: 8px;
  border-top: 1px solid #d4dce5;
}

.pin-column-group :deep(.n-checkbox .n-checkbox__label) {
  color: #334155;
}

:deep(.query-grid .ag-root-wrapper),
:deep(.query-grid .ag-root),
:deep(.query-grid .ag-header),
:deep(.query-grid .ag-body),
:deep(.query-grid .ag-floating-top),
:deep(.query-grid .ag-floating-bottom),
:deep(.query-grid .ag-row),
:deep(.query-grid .ag-row-odd),
:deep(.query-grid .ag-row-even),
:deep(.query-grid .ag-header-row) {
  background-color: var(--wb-grid-surface) !important;
}

:deep(.query-grid .ag-header-cell),
:deep(.query-grid .ag-cell),
:deep(.query-grid .ag-header-cell-text),
:deep(.query-grid .ag-cell-value) {
  color: var(--wb-grid-text) !important;
}

:deep(.query-grid .ag-cell-wrapper),
:deep(.query-grid .ag-header-cell-comp-wrapper) {
  height: 100%;
  align-items: center;
}

:deep(.query-grid .ag-selection-checkbox),
:deep(.query-grid .ag-checkbox-input-wrapper) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

:deep(.query-grid .ag-selection-checkbox),
:deep(.query-grid .ag-header-select-all) {
  width: 100%;
  height: 100%;
}

:deep(.query-grid .ag-header-select-all) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding-left: 0;
}

:deep(.query-grid .selection-header-left .ag-header-cell-comp-wrapper) {
  width: 100%;
  justify-content: center !important;
  padding-left: 0 !important;
}

:deep(.query-grid .selection-header-left .ag-header-select-all) {
  margin: 0 auto;
  width: auto !important;
}

:deep(.query-grid .selection-header-left .ag-header-select-all .ag-selection-checkbox) {
  justify-content: center !important;
  width: auto !important;
  padding-left: 0 !important;
}

:deep(.query-grid .selection-header-left .ag-header-cell-label) {
  width: 100% !important;
  justify-content: center !important;
  padding-left: 0 !important;
  gap: 0 !important;
}

:deep(.query-grid .selection-header-left .ag-header-cell-label .ag-checkbox-input-wrapper) {
  margin-left: 0 !important;
  margin-right: 0 !important;
}

/* Final override: target AG Grid selection column directly. */
:deep(.query-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn']) {
  position: relative;
  padding-left: 7px !important;
  padding-right: 7px !important;
}

:deep(.query-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-header-cell-label) {
  justify-content: center !important;
  padding-left: 0 !important;
}

:deep(.query-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-header-select-all) {
  position: static !important;
  width: auto !important;
  height: auto !important;
  margin: 0 auto !important;
  padding: 0 !important;
}

:deep(.query-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-selection-checkbox),
:deep(.query-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-checkbox-input-wrapper) {
  margin: 0 !important;
  padding: 0 !important;
}

:deep(.query-grid .ag-cell[col-id='ag-Grid-SelectionColumn']) {
  padding-left: 7px !important;
  padding-right: 7px !important;
}

:deep(.query-grid .ag-cell-value),
:deep(.query-grid .ag-header-cell-text) {
  display: inline-flex;
  align-items: center;
}

.grid-loading {
  position: absolute;
  inset: 0;
  z-index: 2;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.7);
}

.system-dark .toolbar-card,
.system-dark .grid-card {
  background: var(--wb-dark-bg);
}

.pin-column-modal-dark .pin-column-select-panel {
  border-color: #3d4f60;
  background: #1b2a38;
}

.pin-column-modal-dark .pin-column-group {
  border-top-color: #3d4f60;
}

.pin-column-modal-dark .pin-column-actions :deep(.n-checkbox .n-checkbox__label) {
  color: rgb(var(--base-text-color));
}

.pin-column-modal-dark .pin-column-actions :deep(.n-checkbox .n-checkbox-box) {
  border-color: #7f95ac;
  background-color: #1f3042;
}

.pin-column-modal-dark .pin-column-actions :deep(.n-checkbox.n-checkbox--checked .n-checkbox-box) {
  border-color: #4ea4f3;
  background-color: #2f7fc5;
}

.pin-column-modal-dark .pin-column-group :deep(.n-checkbox .n-checkbox__label) {
  color: rgb(var(--base-text-color));
}

.pin-column-modal-dark .pin-column-group :deep(.n-checkbox .n-checkbox-box) {
  border-color: #7f95ac;
  background-color: #1f3042;
}

.pin-column-modal-dark .pin-column-group :deep(.n-checkbox.n-checkbox--checked .n-checkbox-box) {
  border-color: #4ea4f3;
  background-color: #2f7fc5;
}

.system-dark :deep(.toolbar-card),
.system-dark :deep(.grid-card) {
  --n-color: var(--wb-dark-bg);
  --n-color-embedded: var(--wb-dark-bg);
  --n-color-modal: var(--wb-dark-bg);
}

.system-dark :deep(.n-card),
.system-dark :deep(.n-card-header),
.system-dark :deep(.n-card-content),
.system-dark :deep(.n-card__content) {
  background: var(--wb-dark-bg) !important;
}

.system-dark :deep(.toolbar-card .n-card__content),
.system-dark :deep(.grid-card .n-card__content),
.system-dark :deep(.toolbar-card .n-card-content),
.system-dark :deep(.grid-card .n-card-content) {
  background: var(--wb-dark-bg);
}

.system-dark .query-grid {
  --wb-grid-surface: var(--wb-dark-bg);
  --wb-grid-text: rgb(var(--base-text-color));
  --ag-background-color: var(--wb-dark-bg);
  --ag-header-background-color: var(--wb-dark-bg);
  --ag-data-background-color: var(--wb-dark-bg);
  --ag-control-panel-background-color: var(--wb-dark-bg);
  --ag-panel-background-color: var(--wb-dark-bg);
  --ag-subheader-background-color: var(--wb-dark-bg);
  --ag-odd-row-background-color: var(--wb-dark-bg);
  --ag-foreground-color: var(--wb-grid-text);
  --ag-secondary-foreground-color: var(--wb-grid-text);
  --ag-border-color: #2b3a49;
  --ag-row-border-color: #2b3a49;
  --ag-input-background-color: #1b2a38;
  --ag-input-border-color: #43576b;
}

.system-dark .grid-loading {
  background: rgba(16, 22, 29, 0.75);
}

.system-dark :deep(.query-grid .ag-root-wrapper),
.system-dark :deep(.query-grid .ag-root),
.system-dark :deep(.query-grid .ag-header),
.system-dark :deep(.query-grid .ag-body),
.system-dark :deep(.query-grid .ag-floating-bottom),
.system-dark :deep(.query-grid .ag-floating-top) {
  background-color: var(--wb-dark-bg);
  color: var(--wb-grid-text);
}

.system-dark :deep(.query-grid .ag-root-wrapper) {
  border-color: #2b3a49;
}

.system-dark :deep(.query-grid .ag-paging-panel) {
  background-color: var(--wb-dark-bg);
  color: var(--wb-grid-text);
  border-top-color: #2b3a49;
}

.system-dark :deep(.query-grid .ag-paging-button) {
  color: var(--wb-grid-text);
  background-color: #1b2a38;
  border: 1px solid #43576b;
  border-radius: 4px;
}

.system-dark :deep(.query-grid .ag-paging-button:hover) {
  color: #f3f8ff;
  background-color: #243547;
  border-color: #5a7190;
}

.system-dark :deep(.query-grid .ag-paging-button.ag-disabled),
.system-dark :deep(.query-grid .ag-paging-button[aria-disabled='true']) {
  color: rgb(var(--base-text-color) / 0.45);
  background-color: #151f2a;
  border-color: #2f4152;
}

.system-dark :deep(.query-grid .ag-paging-button .ag-icon) {
  color: inherit;
}

.system-dark :deep(.query-grid .ag-picker-field-wrapper),
.system-dark :deep(.query-grid .ag-select .ag-picker-field-wrapper),
.system-dark :deep(.query-grid .ag-paging-page-size .ag-wrapper) {
  background-color: var(--wb-dark-bg);
  border-color: #43576b;
  color: var(--wb-grid-text);
}

.system-dark :deep(.query-grid .ag-picker-field-display),
.system-dark :deep(.query-grid .ag-picker-field-icon) {
  color: var(--wb-grid-text);
}

.system-dark :deep(.query-grid .ag-body-horizontal-scroll),
.system-dark :deep(.query-grid .ag-body-vertical-scroll),
.system-dark :deep(.query-grid .ag-body-horizontal-scroll-viewport),
.system-dark :deep(.query-grid .ag-body-vertical-scroll-viewport),
.system-dark :deep(.query-grid .ag-body-horizontal-scroll-container),
.system-dark :deep(.query-grid .ag-body-vertical-scroll-container) {
  background-color: var(--wb-dark-bg) !important;
}

.system-dark :deep(.query-grid .ag-body-horizontal-scroll-viewport),
.system-dark :deep(.query-grid .ag-body-vertical-scroll-viewport) {
  scrollbar-color: #5e6f80 var(--wb-dark-bg);
}

.system-dark :deep(.query-grid .ag-body-horizontal-scroll-viewport::-webkit-scrollbar),
.system-dark :deep(.query-grid .ag-body-vertical-scroll-viewport::-webkit-scrollbar) {
  background-color: var(--wb-dark-bg);
}

.system-dark :deep(.query-grid .ag-body-horizontal-scroll-viewport::-webkit-scrollbar-thumb),
.system-dark :deep(.query-grid .ag-body-vertical-scroll-viewport::-webkit-scrollbar-thumb) {
  background-color: #5e6f80;
  border-radius: 8px;
}

.system-dark :deep(.query-grid .ag-header-cell),
.system-dark :deep(.query-grid .ag-cell),
.system-dark :deep(.query-grid .ag-header-cell-text),
.system-dark :deep(.query-grid .ag-cell-value) {
  color: var(--wb-grid-text);
}

.system-dark :deep(.query-grid .ag-header-cell .ag-icon),
.system-dark :deep(.query-grid .ag-header-cell .ag-header-icon),
.system-dark :deep(.query-grid .ag-header-cell-menu-button),
.system-dark :deep(.query-grid .ag-header-cell-filter-button),
.system-dark :deep(.query-grid .ag-header-cell-sortable .ag-sort-indicator-icon) {
  color: var(--wb-grid-text) !important;
  opacity: 0.95;
}

.system-dark :deep(.query-grid .ag-header-cell-menu-button:hover),
.system-dark :deep(.query-grid .ag-header-cell-filter-button:hover),
.system-dark :deep(.query-grid .ag-header-cell .ag-icon:hover) {
  color: #f3f8ff !important;
  opacity: 1;
}

.system-dark :deep(.query-grid .ag-row-hover::before) {
  background-color: rgba(122, 167, 214, 0.18) !important;
}

/* Legacy visual hints from Vgrid_aggrid.php */
:deep(.ag-header-cell.editable-column .ag-header-cell-text) {
  text-decoration: underline;
  text-decoration-color: #1f8f63;
  text-decoration-thickness: 2px;
  font-weight: 700;
}

/* Force dotted row/column borders to match legacy Vgrid_aggrid.php */
:deep(.query-grid .ag-header-cell),
:deep(.query-grid .ag-cell) {
  border-right: 1px dotted #c1ccc7 !important;
}

:deep(.query-grid .ag-row),
:deep(.query-grid .ag-header-row) {
  border-bottom: 1px dotted #c1ccc7 !important;
}

:deep(.ag-header-cell.pinned-header) {
  background-color: #e6f7ff !important;
}

/* Align checkbox visual with legacy grid style (square box + white check). */
:deep(.query-grid .ag-checkbox-input-wrapper) {
  position: relative;
  width: 16px;
  height: 16px;
  border: 1px solid #95a6b8;
  border-radius: 2px;
  background-color: #ffffff;
  line-height: 16px;
}

:deep(.query-grid .ag-checkbox-input-wrapper::before) {
  display: none !important;
}

:deep(.query-grid .ag-checkbox-input-wrapper::after) {
  content: '';
  position: absolute;
  left: 50%;
  top: 50%;
  width: 0;
  height: 0;
  border: 0;
  transform: translate(-50%, -50%);
}

:deep(.query-grid .ag-checkbox-input-wrapper.ag-checked) {
  border-color: #2a90e8;
  background-color: #2a90e8;
}

:deep(.query-grid .ag-checkbox-input-wrapper.ag-checked::after) {
  content: '✓';
  width: auto;
  height: auto;
  color: #ffffff;
  font-size: 12px;
  font-weight: 700;
  line-height: 1;
  transform: translate(-50%, -56%);
}

:deep(.query-grid .ag-checkbox-input-wrapper.ag-indeterminate) {
  border-color: #2a90e8;
  background-color: #2a90e8;
}

:deep(.query-grid .ag-checkbox-input-wrapper.ag-indeterminate::after) {
  content: '';
  left: 50%;
  top: 50%;
  width: 8px;
  height: 2px;
  border: 0;
  background: #ffffff;
  transform: translate(-50%, -50%);
}

:deep(.query-grid .ag-cell .ag-checkbox-input-wrapper.ag-indeterminate::after) {
  content: '✓';
  width: auto;
  height: auto;
  border: 0;
  background: transparent;
  color: #ffffff;
  font-size: 12px;
  font-weight: 700;
  line-height: 1;
  transform: translate(-50%, -56%);
}

/* Match legacy selected-row tint. */
:deep(.query-grid .ag-row-selected::before) {
  background-color: #b7d7f5 !important;
}

:deep(.query-grid .ag-row-hover.ag-row-selected::before) {
  background-color: #c8e4ff !important;
  background-image: none !important;
}

.system-dark :deep(.query-grid .ag-header-cell),
.system-dark :deep(.query-grid .ag-cell) {
  border-right: 1px dotted #4b5965 !important;
}

.system-dark :deep(.query-grid .ag-row),
.system-dark :deep(.query-grid .ag-header-row) {
  border-bottom: 1px dotted #4b5965 !important;
}

.system-dark :deep(.ag-header-cell.pinned-header) {
  background-color: #1d3242 !important;
}

.system-dark :deep(.query-grid .ag-row-selected::before) {
  background-color: #34516f !important;
}

.system-dark :deep(.query-grid .ag-row-hover.ag-row-selected::before) {
  background-color: #406281 !important;
}

.system-dark :deep(.query-grid .ag-checkbox-input-wrapper) {
  border-color: #6f859b;
  background-color: var(--wb-dark-bg);
}

.system-dark :deep(.query-grid .ag-checkbox-input-wrapper.ag-checked),
.system-dark :deep(.query-grid .ag-checkbox-input-wrapper.ag-indeterminate) {
  border-color: #4ea4f3;
  background-color: #2f7fc5;
}
</style>
