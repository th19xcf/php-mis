import { computed, ref } from 'vue';
import type { Ref } from 'vue';
import type { GridApi } from 'ag-grid-community';

import { addComment, fetchCommentFields, fetchCommentList } from '@/service/api/comment';

interface UseWorkbenchCommentOptions {
  gridApi: Ref<GridApi<Api.Workbench.QueryRecord> | null>;
  getFunctionCode: () => string;
  getCommentModuleName: () => string;
  notify: (type: 'success' | 'error' | 'warning' | 'info', message: string, data?: unknown) => void;
}

export function useWorkbenchComment(options: UseWorkbenchCommentOptions) {
  const addCommentVisible = ref(false);
  const viewCommentVisible = ref(false);
  const commentFields = ref<Api.Comment.FieldInfo[]>([]);
  const commentKeyFields = ref<string>('');
  const commentList = ref<Api.Comment.CommentRecord[]>([]);
  const commentFormData = ref<Record<string, string>>({});
  const commentLoading = ref(false);
  const commentKeyFieldValues = ref<Record<string, string | number>>({});
  const commentModuleName = ref<string>('');
  const commentRemark = ref<string>('');

  const keyFieldList = computed(() => {
    return commentFields.value.filter(field => field.isKeyField);
  });

  const keyFieldCount = computed(() => {
    return Object.keys(commentKeyFieldValues.value).length;
  });

  function parseKeyFieldsFromRow(selectedRow: any, keyFieldsConfig: string): Record<string, string | number> {
    const keyFields: Record<string, string | number> = {};

    if (!keyFieldsConfig) {
      for (const field of commentFields.value) {
        if (!field.isKeyField || !field.name) continue;
        const value = selectedRow[field.sourceColumn || field.name] || selectedRow[field.name];
        if (value !== undefined && value !== null) {
          keyFields[field.name] = value;
        }
      }
      return keyFields;
    }

    const fieldPairs = keyFieldsConfig.split(';');
    for (const pair of fieldPairs) {
      const trimmedPair = pair.trim();
      if (!trimmedPair) continue;

      const [fieldName, colName] = trimmedPair.split(':');
      const actualFieldName = fieldName.trim();
      const actualColName = colName ? colName.trim() : actualFieldName;
      const value = selectedRow[actualColName];

      if (value !== undefined && value !== null) {
        keyFields[actualFieldName] = value;
      }
    }

    return keyFields;
  }

  function getSelectedRowKeyFields(keyFieldsConfig?: string): Record<string, string | number> | null {
    const selectedRows = options.gridApi.value?.getSelectedRows() || [];
    if (selectedRows.length === 0) {
      options.notify('warning', '请先选择一条记录');
      return null;
    }
    if (selectedRows.length > 1) {
      options.notify('warning', '只能选择一条记录');
      return null;
    }

    const selectedRow = selectedRows[0];
    const config = keyFieldsConfig || commentKeyFields.value;
    return parseKeyFieldsFromRow(selectedRow, config);
  }

  async function loadCommentFields() {
    const functionCode = options.getFunctionCode();
    if (!functionCode) return;

    try {
      const { data, error } = await fetchCommentFields(functionCode);
      if (data) {
        commentFields.value = data.fields || [];
        commentKeyFields.value = data.keyFields || '';
      }
      if (error) {
        // 批注字段接口错误
      }
    } catch {
      // 加载批注字段失败
    }
  }

  async function handleOpenAddComment() {
    const selectedRows = options.gridApi.value?.getSelectedRows() || [];
    if (selectedRows.length === 0) {
      options.notify('warning', '请先选择一条记录');
      return;
    }
    if (selectedRows.length > 1) {
      options.notify('warning', '只能选择一条记录');
      return;
    }
    const selectedRow = selectedRows[0];

    await loadCommentFields();
    const keyFields = getSelectedRowKeyFields();
    if (!keyFields) return;

    commentKeyFieldValues.value = keyFields;
    commentModuleName.value = options.getCommentModuleName();
    commentRemark.value = '';

    commentFormData.value = {};
    for (const field of commentFields.value) {
      if (!field.isKeyField) continue;
      const keyValue = keyFields[field.name];
      if (keyValue !== undefined) {
        commentFormData.value[field.name] = String(keyValue);
      } else if (field.sourceColumn) {
        commentFormData.value[field.name] = String(selectedRow[field.sourceColumn] || '');
      } else {
        commentFormData.value[field.name] = '';
      }
    }

    addCommentVisible.value = true;
  }

  async function handleSubmitComment() {
    const functionCode = options.getFunctionCode();
    if (!functionCode) return;

    const keyFields: Record<string, string | number> = {};
    for (const field of commentFields.value) {
      if (field.isKeyField && commentFormData.value[field.name]) {
        keyFields[field.name] = commentFormData.value[field.name];
      }
    }

    if (Object.keys(keyFields).length === 0) {
      options.notify('warning', '关键字段为空，请重新选择记录', {
        commentFormData: commentFormData.value,
        commentFields: commentFields.value
      });
      return;
    }

    if (!commentRemark.value.trim()) {
      options.notify('warning', '请填写备注说明');
      return;
    }

    const submitData: Record<string, string> = {
      备注模块: commentModuleName.value,
      备注说明: commentRemark.value
    };

    commentLoading.value = true;
    try {
      const { error } = await addComment(functionCode, {
        keyFields,
        data: submitData
      });

      if (error) {
        options.notify('error', '添加批注失败', { error, keyFields, data: submitData });
        return;
      }

      options.notify('success', '添加批注成功');
      addCommentVisible.value = false;
    } catch (err) {
      options.notify('error', '添加批注失败', { error: err, keyFields, data: submitData });
    } finally {
      commentLoading.value = false;
    }
  }

  async function loadCommentList(keyFields: Record<string, string | number>) {
    const functionCode = options.getFunctionCode();
    if (!functionCode) return;

    commentLoading.value = true;
    try {
      const { data, error } = await fetchCommentList(functionCode, { keyFields });
      if (error) {
        const backendError = (error as any)?.response?.data || error;
        const errorMsg = backendError?.msg || '获取批注列表失败';
        const errorData = backendError?.data || {};
        console.error('[ERROR] 获取批注列表失败:', {
          message: errorMsg,
          sql: errorData.sql,
          table: errorData.table,
          keyFields: errorData.keyFields || keyFields,
          fullError: error
        });
        options.notify('error', `${errorMsg}${errorData.sql ? ' (SQL: ' + errorData.sql + ')' : ''}`);
        return;
      }
      commentList.value = data?.records || [];
    } catch (err) {
      options.notify('error', '获取批注列表失败', { error: err, keyFields });
    } finally {
      commentLoading.value = false;
    }
  }

  async function handleOpenViewComment() {
    await loadCommentFields();
    const keyFields = getSelectedRowKeyFields();
    if (!keyFields) return;
    await loadCommentList(keyFields);
    viewCommentVisible.value = true;
  }

  return {
    addCommentVisible,
    viewCommentVisible,
    commentFields,
    commentList,
    commentFormData,
    commentLoading,
    commentModuleName,
    commentRemark,
    keyFieldList,
    keyFieldCount,
    handleOpenAddComment,
    handleOpenViewComment,
    handleSubmitComment
  };
}
