import { request } from '../request';

export function fetchWorkbenchPage(functionCode: string) {
  return request<Api.Workbench.PageData>({ url: `/workbench/page/${encodeURIComponent(functionCode)}` });
}

export function fetchWorkbenchQuery(functionCode: string, data: Api.Workbench.QueryPayload) {
  return request<Api.Common.PaginatingQueryRecord<Api.Workbench.QueryRecord>>({
    url: `/workbench/query/${encodeURIComponent(functionCode)}`,
    method: 'post',
    data
  });
}

export function fetchWorkbenchDrill(functionCode: string, data: any) {
  return request<Api.Workbench.DrillData>({
    url: `/workbench/drill/${encodeURIComponent(functionCode)}`,
    method: 'post',
    data
  });
}

export function fetchImportColumns(functionCode: string) {
  return request<Api.Workbench.ImportColumnsData>({
    url: `/workbench/import-columns/${encodeURIComponent(functionCode)}`
  });
}

export function importData(functionCode: string, data: any[]) {
  return request<Api.Workbench.ImportResult>({
    url: `/workbench/import/${encodeURIComponent(functionCode)}`,
    method: 'post',
    data: {
      data,
      config: {}
    }
  });
}

export function fetchAddFields(functionCode: string) {
  return request<Api.Workbench.AddFieldsData>({
    url: `/workbench/add-fields/${encodeURIComponent(functionCode)}`
  });
}

export function addRow(functionCode: string, data: Record<string, any>) {
  return request<Api.Workbench.AddResult>({
    url: `/workbench/add-row/${encodeURIComponent(functionCode)}`,
    method: 'post',
    data
  });
}

export function deleteRow(functionCode: string, keys: (string | number)[]) {
  return request<Api.Workbench.DeleteResult>({
    url: `/workbench/delete-row/${encodeURIComponent(functionCode)}`,
    method: 'post',
    data: { keys }
  });
}

export function fetchUpdateFields(functionCode: string, keys: (string | number)[]) {
  return request<Api.Workbench.UpdateFieldsResult>({
    url: `/workbench/update-fields/${encodeURIComponent(functionCode)}`,
    method: 'post',
    data: { keys }
  });
}

export function updateRow(functionCode: string, keys: (string | number)[], data: Record<string, any>) {
  return request<Api.Workbench.UpdateResult>({
    url: `/workbench/update-row/${encodeURIComponent(functionCode)}`,
    method: 'post',
    data: { keys, data }
  });
}

export function fetchPopupData(functionCode: string, objectName: string) {
  return request<Api.Workbench.PopupData>({
    url: `/workbench/popup-data/${encodeURIComponent(functionCode)}`,
    params: { objectName }
  });
}

// 懒加载级联选择 API
export function fetchPopupLevels(functionCode: string, objectName: string) {
  return request<Api.Workbench.PopupLevelsData>({
    url: `/workbench/popup-levels/${encodeURIComponent(functionCode)}`,
    params: { objectName }
  });
}

export function fetchPopupLevelData(functionCode: string, objectName: string, level: number, parentCode: string = '') {
  return request<Api.Workbench.PopupLevelData>({
    url: `/workbench/popup-level-data/${encodeURIComponent(functionCode)}`,
    params: { objectName, level, parentCode }
  });
}
