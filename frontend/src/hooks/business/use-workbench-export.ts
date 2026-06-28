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
          filters
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
