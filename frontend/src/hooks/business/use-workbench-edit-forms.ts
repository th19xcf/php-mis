import { ref } from 'vue';
import type { Ref } from 'vue';
import type { GridApi } from 'ag-grid-community';

import { addRow, batchUpdateRow, fetchAddFields, fetchUpdateFields, updateRow } from '@/service/api/workbench';

interface UseWorkbenchEditFormsOptions {
  gridApi: Ref<GridApi<Api.Workbench.QueryRecord> | null>;
  getFunctionCode: () => string;
  refreshAfterMutation: () => void;
  notify: (type: 'success' | 'error' | 'warning' | 'info', message: string) => void;
}

function normalizeFields(fields: any[]) {
  return fields.map((field: any) => ({
    fieldName: field.fieldName,
    columnName: field.columnName,
    fieldType: field.fieldType,
    editorType: field.editorType,
    editorParams: field.editorParams,
    required: field.required,
    readonly: field.readonly,
    objectName: field.objectName,
    objectOptions: field.objectOptions
  }));
}

/**
 * 从请求错误中提取可读的提示信息
 * 优先取后端业务返回的 msg 字段；若无则回退到 AxiosError.message；再无则使用默认提示
 */
function extractErrorMessage(error: any, fallback: string): string {
  const backendMsg = error?.response?.data?.msg;
  if (typeof backendMsg === 'string' && backendMsg.trim() !== '') {
    return backendMsg;
  }
  return error?.message || fallback;
}

function getSelectedKeyValues(
  gridApi: GridApi<Api.Workbench.QueryRecord> | null,
  mode: 'single' | 'multiple'
): (string | number)[] | null {
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

  if (mode === 'single' && keyValues.length !== 1) {
    return null;
  }

  return keyValues.length > 0 ? keyValues : null;
}

export function useWorkbenchEditForms(options: UseWorkbenchEditFormsOptions) {
  const addVisible = ref(false);
  const addLoading = ref(false);
  const addFormData = ref<Record<string, any>>({});
  const addFormFields = ref<any[]>([]);
  const addError = ref('');
  const addSuccess = ref('');

  const updateVisible = ref(false);
  const updateLoading = ref(false);
  const updateError = ref('');
  const updateSuccess = ref('');
  const updateFormData = ref<Record<string, any>>({});
  const updateFormFields = ref<any[]>([]);

  const batchUpdateVisible = ref(false);
  const batchUpdateLoading = ref(false);
  const batchUpdateError = ref('');
  const batchUpdateSuccess = ref('');
  const batchUpdateFormData = ref<Record<string, any>>({});
  const batchUpdateFormFields = ref<any[]>([]);

  async function handleOpenAdd() {
    addVisible.value = true;
    addLoading.value = true;
    addError.value = '';
    addSuccess.value = '';
    addFormData.value = {};
    addFormFields.value = [];

    try {
      const functionCode = options.getFunctionCode();
      if (!functionCode) {
        addError.value = '功能编码不能为空';
        options.notify('error', addError.value);
        return;
      }

      const { data, error } = await fetchAddFields(functionCode);
      if (error) {
        addError.value = '获取新增字段配置失败';
        options.notify('error', addError.value);
        return;
      }

      addFormFields.value = data.fields || [];
      addFormFields.value.forEach((field: any) => {
        if (field.fieldType === '日期') {
          addFormData.value[field.fieldName] = field.defaultValue || null;
        } else {
          addFormData.value[field.fieldName] = field.defaultValue || '';
        }
      });
    } catch {
      addError.value = '获取新增字段配置失败';
      options.notify('error', addError.value);
    } finally {
      addLoading.value = false;
    }
  }

  async function confirmAdd() {
    addLoading.value = true;
    addError.value = '';
    addSuccess.value = '';

    try {
      const functionCode = options.getFunctionCode();
      if (!functionCode) {
        addError.value = '功能编码不能为空';
        options.notify('error', addError.value);
        return;
      }

      const { data, error } = await addRow(functionCode, addFormData.value);
      if (error) {
        addError.value = extractErrorMessage(error, '新增失败');
        options.notify('error', addError.value);
        return;
      }

      if (data.success) {
        addSuccess.value = data.message || '新增成功';
        options.notify('success', addSuccess.value);
        setTimeout(() => {
          addVisible.value = false;
          options.refreshAfterMutation();
        }, 1500);
      } else {
        addError.value = data.message || '新增失败';
        options.notify('error', addError.value);
      }
    } catch (e: any) {
      addError.value = extractErrorMessage(e, '新增失败');
      options.notify('error', addError.value);
    } finally {
      addLoading.value = false;
    }
  }

  async function handleOpenUpdate() {
    const functionCode = options.getFunctionCode();
    if (!functionCode) {
      options.notify('error', '功能编码不能为空');
      return;
    }

    const keyValues = getSelectedKeyValues(options.gridApi.value, 'single');
    if (!keyValues) {
      const selectedRows = options.gridApi.value?.getSelectedRows() || [];
      if (selectedRows.length === 0) {
        options.notify('warning', '请先选择要修改的记录');
      } else if (selectedRows.length > 1) {
        options.notify('warning', '修改操作只能选择一条记录');
      } else {
        options.notify('error', '无法获取记录主键值，请联系管理员');
      }
      return;
    }

    updateVisible.value = true;
    updateLoading.value = true;
    updateError.value = '';
    updateSuccess.value = '';
    updateFormData.value = {};
    updateFormFields.value = [];

    try {
      const { data, error } = await fetchUpdateFields(functionCode, keyValues);
      if (error) {
        updateError.value = error.message || '获取修改信息失败';
        options.notify('error', updateError.value);
        return;
      }

      if (data && data.fields) {
        updateFormFields.value = normalizeFields(data.fields);
        if (data.currentData) {
          updateFormData.value = { ...data.currentData };
        }
      } else {
        updateError.value = '未获取到字段配置';
        options.notify('error', updateError.value);
      }
    } catch (e: any) {
      updateError.value = e.message || '获取修改信息失败';
      options.notify('error', updateError.value);
    } finally {
      updateLoading.value = false;
    }
  }

  async function confirmUpdate() {
    updateLoading.value = true;
    updateError.value = '';
    updateSuccess.value = '';

    try {
      const functionCode = options.getFunctionCode();
      if (!functionCode) {
        updateError.value = '功能编码不能为空';
        options.notify('error', updateError.value);
        return;
      }

      const keyValues = getSelectedKeyValues(options.gridApi.value, 'single');
      if (!keyValues) {
        updateError.value = '未选择要修改的记录';
        options.notify('error', updateError.value);
        return;
      }

      const { data, error } = await updateRow(functionCode, keyValues, updateFormData.value);
      if (error) {
        updateError.value = extractErrorMessage(error, '修改失败');
        options.notify('error', updateError.value);
        return;
      }

      if (data.success) {
        updateSuccess.value = data.message || '修改成功';
        options.notify('success', updateSuccess.value);
        setTimeout(() => {
          updateVisible.value = false;
          options.refreshAfterMutation();
        }, 1500);
      } else {
        updateError.value = data.message || '修改失败';
        options.notify('error', updateError.value);
      }
    } catch (e: any) {
      updateError.value = extractErrorMessage(e, '修改失败');
      options.notify('error', updateError.value);
    } finally {
      updateLoading.value = false;
    }
  }

  async function handleOpenBatchUpdate() {
    const functionCode = options.getFunctionCode();
    if (!functionCode) {
      options.notify('error', '功能编码不能为空');
      return;
    }

    const keyValues = getSelectedKeyValues(options.gridApi.value, 'multiple');
    if (!keyValues) {
      options.notify('warning', '请先选择要修改的记录');
      return;
    }

    batchUpdateVisible.value = true;
    batchUpdateLoading.value = true;
    batchUpdateError.value = '';
    batchUpdateSuccess.value = '';
    batchUpdateFormData.value = {};
    batchUpdateFormFields.value = [];

    try {
      const { data, error } = await fetchUpdateFields(functionCode, keyValues);
      if (error) {
        batchUpdateError.value = error.message || '获取修改信息失败';
        options.notify('error', batchUpdateError.value);
        return;
      }

      if (data && data.fields) {
        batchUpdateFormFields.value = normalizeFields(data.fields);
      } else {
        batchUpdateError.value = '未获取到字段配置';
        options.notify('error', batchUpdateError.value);
      }
    } catch (e: any) {
      batchUpdateError.value = e.message || '获取修改信息失败';
      options.notify('error', batchUpdateError.value);
    } finally {
      batchUpdateLoading.value = false;
    }
  }

  async function confirmBatchUpdate() {
    batchUpdateLoading.value = true;
    batchUpdateError.value = '';
    batchUpdateSuccess.value = '';

    try {
      const functionCode = options.getFunctionCode();
      if (!functionCode) {
        batchUpdateError.value = '功能编码不能为空';
        options.notify('error', batchUpdateError.value);
        return;
      }

      const keyValues = getSelectedKeyValues(options.gridApi.value, 'multiple');
      if (!keyValues) {
        batchUpdateError.value = '未选择要修改的记录';
        options.notify('error', batchUpdateError.value);
        return;
      }

      const { data, error } = await batchUpdateRow(functionCode, keyValues, batchUpdateFormData.value);
      if (error) {
        batchUpdateError.value = extractErrorMessage(error, '批量修改失败');
        options.notify('error', batchUpdateError.value);
        return;
      }

      if (data.success) {
        batchUpdateSuccess.value = data.message || '批量修改成功';
        options.notify('success', batchUpdateSuccess.value);
        setTimeout(() => {
          batchUpdateVisible.value = false;
          options.refreshAfterMutation();
        }, 1500);
      } else {
        batchUpdateError.value = data.message || '批量修改失败';
        options.notify('error', batchUpdateError.value);
      }
    } catch (e: any) {
      batchUpdateError.value = extractErrorMessage(e, '批量修改失败');
      options.notify('error', batchUpdateError.value);
    } finally {
      batchUpdateLoading.value = false;
    }
  }

  function setEditFieldValue(fieldName: string, value: string) {
    addFormData.value[fieldName] = value;
    updateFormData.value[fieldName] = value;
    batchUpdateFormData.value[fieldName] = value;
  }

  return {
    addVisible,
    addLoading,
    addFormData,
    addFormFields,
    addError,
    addSuccess,
    updateVisible,
    updateLoading,
    updateError,
    updateSuccess,
    updateFormData,
    updateFormFields,
    batchUpdateVisible,
    batchUpdateLoading,
    batchUpdateError,
    batchUpdateSuccess,
    batchUpdateFormData,
    batchUpdateFormFields,
    handleOpenAdd,
    confirmAdd,
    handleOpenUpdate,
    confirmUpdate,
    handleOpenBatchUpdate,
    confirmBatchUpdate,
    setEditFieldValue
  };
}
