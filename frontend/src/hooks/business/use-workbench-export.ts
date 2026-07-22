import { ref, type Ref } from 'vue';
import type { GridApi } from 'ag-grid-community';
import * as XLSX from '@e965/xlsx';
import { getAuthorization } from '@/service/request/shared';
import { getServiceBaseURL } from '@/utils/service';

const isHttpProxy = import.meta.env.DEV && import.meta.env.VITE_HTTP_PROXY === 'Y';
const { baseURL } = getServiceBaseURL(import.meta.env, isHttpProxy);

type NotifyType = 'success' | 'error' | 'warning' | 'info';

interface UseWorkbenchExportOptions {
  gridApi: Ref<GridApi<Api.Workbench.QueryRecord> | null>;
  getFunctionCode: () => string;
  notify: (type: NotifyType, message: string) => void;
  getFilters?: () => any[];
}

/**
 * 通用：收集 ag-grid 组合筛选条件中的非空子条件。
 *
 * ag-grid v32+ 的 model 结构：
 *   - 单条件：{ type: 'contains', filter: 'value' }
 *   - 组合  ：{ operator: 'AND' | 'OR',
 *              type: 'contains', filter: 'value1',
 *              condition1: { type: 'contains', filter: 'value2' },
 *              condition2: { type: 'contains', filter: 'value3' } }
 *
 * 空值/非空值筛选（blank / notBlank / empty / notEmpty）没有 filter 字段，
 * 需要通过 nullOpMap 单独识别并映射为 isNull / isNotNull。
 */
function collectAgGridConditions(
  model: any,
  opMap: Record<string, string>,
  nullOpMap?: Record<string, string>
): Array<{ operator: string; value: string }> {
  const conditions: Array<{ operator: string; value: string }> = [];
  const nullTypeSet = new Set(nullOpMap ? Object.keys(nullOpMap) : []);

  const collect = (m: any) => {
    if (!m) return;
    const type = m.type;
    if (type === undefined || type === null) return;

    const isNullType = nullTypeSet.has(type);
    if (!isNullType && (m.filter === undefined || m.filter === null || m.filter === '')) return;

    const op = isNullType
      ? (nullOpMap?.[type] ?? 'contains')
      : (opMap[type] || opMap.default || 'contains');
    conditions.push({ operator: op, value: isNullType ? '' : String(m.filter) });
  };

  collect(model);
  collect(model.condition1);
  collect(model.condition2);
  return conditions;
}

/**
 * ag-grid 文本筛选条件转后端筛选格式
 *
 * 后端 buildWhereConditions 支持三种：
 *   - { fieldKey, operator, value }                       单条件
 *   - 多条 { fieldKey, operator, value }                  同字段 AND（多条 = 多条 WHERE AND）
 *   - { fieldOrFilter: { fieldKey, conditions: [...] } }  同字段 OR
 */
export function convertAgGridTextFilterToBackend(colId: string, model: any): any[] {
  if (!model || model.filterType !== 'text') {
    return [];
  }

  const typeMap: Record<string, string> = {
    contains: 'contains',
    equals: 'equals',
    notEqual: 'notEqual',
    startsWith: 'startsWith',
    endsWith: 'endsWith'
  };

  // 空值/非空值筛选：ag-grid 中文 locale 可能显示为"空"、"非空"，
  // 底层 model.type 常见为 blank / notBlank / empty / notEmpty。
  const nullOpMap: Record<string, string> = {
    blank: 'isNull',
    notBlank: 'isNotNull',
    empty: 'isNull',
    notEmpty: 'isNotNull'
  };

  const fieldKey = colId;
  const combineOp: string | undefined = model.operator;
  const conditions = collectAgGridConditions(model, typeMap, nullOpMap);

  if (conditions.length === 0) {
    return [];
  }

  if (combineOp === 'OR' && conditions.length > 1) {
    return [{ fieldOrFilter: { fieldKey, conditions } }];
  }

  // AND（或单条 condition）展开成多条独立 filter
  return conditions.map(c => ({ fieldKey, ...c }));
}

/**
 * ag-grid 数值筛选条件转后端筛选格式
 *
 * model 示例：
 *   - { filterType: 'number', type: 'equals', filter: 100 }
 *   - { filterType: 'number', operator: 'AND',
 *       type: 'greaterThan', filter: 100,
 *       condition1: { type: 'lessThan', filter: 200 } }
 *   - { filterType: 'number', type: 'inRange', filter: 100, filterTo: 200 }
 */
export function convertAgGridNumberFilterToBackend(colId: string, model: any): any[] {
  if (!model || model.filterType !== 'number') {
    return [];
  }

  const fieldKey = colId;

  // 范围筛选：拆成 >= min 和 <= max 两条独立 filter（后端 AND 连接）
  if (model.type === 'inRange') {
    const min = model.filter;
    const max = model.filterTo;
    const result: any[] = [];
    if (min !== undefined && min !== null && min !== '') {
      result.push({ fieldKey, operator: 'greaterThanOrEqual', value: String(min) });
    }
    if (max !== undefined && max !== null && max !== '') {
      result.push({ fieldKey, operator: 'lessThanOrEqual', value: String(max) });
    }
    return result;
  }

  const typeMap: Record<string, string> = {
    equals: 'equals',
    notEqual: 'notEqual',
    greaterThan: 'greaterThan',
    greaterThanOrEqual: 'greaterThanOrEqual',
    lessThan: 'lessThan',
    lessThanOrEqual: 'lessThanOrEqual'
  };

  const nullOpMap: Record<string, string> = {
    blank: 'isNull',
    notBlank: 'isNotNull'
  };

  const combineOp: string | undefined = model.operator;
  const conditions = collectAgGridConditions(model, typeMap, nullOpMap);

  if (conditions.length === 0) {
    return [];
  }

  if (combineOp === 'OR' && conditions.length > 1) {
    return [{ fieldOrFilter: { fieldKey, conditions } }];
  }

  return conditions.map(c => ({ fieldKey, ...c }));
}

/**
 * ag-grid 日期筛选条件转后端筛选格式
 *
 * model 示例：
 *   - { filterType: 'date', type: 'equals', filter: '2024-01-01' }
 *   - { filterType: 'date', type: 'inRange', filter: '2024-01-01', filterTo: '2024-01-31' }
 */
export function convertAgGridDateFilterToBackend(colId: string, model: any): any[] {
  if (!model || model.filterType !== 'date') {
    return [];
  }

  const fieldKey = colId;

  if (model.type === 'inRange') {
    const min = model.filter;
    const max = model.filterTo;
    const result: any[] = [];
    if (min !== undefined && min !== null && min !== '') {
      result.push({ fieldKey, operator: 'greaterThanOrEqual', value: String(min) });
    }
    if (max !== undefined && max !== null && max !== '') {
      result.push({ fieldKey, operator: 'lessThanOrEqual', value: String(max) });
    }
    return result;
  }

  const typeMap: Record<string, string> = {
    equals: 'equals',
    notEqual: 'notEqual',
    greaterThan: 'greaterThan',
    greaterThanOrEqual: 'greaterThanOrEqual',
    lessThan: 'lessThan',
    lessThanOrEqual: 'lessThanOrEqual'
  };

  const nullOpMap: Record<string, string> = {
    blank: 'isNull',
    notBlank: 'isNotNull'
  };

  const combineOp: string | undefined = model.operator;
  const conditions = collectAgGridConditions(model, typeMap, nullOpMap);

  if (conditions.length === 0) {
    return [];
  }

  if (combineOp === 'OR' && conditions.length > 1) {
    return [{ fieldOrFilter: { fieldKey, conditions } }];
  }

  return conditions.map(c => ({ fieldKey, ...c }));
}

/**
 * 收集 gridApi 当前的列筛选，转换为后端筛选格式
 */
export function collectColumnFilters(gridApi: GridApi<Api.Workbench.QueryRecord> | null): any[] {
  if (!gridApi || gridApi.isDestroyed()) {
    return [];
  }
  const model = gridApi.getFilterModel() ?? {};
  const result: any[] = [];
  for (const colId of Object.keys(model)) {
    const filterModel = model[colId];
    const filterType = filterModel?.filterType;
    let filters: any[] = [];

    if (filterType === 'text') {
      filters = convertAgGridTextFilterToBackend(colId, filterModel);
    } else if (filterType === 'number') {
      filters = convertAgGridNumberFilterToBackend(colId, filterModel);
    } else if (filterType === 'date') {
      filters = convertAgGridDateFilterToBackend(colId, filterModel);
    }

    if (filters.length > 0) {
      result.push(...filters);
    }
  }
  return result;
}

/**
 * 收集需要跨行合并的列名（colDef.spanRows=true 的可见列 field）
 *
 * 用于导出时告知后端哪些列需要按连续相同值合并。
 * 与 ag-grid 显示侧共用同一份真相源（colDef.spanRows），确保所见即所得。
 */
export function collectMergeColumns(gridApi: GridApi<Api.Workbench.QueryRecord> | null): string[] {
  if (!gridApi || gridApi.isDestroyed()) {
    return [];
  }
  const columns = gridApi.getColumns() || [];
  const result: string[] = [];
  for (const col of columns) {
    const colDef = col.getColDef();
    if (!colDef.hide && colDef.spanRows && colDef.field) {
      result.push(colDef.field);
    }
  }
  return result;
}

export interface ExportOptions {
  format?: 'xlsx' | 'csv';
  /** 是否导出全部数据（忽略筛选条件），默认 true */
  exportAll?: boolean;
  columns?: string[];
}

export function useWorkbenchExport(options: UseWorkbenchExportOptions) {
  const exportLoading = ref(false);

  async function handleExport(exportOptions: ExportOptions = {}) {
    const { format = 'xlsx', exportAll = true, columns = [] } = exportOptions;
    const functionCode = options.getFunctionCode();

    await serverExport(functionCode, format, columns, exportAll);
  }

  async function serverExport(functionCode: string, format: string, columns: string[], exportAll: boolean) {
    exportLoading.value = true;
    try {
      const filters = exportAll ? [] : (options.getFilters?.() ?? []);

      // 从 ag-grid 列定义中提取需要跨行合并的列名（canMerge=true 的可见列 field）
      const mergeColumns = collectMergeColumns(options.gridApi.value);

      const Authorization = getAuthorization();

      const apiUrl = `${baseURL}/workbench/export/${encodeURIComponent(functionCode)}`;
      const response = await fetch(apiUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          ...(Authorization ? { Authorization } : {})
        },
        body: JSON.stringify({
          format,
          allData: exportAll,
          columns,
          filters,
          mergeColumns
        })
      });

      if (!response.ok) {
        throw new Error(`导出失败: HTTP ${response.status}`);
      }

      const contentType = response.headers.get('content-type') || '';
      const isJson = contentType.includes('application/json');

      if (isJson) {
        const errorData = await response.json();
        throw new Error(errorData.msg || errorData.message || '导出失败');
      }

      const blob = await response.blob();

      const contentDisposition = response.headers.get('content-disposition') ?? '';
      let filename = `${functionCode}_${new Date().toISOString().slice(0, 19).replace(/:/g, '-')}.${format}`;

      const match = contentDisposition.match(/filename="([^"]+)"/);
      if (match && match[1]) {
        filename = decodeURIComponent(match[1]);
      }

      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      window.URL.revokeObjectURL(url);

      options.notify('success', `成功导出数据到 ${filename}`);
    } catch (error: any) {
      options.notify('error', error.message || '导出失败');
    } finally {
      exportLoading.value = false;
    }
  }

  function clientExport(format: string) {
    const api = options.gridApi.value;
    if (!api || api.isDestroyed()) {
      options.notify('warning', '表格未初始化，无法导出');
      return;
    }

    const rowData: any[] = [];
    api.forEachNode(node => {
      rowData.push(node.data);
    });

    if (rowData.length === 0) {
      options.notify('warning', '当前没有数据可导出');
      return;
    }

    const columns = api.getColumns() || [];
    const visibleColumns = columns.filter(col => {
      const colDef = col.getColDef();
      return !colDef.hide && colDef.field && colDef.field !== '';
    });

    const headers = visibleColumns.map(col => {
      const colDef = col.getColDef();
      return colDef.headerName || colDef.field || '';
    });

    const exportData = rowData.map(row => {
      const rowObj: Record<string, any> = {};
      visibleColumns.forEach(col => {
        const colDef = col.getColDef();
        const field = colDef.field;
        if (field) {
          const headerName = colDef.headerName || field;
          rowObj[headerName] = row[field] ?? '';
        }
      });
      return rowObj;
    });

    const fnCode = options.getFunctionCode() || 'export';
    const timestamp = new Date().toISOString().slice(0, 19).replace(/:/g, '-');
    const filename = `${fnCode}_${timestamp}.${format}`;

    if (format === 'csv') {
      exportToCsv(headers, exportData, filename);
    } else {
      exportToExcel(headers, exportData, filename);
    }

    options.notify('success', `成功导出 ${rowData.length} 条数据`);
  }

  function exportToExcel(headers: string[], data: any[], filename: string) {
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.json_to_sheet(data, { header: headers });
    ws['!cols'] = headers.map(header => ({ wch: Math.max(header.length * 2, 12) }));
    XLSX.utils.book_append_sheet(wb, ws, '数据');
    XLSX.writeFile(wb, filename);
  }

  function exportToCsv(headers: string[], data: any[], filename: string) {
    const csvContent = [
      headers.join(','),
      ...data.map(row => headers.map(h => `"${String(row[h] ?? '').replace(/"/g, '""')}"`).join(','))
    ].join('\n');

    const blob = new Blob(['\ufeff' + csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
  }

  return { handleExport, exportLoading };
}
