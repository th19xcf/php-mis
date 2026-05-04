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

export function fetchPopupData(functionCode: string, objectName: string) {
  return request<Api.Workbench.PopupData>({
    url: `/workbench/popup-data/${encodeURIComponent(functionCode)}`,
    params: { objectName }
  });
}
