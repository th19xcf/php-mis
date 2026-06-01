import { ref } from 'vue';
import { fetchAddFields, fetchDetailFields } from '@/service/api';

export interface AddField {
  columnName: string;
  fieldName: string;
  fieldType?: string;
  editable?: boolean;
  required?: boolean;
  objectOptions?: Array<{ value: string; label: string }>;
  defaultValue?: any;
}

export interface DetailField {
  columnName: string;
  fieldName: string;
  editable?: boolean;
}

export interface WorkbenchFieldOptions {
  region?: Array<{ value: string; label: string }>;
  channel?: Array<{ value: string; label: string }>;
  interviewResult?: Array<{ value: string; label: string }>;
}

export function useWorkbenchFields() {
  const addFields = ref<AddField[]>([]);
  const detailFields = ref<DetailField[]>([]);

  async function loadFields(functionCode: string | string[], additionalOptions?: WorkbenchFieldOptions) {
    const code = Array.isArray(functionCode) ? functionCode[0] : functionCode;

    try {
      const [addResult, detailResult] = await Promise.all([fetchAddFields(code), fetchDetailFields(code)]);

      if (addResult.error) {
        const errorMsg = '获取新增字段配置失败';
        const axiosError = addResult.error as any;
        const errorDetail = axiosError.response?.data?.msg || axiosError.message || JSON.stringify(addResult.error);
        const statusCode = axiosError.response?.status;
        const backendCode = axiosError.response?.data?.code;
        window.$message?.error(errorMsg);
        console.error('[ERROR]', errorMsg, {
          功能编码: code,
          HTTP状态码: statusCode,
          后端错误码: backendCode,
          错误信息: errorDetail,
          完整响应: axiosError.response?.data,
          原始错误: addResult.error
        });
        return;
      }

      if (detailResult.error) {
        const errorMsg = '获取详情字段配置失败';
        const errorDetail = detailResult.error.message || JSON.stringify(detailResult.error);
        window.$message?.error(errorMsg);
        console.error('[ERROR]', errorMsg, '- 功能编码:', code, '- 错误详情:', errorDetail, '- 完整响应:', detailResult);
        return;
      }

      if (addResult.data?.fields) {
        addFields.value = addResult.data.fields.map((field: any) => ({
          columnName: field.columnName,
          fieldName: field.fieldName,
          fieldType: field.fieldType || '文本',
          editable: field.editable !== undefined ? field.editable : true,
          required: field.required || false,
          objectOptions: field.objectOptions || [],
          defaultValue: field.defaultValue
        }));

        if (additionalOptions) {
          addFields.value.forEach(field => {
            if (additionalOptions.region && field.columnName === '属地') {
              field.objectOptions = additionalOptions.region;
            }
            if (additionalOptions.channel && field.columnName === '招聘渠道') {
              field.objectOptions = additionalOptions.channel;
            }
            if (additionalOptions.interviewResult && field.columnName === '面试结果') {
              field.objectOptions = additionalOptions.interviewResult;
            }
          });
        }
      }

      if (detailResult.data?.fields) {
        detailFields.value = detailResult.data.fields.map((field: any) => ({
          columnName: field.columnName,
          fieldName: field.fieldName,
          editable: field.editable !== undefined ? field.editable : false
        }));
      }
    } catch (error) {
      const errorMsg = '获取字段配置失败';
      window.$message?.error(errorMsg);
      console.error('[ERROR]', errorMsg, '- 功能编码:', code, '- 异常:', error);
    }
  }

  return {
    addFields,
    detailFields,
    loadFields
  };
}
