import { ref, computed, watch, shallowRef } from 'vue';
import type { Ref } from 'vue';
import type { GridApi, ColDef } from 'ag-grid-community';

import { submitTableEdit } from '@/service/api/workbench';
import type { WorkbenchStore } from './use-workbench-grid-state';
import { WORKBENCH_CONFIG } from '@/config/workbench';
import { logger } from '@/utils/logger';

interface UseWorkbenchTableEditOptions {
  gridApi: Ref<GridApi<Api.Workbench.QueryRecord> | null>;
  getFunctionCode: () => string;
  getParams: () => string;
  workbenchStore: WorkbenchStore;
  notify: (type: 'success' | 'error' | 'warning' | 'info', message: string) => void;
  loadPage: () => void;
  serverRows: Ref<Api.Workbench.QueryRecord[]>;
  pageMeta: Ref<Api.Workbench.PageMeta | null>;
  colorMarkConfig: Ref<any>;
  isDarkMode: Ref<boolean>;
}

function getRowId(row: Api.Workbench.QueryRecord, index: number, primaryKey?: string): string {
  if (primaryKey) {
    const parts = primaryKey.split(';').map(p => p.trim()).filter(Boolean);
    if (parts.length > 0) {
      const values = parts.map(p => row[p]).filter(v => v !== undefined && v !== null && v !== '');
      if (values.length === parts.length) {
        return values.map(v => String(v)).join('|');
      }
    }
  }
  if (row.GUID) return String(row.GUID);
  if (row.id) return String(row.id);
  if (row.ID) return String(row.ID);
  return String(index);
}

function pickPrimaryKeyValue(row: Api.Workbench.QueryRecord, primaryKey?: string): Record<string, any> {
  if (primaryKey) {
    const parts = primaryKey.split(';').map(p => p.trim()).filter(Boolean);
    const result: Record<string, any> = {};
    for (const part of parts) {
      if (row[part] !== undefined) {
        result[part] = row[part];
      }
    }
    if (Object.keys(result).length > 0) {
      return result;
    }
  }
  const fallback: Record<string, any> = {};
  if (row.GUID !== undefined) fallback.GUID = row.GUID;
  if (row.id !== undefined) fallback.id = row.id;
  if (row.ID !== undefined) fallback.ID = row.ID;
  return fallback;
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

  const resolvePrimaryKey = (): string => options.pageMeta.value?.primaryKey ?? '';

  const hasTableModifications = computed(() => tableModifiedRows.value.size > 0);

  function log(method: 'info' | 'warn' | 'error' | 'debug', message: string, data?: unknown) {
    const timestamp = new Date().toLocaleTimeString('zh-CN', { hour12: false });
    const prefix = `[${timestamp}] [TABLE-EDIT] [${method.toUpperCase()}]`;

    if (data !== undefined) {
      logger.info(`${prefix} ${message}`, data);
    } else {
      logger.info(`${prefix} ${message}`);
    }
  }

  const gridColumns = computed<ColDef<Api.Workbench.QueryRecord>[]>(() => {
    const allColumns = options.pageMeta.value?.columns || [];
    const mergeableFields = allColumns
      .filter(col => col.canMerge)
      .map(col => col.field);

    return allColumns.map(column => {
      const headerClasses: string[] = [];

      if (column.editable) {
        headerClasses.push('editable-column');
      }

      // GUID 列恒隐藏：作为行内主键不参与业务展示
      const isGuidColumn =
        String(column.field || '')
          .trim()
          .toUpperCase() === 'GUID' ||
        String(column.title || '')
          .trim()
          .toUpperCase() === 'GUID';

      const definition: ColDef<Api.Workbench.QueryRecord> = {
        field: column.field,
        headerName: column.title,
        hide: column.hidden || isGuidColumn,
        sortable: column.sortable,
        filter: true,
        resizable: true,
        headerClass: headerClasses
      };

      if (column.canMerge) {
        definition.editable = false;
        definition.spanRows = (params: any) => {
          const { nodeA, nodeB } = params;
          if (!nodeA || !nodeB || !nodeA.data || !nodeB.data) return false;

          const currentRow = nodeA.data;
          const nextRow = nodeB.data;

          // 归一化：null/undefined/'' 视为同一空值，两行都是空值时也合并
          const normalize = (v: unknown) => (v === null || v === undefined || v === '' ? '' : v);

          return mergeableFields.every(field => normalize(currentRow[field]) === normalize(nextRow[field]));
        };
      }

      // 数值列右对齐基础样式：作用于 .ag-cell（flex 容器），
      // 用 justify-content 把 .ag-cell-value（flex 子项）推到右侧。
      // 同时保留 textAlign 作为非 flex 布局的兜底。
      // 注意：column.type 可能包含前后空格，需 trim 后再比较
      const isNumericColumn = (column.type || '').trim() === '数值';
      const numericBaseStyle: Record<string, string> | null = isNumericColumn
        ? { textAlign: 'right', justifyContent: 'flex-end' }
        : null;

      if (isNumericColumn) {
        definition.type = 'numericColumn';
        definition.cellClass = 'wb-numeric-cell';
        // 数值列使用数值筛选器（提供 > < >= <= 等数值比较运算符，
        // 否则默认 agTextColumnFilter 会按字符串比较，"10" < "9" 这种判断会出错）
        definition.filter = 'agNumberColumnFilter';
        // 数值列按数值大小排序：后端返回的值可能是字符串（PHP mysqli 默认返回字符串），
        // 默认 comparator 按字符串比较会导致 "10" < "9"，需转换为数值后再比较
        definition.comparator = (valueA: any, valueB: any) => {
          const numA = valueA === null || valueA === undefined || valueA === '' ? null : Number(valueA);
          const numB = valueB === null || valueB === undefined || valueB === '' ? null : Number(valueB);
          // 空值统一沉底，避免 NaN 干扰
          if (numA === null && numB === null) return 0;
          if (numA === null) return 1;
          if (numB === null) return -1;
          return numA - numB;
        };
        headerClasses.push('wb-numeric-header');
      }

      definition.cellStyle = (params: any) => {
        const data = params.data || {};
        const rowIndex = params.rowIndex;

        const rowId = getRowId(data, rowIndex, resolvePrimaryKey());
        const modifiedFields = modifiedRowsData.value.get(rowId);
        if (modifiedFields && column.field in modifiedFields) {
          if (options.isDarkMode.value) {
            return { ...numericBaseStyle, backgroundColor: '#856404', color: '#ffffff', border: 'none', outline: 'none', boxShadow: 'none' };
          }
          return { ...numericBaseStyle, backgroundColor: '#fff3cd', color: '#000000', border: 'none', outline: 'none', boxShadow: 'none' };
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
            if (match) return { ...numericBaseStyle, ...style };
          }
        }

        if (column.errorCondition) {
          const errorKey = `异常^${column.field}`;
          if (data[errorKey] === '1' || data[errorKey] === 1) {
            return { ...numericBaseStyle, ...parseStyleString(column.errorStyle || '') };
          }
        }

        if (column.hintCondition) {
          const hintKey = `提示^${column.field}`;
          if (data[hintKey] === '1' || data[hintKey] === 1) {
            return { ...numericBaseStyle, ...parseStyleString(column.hintStyle || '') };
          }
        }

        return numericBaseStyle;
      };

      return definition;
    });
  });

  function updateProcessedRows() {
    const rows = options.serverRows.value.map((row, index) => {
      const rowId = getRowId(row, index, resolvePrimaryKey());
      if (modifiedRowsData.value.has(rowId)) {
        return { ...row, ...modifiedRowsData.value.get(rowId) };
      }
      return row;
    });
    processedRows.value = rows;
    return rows;
  }

  function handleCellValueChanged(event: any, hasTableEditAuth: boolean) {
    log('info', `========== handleCellValueChanged 开始 ==========`);

    if (isRestoringCellValue.value) {
      log('debug', '正在恢复单元格值，跳过本次修改');
      return;
    }

    if (!hasTableEditAuth) {
      log('warn', '用户没有表级修改权限，恢复原始值');
      options.notify('warning', '数据在此处修改无效，请点击"单条修改"或"多条修改"按钮进行修改');
      isRestoringCellValue.value = true;
      const rowNode = event.node;
      const originalValue = event.oldValue;
      const field = event.colDef.field;
      log('debug', `恢复字段 ${field} 的值: ${event.newValue} -> ${originalValue}`);
      rowNode.setDataValue(field, originalValue);
      setTimeout(() => {
        isRestoringCellValue.value = false;
      }, 0);
      return;
    }

    const rowData = event.data;
    const rowIndex = event.rowIndex;
    const rowId = getRowId(rowData, rowIndex, resolvePrimaryKey());

    log('info', `行ID: ${rowId}, 字段: ${event.colDef.field}`);
    log('info', `值变化: ${event.oldValue} -> ${event.newValue}`);

    tableModifiedRows.value.add(rowId);
    log('debug', `已添加到修改集合，当前修改行数: ${tableModifiedRows.value.size}`);

    if (!originalRowsData.value.has(rowId)) {
      originalRowsData.value.set(rowId, { ...rowData });
      log('debug', `已保存原始数据`);
    }

    const currentData = modifiedRowsData.value.get(rowId) || {};
    currentData[event.colDef.field] = event.newValue;
    modifiedRowsData.value.set(rowId, currentData);
    log('debug', `已保存修改数据`);
    log('info', `========== handleCellValueChanged 结束 ==========`);
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
          api.setGridOption('rowData', rows);
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
        if (
          !previouslyModified.has(id) ||
          JSON.stringify(newModified.get(id)) !== JSON.stringify(oldModified?.get(id))
        ) {
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
          const rowId = getRowId(row, index, resolvePrimaryKey());
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
    log('info', '========== handleTableEditSubmit 开始 ==========');

    if (tableModifiedRows.value.size === 0) {
      log('warn', '没有需要提交的修改');
      options.notify('warning', '没有需要提交的修改');
      return;
    }

    const functionCode = options.getFunctionCode();
    if (!functionCode) {
      log('warn', '功能编码不能为空');
      options.notify('error', '功能编码不能为空');
      return;
    }

    log('info', `功能编码: ${functionCode}`);

    const modifiedData: Api.Workbench.QueryRecord[] = [];
    const primaryKey = resolvePrimaryKey();
    tableModifiedRows.value.forEach(rowId => {
      const originalRow = originalRowsData.value.get(rowId);
      const modifiedFields = modifiedRowsData.value.get(rowId);

      if (originalRow && modifiedFields) {
        const submitData: Record<string, any> = pickPrimaryKeyValue(originalRow, primaryKey);

        Object.keys(modifiedFields).forEach(field => {
          if (field !== '序号' && !field.startsWith('序号')) {
            submitData[field] = modifiedFields[field];
          }
        });

        modifiedData.push(submitData);
      }
    });

    log('info', `要提交的记录数: ${modifiedData.length}`);
    log('debug', '提交的数据:', modifiedData);

    if (modifiedData.length === 0) {
      log('warn', '没有需要提交的修改');
      options.notify('warning', '没有需要提交的修改');
      return;
    }

    try {
      log('info', '开始调用表级修改提交 API...');
      const { data, error } = await submitTableEdit(functionCode, modifiedData);

      if (error) {
        log('error', '表级修改提交失败 - API 错误:', error);
        options.notify('error', error.message || '表级修改提交失败');
        return;
      }

      log('info', 'API 返回数据:', data);

      if (data?.success) {
        log('info', '表级修改提交成功');
        options.notify('success', data.message || '表级修改提交成功');
        tableModifiedRows.value.clear();
        modifiedRowsData.value.clear();
        originalRowsData.value.clear();
        const params = options.getParams();
        options.workbenchStore.clearCache(functionCode, params);
        options.loadPage();
      } else {
        log('error', '表级修改提交失败 - 业务错误:', data?.message);
        options.notify('error', data?.message || '表级修改提交失败');
      }
    } catch (e: any) {
      log('error', '表级修改提交失败 - 异常:', e);
      options.notify('error', e.message || '表级修改提交失败');
    }

    log('info', '========== handleTableEditSubmit 结束 ==========');
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
