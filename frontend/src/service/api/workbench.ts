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
