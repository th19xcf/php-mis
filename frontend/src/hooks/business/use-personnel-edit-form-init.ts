/**
 * 人员页编辑详情时，把 detail 对象按 detailFields + addFields 规则规范化成一个可直接 v-model 的表单对象。
 *
 * 规则：
 *  1. 先把 detail 的所有键复制到 form（值为 undefined/null 替换为 ''）
 *  2. 对 detailFields 中 editable=true 的字段：
 *     - 若 addField.fieldType === '日期' 且当前值是空串/无效日期（'0000-00-00'），则置为 undefined
 *     - 若值仍是 undefined/null，则置为 ''（让输入控件有占位）
 *
 * 用法：
 *   const { buildEditForm } = usePersonnelEditFormInit();
 *   editDetailForm.value = buildEditForm(employeeDetail.value, addFields.value, detailFields.value);
 */
export function usePersonnelEditFormInit() {
  function buildEditForm<T extends Record<string, any>>(
    detail: T | null | undefined,
    addFields: Array<{ columnName: string; fieldType?: string }>,
    detailFields: Array<{ columnName: string; editable?: boolean }>
  ): Record<string, any> {
    const form: Record<string, any> = {};

    if (!detail) return form;

    // 1) 复制 detail 所有字段
    Object.keys(detail).forEach((key: string) => {
      form[key] = (detail as Record<string, any>)[key] ?? '';
    });

    // 2) 按 detailFields + addFields 规范化
    detailFields.forEach(field => {
      if (!field.editable) return;
      const addField = addFields.find(f => f.columnName === field.columnName);
      if (addField?.fieldType === '日期') {
        if (!form[field.columnName] || form[field.columnName] === '' || form[field.columnName] === '0000-00-00') {
          form[field.columnName] = undefined;
        }
      } else if (form[field.columnName] === undefined || form[field.columnName] === null) {
        form[field.columnName] = '';
      }
    });

    return form;
  }

  // 让返回值满足 `useXxx` 的命名约定并支持解构使用
  return { buildEditForm } as { buildEditForm: typeof buildEditForm };
}
