import { ref, computed, watch, shallowRef } from 'vue';
import type { Ref, ComputedRef } from 'vue';
import type { GridApi, ColDef } from 'ag-grid-community';

import { submitTableEdit } from '@/service/api/workbench';
import type { WorkbenchStore } from './use-workbench-grid-state';
import { WORKBENCH_CONFIG } from '@/config/workbench';

interface UseWorkbenchTableEditOptions {
  gridApi: Ref<GridApi<Api.Workbench.QueryRecord> | null>;
  getFunctionCode: () => string;
  getParams: () => string;
  workbenchStore: WorkbenchStore;
  notify: (type: 'success' | 'error' | 'warning' | 'info', message: string) => void;
  loadPage: () => void;
  serverRows: Ref<Api.Workbench.QueryRecord[]>;
  pageMeta: ComputedRef<Api.Workbench.PageMeta | null>;
  colorMarkConfig: Ref<any>;
  isDarkMode: Ref<boolean>;
}

function getRowId(row: Api.Workbench.QueryRecord, index: number): string {
  if (row.GUID) return String(row.GUID);
  if (row.id) return String(row.id);
  if (row.ID) return String(row.ID);
  return String(index);
}

/**
 * 解析样式字符串为 CSS 对象
 * 格式: "color:red,background-color:#f7acbc,font-weight:bold"
 */
function parseStyleString(styleStr: string): Record<string, string> {
  const { DEFAULT_STYLES } = WORKBENCH_CONFIG;
  const defaultStyle = DEFAULT_STYLES.COLOR_MARK;
  if (!styleStr) return defaultStyle;

  const styleObj: Record<string, string> = {};
  const items = styleStr.split(',');
  for (const item of items) {
    const [key, value] = item.split(':');
    if (key && value) {
      // 将 CSS 属性名转换为 camelCase
      const camelKey = key.trim().replace(/-([a-z])/g, g => g[1].toUpperCase());
      styleObj[camelKey] = value.trim();
    }
  }
  return Object.keys(styleObj).length > 0 ? styleObj : defaultStyle;
}

export function useWorkbenchTableEdit(options: UseWorkbenchTableEditOptions) {
  const tableModifiedRows = ref<Set<string | number>>(new Set());
  const modifiedRowsData = ref<Map<string | number, Record<string, any>>>(new Map());
  const originalRowsData = ref<Map<string | number, Api.Workbench.QueryRecord>>(new Map());
  const isRestoringCellValue = ref(false);
  const processedRows = shallowRef<Api.Workbench.QueryRecord[]>([]);

  const hasTableModifications = computed(() => tableModifiedRows.value.size > 0);

  function logger(method: 'info' | 'warn' | 'error' | 'debug', message: string, data?: unknown) {
    const timestamp = new Date().toISOString().split('T')[1].split('.')[0];
    const prefix = `[${timestamp}] [TABLE-EDIT] [${method.toUpperCase()}]`;
    
    if (data !== undefined) {
      console.log(`${prefix} ${message}`, data);
    } else {
      console.log(`${prefix} ${message}`);
    }
  }

  const gridColumns = computed<ColDef<Api.Workbench.QueryRecord>[]>(() => {
    return (options.pageMeta.value?.columns || []).map(column => {
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

      definition.cellStyle = (params: any) => {
        const data = params.data || {};
        const rowIndex = params.rowIndex;

        const rowId = getRowId(data, rowIndex);
        const modifiedFields = modifiedRowsData.value.get(rowId);
        if (modifiedFields && column.field in modifiedFields) {
          if (options.isDarkMode.value) {
            return { backgroundColor: '#856404', color: '#ffffff', border: 'none', outline: 'none', boxShadow: 'none' };
          }
          return { backgroundColor: '#fff3cd', color: '#000000', border: 'none', outline: 'none', boxShadow: 'none' };
        }

        if (column.colorMarkEnabled && options.colorMarkConfig.value) {
          const { field1, operator, field2, style } = options.colorMarkConfig.value;
          if (column.field === field1 || column.field === field2) {
            const val1 = Number(data[field1]);
            const val2 = Number(data[field2]);
            let match = false;
            switch (operator) {
              case '大于':
                match = val1 > val2;
                break;
              case '小于':
                match = val1 < val2;
                break;
              case '等于':
                match = val1 === val2;
                break;
              case '大于等于':
                match = val1 >= val2;
                break;
              case '小于等于':
                match = val1 <= val2;
                break;
              case '不等于':
                match = val1 !== val2;
                break;
            }
            if (match) return style;
          }
        }

        if (column.errorCondition) {
          const errorKey = `异常^${column.field}`;
          if (data[errorKey] === '1' || data[errorKey] === 1) {
            return parseStyleString(column.errorStyle || '');
          }
        }

        if (column.hintCondition) {
          const hintKey = `提示^${column.field}`;
          if (data[hintKey] === '1' || data[hintKey] === 1) {
            return parseStyleString(column.hintStyle || '');
          }
        }

        return null;
      };

      return definition;
    });
  });

  function updateProcessedRows() {
    const rows = options.serverRows.value.map((row, index) => {
      const rowId = getRowId(row, index);
      if (modifiedRowsData.value.has(rowId)) {
        return { ...row, ...modifiedRowsData.value.get(rowId) };
      }
      return row;
    });
    processedRows.value = rows;
    return rows;
  }

  function handleCellValueChanged(event: any, hasTableEditAuth: boolean) {
    logger('info', `========== handleCellValueChanged 开始 ==========`);
    
    if (isRestoringCellValue.value) {
      logger('debug', '正在恢复单元格值，跳过本次修改');
      return;
    }

    if (!hasTableEditAuth) {
      logger('warn', '用户没有表级修改权限，恢复原始值');
      options.notify('warning', '数据在此处修改无效，请点击"单条修改"或"多条修改"按钮进行修改');
      isRestoringCellValue.value = true;
      const rowNode = event.node;
      const originalValue = event.oldValue;
      const field = event.colDef.field;
      logger('debug', `恢复字段 ${field} 的值: ${event.newValue} -> ${originalValue}`);
      rowNode.setDataValue(field, originalValue);
      setTimeout(() => {
        isRestoringCellValue.value = false;
      }, 0);
      return;
    }

    const rowData = event.data;
    const rowIndex = event.rowIndex;
    const rowId = getRowId(rowData, rowIndex);
    
    logger('info', `行ID: ${rowId}, 字段: ${event.colDef.field}`);
    logger('info', `值变化: ${event.oldValue} -> ${event.newValue}`);

    tableModifiedRows.value.add(rowId);
    logger('debug', `已添加到修改集合，当前修改行数: ${tableModifiedRows.value.size}`);

    if (!originalRowsData.value.has(rowId)) {
      originalRowsData.value.set(rowId, { ...rowData });
      logger('debug', `已保存原始数据`);
    }

    const currentData = modifiedRowsData.value.get(rowId) || {};
    currentData[event.colDef.field] = event.newValue;
    modifiedRowsData.value.set(rowId, currentData);
    logger('debug', `已保存修改数据`);
    logger('info', `========== handleCellValueChanged 结束 ==========`);
  }

  watch(
    () => options.serverRows.value,
    () => {
      tableModifiedRows.value.clear();
      modifiedRowsData.value.clear();
      originalRowsData.value.clear();
      
      const rows = updateProcessedRows();
      const api = options.gridApi.value;
      if (api && !api.isDestroyed()) {
        setTimeout(() => {
        const currentRowCount = api.getDisplayedRowCount();
        if (currentRowCount === 0 || rows.length <= currentRowCount) {
          api.setGridOption('rowData', rows);
        } else {
          const newRows = rows.slice(currentRowCount);
          if (newRows.length > 0) {
            api.applyTransaction({ add: newRows });
          }
        }
      }, 0);
      }
    },
    { immediate: true, deep: false }
  );

  watch(
    () => modifiedRowsData.value,
    (newModified, oldModified) => {
      updateProcessedRows();
      
      const api = options.gridApi.value;
      if (!api || api.isDestroyed()) return;

      const newlyModified = new Set<string | number>();
      newModified.forEach((_, key) => newlyModified.add(key));
      
      const previouslyModified = new Set<string | number>();
      oldModified?.forEach((_, key) => previouslyModified.add(key));

      const updatedRowIds = new Set<string | number>();
      newlyModified.forEach(id => {
        if (!previouslyModified.has(id) || 
            JSON.stringify(newModified.get(id)) !== JSON.stringify(oldModified?.get(id))) {
          updatedRowIds.add(id);
        }
      });

      previouslyModified.forEach(id => {
        if (!newlyModified.has(id)) {
          updatedRowIds.add(id);
        }
      });

      if (updatedRowIds.size > 0) {
        const updatedRows: Api.Workbench.QueryRecord[] = [];
        options.serverRows.value.forEach((row, index) => {
          const rowId = getRowId(row, index);
          if (updatedRowIds.has(rowId)) {
            const modifiedFields = newModified.get(rowId);
            updatedRows.push(modifiedFields ? { ...row, ...modifiedFields } : row);
          }
        });

        if (updatedRows.length > 0) {
          setTimeout(() => {
            api.applyTransaction({ update: updatedRows });
          }, 0);
        }
      }
    },
    { deep: true }
  );

  async function handleTableEditSubmit() {
    if (tableModifiedRows.value.size === 0) {
      options.notify('warning', '没有需要提交的修改');
      return;
    }

    const functionCode = options.getFunctionCode();
    if (!functionCode) {
      options.notify('error', '功能编码不能为空');
      return;
    }

    const modifiedData: Api.Workbench.QueryRecord[] = [];
    tableModifiedRows.value.forEach(rowId => {
      const originalRow = originalRowsData.value.get(rowId);
      const modifiedFields = modifiedRowsData.value.get(rowId);

      if (originalRow && modifiedFields) {
        const submitData: Record<string, any> = {};

        if (originalRow.GUID !== undefined) submitData.GUID = originalRow.GUID;
        if (originalRow.id !== undefined) submitData.id = originalRow.id;
        if (originalRow.ID !== undefined) submitData.ID = originalRow.ID;

        Object.keys(modifiedFields).forEach(field => {
          if (field !== '序号' && !field.startsWith('序号')) {
            submitData[field] = modifiedFields[field];
          }
        });

        modifiedData.push(submitData);
      }
    });

    if (modifiedData.length === 0) {
      options.notify('warning', '没有需要提交的修改');
      return;
    }

    try {
      const { data, error } = await submitTableEdit(functionCode, modifiedData);
      if (error) {
        options.notify('error', error.message || '表级修改提交失败');
        return;
      }

      if (data?.success) {
        options.notify('success', data.message || '表级修改提交成功');
        tableModifiedRows.value.clear();
        modifiedRowsData.value.clear();
        originalRowsData.value.clear();
        const params = options.getParams();
        options.workbenchStore.clearCache(functionCode, params);
        options.loadPage();
      } else {
        options.notify('error', data?.message || '表级修改提交失败');
      }
    } catch (e: any) {
      options.notify('error', e.message || '表级修改提交失败');
    }
  }

  function clearModifications() {
    tableModifiedRows.value.clear();
    modifiedRowsData.value.clear();
    originalRowsData.value.clear();
  }

  return {
    tableModifiedRows,
    modifiedRowsData,
    originalRowsData,
    hasTableModifications,
    isRestoringCellValue,
    processedRows,
    gridColumns,
    handleCellValueChanged,
    handleTableEditSubmit,
    clearModifications,
    getRowId
  };
}