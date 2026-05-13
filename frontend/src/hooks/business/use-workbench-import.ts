import { computed, ref } from 'vue';
import type { Ref } from 'vue';
import type { GridApi } from 'ag-grid-community';
import * as XLSX from 'xlsx';

import { fetchImportColumns, importData } from '@/service/api/workbench';

interface UseWorkbenchImportOptions {
  gridApi: Ref<GridApi<Api.Workbench.QueryRecord> | null>;
  getFunctionCode: () => string;
  reloadPage: () => void;
  notify: (type: 'success' | 'error' | 'warning' | 'info', message: string) => void;
}

export function useWorkbenchImport(options: UseWorkbenchImportOptions) {
  const importVisible = ref(false);
  const importLoading = ref(false);
  const importFile = ref<File | null>(null);
  const importPreviewData = ref<any[]>([]);
  const importError = ref<string>('');
  const importSuccess = ref<{ count: number; message: string } | null>(null);
  const fileInputRef = ref<HTMLInputElement | null>(null);

  const importPreviewColumns = computed(() => {
    if (importPreviewData.value.length === 0) return [];

    const keys = Object.keys(importPreviewData.value[0] || {}).filter(key => key !== '_rowIndex');
    return keys.map(key => {
      const headerLength = key.length;
      const maxDataLength = importPreviewData.value.slice(0, 10).reduce((max, row) => {
        const valueLength = String(row[key] ?? '').length;
        return Math.max(max, valueLength);
      }, 0);

      const baseWidth = Math.max(headerLength * 14 + 20, 80);
      const contentWidth = Math.min(maxDataLength * 8 + 20, 300);
      const width = Math.max(baseWidth, contentWidth);

      return {
        title: key,
        key,
        width,
        minWidth: 80,
        maxWidth: 400,
        resizable: true,
        ellipsis: { tooltip: true }
      };
    });
  });

  function handleImport() {
    importVisible.value = true;
    importFile.value = null;
    importPreviewData.value = [];
    importError.value = '';
    importSuccess.value = null;
  }

  function triggerFileInput() {
    fileInputRef.value?.click();
  }

  function handleFileSelect(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (file) {
      processImportFile(file);
    }
    input.value = '';
  }

  function handleDrop(event: DragEvent) {
    event.preventDefault();
    const file = event.dataTransfer?.files[0];
    if (file) {
      processImportFile(file);
    }
  }

  async function processImportFile(file: File) {
    const validTypes = ['.xlsx', '.xls', '.csv'];
    const fileExt = file.name.substring(file.name.lastIndexOf('.')).toLowerCase();
    if (!validTypes.includes(fileExt)) {
      importError.value = '请上传 Excel 文件 (.xlsx, .xls) 或 CSV 文件 (.csv)';
      return;
    }

    importLoading.value = true;
    importError.value = '';
    importFile.value = file;

    try {
      const data = await file.arrayBuffer();
      const workbook = XLSX.read(data, { type: 'array' });
      const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
      const jsonData = XLSX.utils.sheet_to_json(firstSheet, { header: 1 }) as any[][];

      if (jsonData.length < 2) {
        importError.value = '文件数据不足，至少需要包含表头和一行数据';
        importPreviewData.value = [];
        return;
      }

      const headers = jsonData[0] as string[];
      const rows = jsonData.slice(1).filter(row => row.some(cell => cell !== undefined && cell !== ''));

      if (rows.length === 0) {
        importError.value = '未找到有效数据行';
        importPreviewData.value = [];
        return;
      }

      importPreviewData.value = rows.map((row, index) => {
        const obj: Record<string, any> = { _rowIndex: index + 2 };
        headers.forEach((header, colIndex) => {
          if (header) {
            obj[header] = row[colIndex] ?? '';
          }
        });
        return obj;
      });

      options.notify('success', `成功解析 ${rows.length} 条数据`);
    } catch (error) {
      console.error('导入文件解析失败:', error);
      importError.value = '文件解析失败，请检查文件格式是否正确';
      importPreviewData.value = [];
    } finally {
      importLoading.value = false;
    }
  }

  async function confirmImport() {
    if (importPreviewData.value.length === 0) {
      options.notify('warning', '没有可导入的数据');
      return;
    }

    const functionCode = options.getFunctionCode();
    if (!functionCode) {
      options.notify('error', '功能编码不能为空');
      return;
    }

    importLoading.value = true;
    importError.value = '';

    try {
      const { data, error } = await importData(functionCode, importPreviewData.value);

      if (error) {
        console.error('导入请求错误:', error);
        importError.value = '导入请求失败: ' + (error.message || '请稍后重试');
        return;
      }

      if (!data) {
        importError.value = '导入结果为空';
        return;
      }

      if (data.success) {
        importSuccess.value = {
          count: data.successCount,
          message: data.message
        };
        options.notify('success', data.message);

        setTimeout(() => {
          importVisible.value = false;
          options.reloadPage();
        }, 1500);
      } else if (data.errors && data.errors.length > 0) {
        const errorMessages = data.errors.slice(0, 5).map((err: any) => {
          if (err.row !== undefined && err.errors !== undefined) {
            return `第 ${err.row} 行: ${err.errors.join(', ')}`;
          }
          if (err.字段值 !== undefined) {
            return `字段值: ${err.字段值}`;
          }
          return JSON.stringify(err);
        });
        importError.value = `${data.message}\n${errorMessages.join('\n')}`;

        if (data.errors.length > 5) {
          importError.value += `\n...还有 ${data.errors.length - 5} 行错误`;
        }
      } else {
        importError.value = data.message;
      }
    } catch (error) {
      console.error('导入失败:', error);
      importError.value = '导入失败，请稍后重试';
    } finally {
      importLoading.value = false;
    }
  }

  async function downloadImportTemplate() {
    const functionCode = options.getFunctionCode();
    if (!functionCode) {
      options.notify('error', '功能编码不能为空');
      return;
    }

    let importColumns: Api.Workbench.ImportColumn[] = [];
    let apiError = false;

    try {
      const result = await fetchImportColumns(functionCode);
      if (result.error) {
        apiError = true;
      } else if (result.data?.columns) {
        importColumns = result.data.columns;
      }
    } catch {
      apiError = true;
    }

    let headers: string[] = [];
    const exampleRow: Record<string, string> = {};

    if (importColumns.length > 0) {
      headers = importColumns.map(col => col.columnName);
      importColumns.forEach(col => {
        let exampleValue = '示例数据';
        if (col.importType === '1') {
          exampleValue = '必填';
        } else if (col.checkType) {
          exampleValue = `校验:${col.checkType}`;
        }
        exampleRow[col.columnName] = exampleValue;
      });
    } else {
      const columns = options.gridApi.value?.getColumns() || [];
      const visibleColumns = columns.filter(col => {
        const colDef = col.getColDef();
        return !colDef.hide && colDef.field && colDef.field !== '';
      });

      headers = visibleColumns.map(col => {
        const colDef = col.getColDef();
        return colDef.headerName || colDef.field || '';
      });

      headers.forEach(header => {
        exampleRow[header] = '示例数据';
      });
    }

    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.json_to_sheet([exampleRow], { header: headers });
    ws['!cols'] = headers.map(header => ({ wch: Math.max(header.length * 2, 15) }));

    XLSX.utils.book_append_sheet(wb, ws, '导入模板');
    XLSX.writeFile(wb, `${functionCode}_导入模板.xlsx`);

    if (apiError) {
      options.notify('warning', '模板已下载（使用表格列作为备选）');
    } else {
      options.notify('success', '模板下载成功');
    }
  }

  function resetImportPreview() {
    importPreviewData.value = [];
    importError.value = '';
    importSuccess.value = null;
  }

  return {
    importVisible,
    importLoading,
    importPreviewData,
    importError,
    importSuccess,
    fileInputRef,
    importPreviewColumns,
    handleImport,
    triggerFileInput,
    handleFileSelect,
    handleDrop,
    confirmImport,
    downloadImportTemplate,
    resetImportPreview
  };
}