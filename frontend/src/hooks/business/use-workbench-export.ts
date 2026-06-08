import type { Ref } from 'vue';
import type { GridApi } from 'ag-grid-community';
import * as XLSX from 'xlsx';

type NotifyType = 'success' | 'error' | 'warning' | 'info';

interface UseWorkbenchExportOptions {
  gridApi: Ref<GridApi<Api.Workbench.QueryRecord> | null>;
  getFunctionCode: () => string;
  notify: (type: NotifyType, message: string) => void;
}

/**
 * 工作台导出组合式函数
 * - 导出当前 grid 已显示（含分页加载）的所有数据为 xlsx
 * - 文件名：{functionCode}_{ISO 时间戳}.xlsx
 */
export function useWorkbenchExport(options: UseWorkbenchExportOptions) {
  function handleExport() {
    const api = options.gridApi.value;
    if (!api || api.isDestroyed()) {
      options.notify('warning', '表格未初始化，无法导出');
      return;
    }

    // 收集当前显示的所有行
    const rowData: any[] = [];
    api.forEachNode(node => {
      rowData.push(node.data);
    });

    if (rowData.length === 0) {
      options.notify('warning', '当前没有数据可导出');
      return;
    }

    // 仅导出可见列（排除隐藏列与无 field 列）
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

    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.json_to_sheet(exportData, { header: headers });
    ws['!cols'] = headers.map(header => ({ wch: Math.max(header.length * 2, 12) }));
    XLSX.utils.book_append_sheet(wb, ws, '数据');

    const fnCode = options.getFunctionCode() || 'export';
    const timestamp = new Date().toISOString().slice(0, 19).replace(/:/g, '-');
    const filename = `${fnCode}_${timestamp}.xlsx`;
    XLSX.writeFile(wb, filename);

    options.notify('success', `成功导出 ${rowData.length} 条数据`);
  }

  return { handleExport };
}
