import { ref } from 'vue';
import type { Ref } from 'vue';
import type { GridApi } from 'ag-grid-community';

import { deleteRow } from '@/service/api/workbench';

interface UseWorkbenchDeleteOptions {
  gridApi: Ref<GridApi<Api.Workbench.QueryRecord> | null>;
  getFunctionCode: () => string;
  refreshAfterMutation: () => void;
  notify: (type: 'success' | 'error' | 'warning' | 'info', message: string) => void;
}

function getSelectedDeleteKeys(gridApi: GridApi<Api.Workbench.QueryRecord> | null): (string | number)[] | null {
  const selectedRows = gridApi?.getSelectedRows() || [];
  if (selectedRows.length === 0) {
    return null;
  }

  let primaryKey = 'GUID';
  const columns = gridApi?.getColumns() || [];
  for (const col of columns) {
    const colDef = col.getColDef();
    const field = String(colDef.field || '');
    if (field.toUpperCase() === 'GUID' || field.toLowerCase().endsWith('_id')) {
      primaryKey = field;
      break;
    }
  }

  const keyValues = selectedRows
    .map(row => row[primaryKey])
    .filter((val): val is string | number => val !== undefined && val !== null && val !== '');

  return keyValues.length > 0 ? keyValues : null;
}

export function useWorkbenchDelete(options: UseWorkbenchDeleteOptions) {
  const deleteLoading = ref(false);

  async function handleDelete() {
    const keyValues = getSelectedDeleteKeys(options.gridApi.value);
    if (!keyValues) {
      const selectedRows = options.gridApi.value?.getSelectedRows() || [];
      if (selectedRows.length === 0) {
        options.notify('warning', '请先选择要删除的记录');
      } else {
        options.notify('error', '无法获取记录主键值，请联系管理员');
      }
      return;
    }

    const functionCode = options.getFunctionCode();
    if (!functionCode) {
      options.notify('error', '功能编码不能为空');
      return;
    }

    const confirmed = window.confirm(`确定要删除选中的 ${keyValues.length} 条记录吗？此操作不可恢复。`);
    if (!confirmed) {
      return;
    }

    deleteLoading.value = true;
    try {
      const { data, error } = await deleteRow(functionCode, keyValues);
      if (error) {
        options.notify('error', error.message || '删除失败');
        return;
      }

      if (data.success) {
        options.notify('success', data.message || `成功删除 ${data.deletedCount} 条记录`);
        options.refreshAfterMutation();
      } else {
        options.notify('error', data.message || '删除失败');
      }
    } catch (e: any) {
      options.notify('error', e.message || '删除失败');
    } finally {
      deleteLoading.value = false;
    }
  }

  return {
    deleteLoading,
    handleDelete
  };
}