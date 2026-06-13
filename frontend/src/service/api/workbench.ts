import { request } from '../request';

// 写接口（add/update/delete/batch/submit/upkeep/reset/import）需在
// config 上设置 `skipAuthError: true`，避免后端把业务校验错误复用到
// logout 业务码时误触发 authStore.resetStore() → 跳登录页。详见
// service/request/index.ts 的 onBackendFail 实现。
export function fetchWorkbenchPage(functionCode: string) {
  return request<{ meta: Api.Workbench.PageMeta }>({ url: `/workbench/page/${encodeURIComponent(functionCode)}` });
}

export function fetchWorkbenchQuery(functionCode: string, data: Api.Workbench.QueryPayload) {
  return request<Api.Common.PaginatingQueryRecord<Api.Workbench.QueryRecord>>({
    url: `/workbench/query/${encodeURIComponent(functionCode)}`,
    method: 'post',
    data
  });
}

/**
 * 分页查询工作台数据
 * @param functionCode 功能编码
 * @param params 分页参数
 * @returns 分页数据
 */
export function fetchWorkbenchPageData(
  functionCode: string,
  params: {
    current: number;
    size: number;
    offset?: number;
    fetchTotal?: boolean;
    drillCondition?: string;
    filters?: any[];
  }
) {
  return request<{
    records: Api.Workbench.QueryRecord[];
    current: number;
    size: number;
    offset?: number;
    total: number;
    hasMore: boolean;
  }>({
    url: `/workbench/queryPaged/${encodeURIComponent(functionCode)}`,
    method: 'post',
    data: params
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
    },
    // 导入接口的业务校验错误（如 "Data too long for column..."）不应
    // 被后端复用的 logout 业务码误判为会话失效。拦截器读此标记后
    // 跳过强制登出，错误交由 useWorkbenchImport 在弹窗内提示用户。
    // 真过期场景（expiredTokenCodes）仍按 token 刷新流程处理。
    skipAuthError: true
  });
}

export function fetchAddFields(functionCode: string) {
  return request<Api.Workbench.AddFieldsData>({
    url: `/workbench/add-fields/${encodeURIComponent(functionCode)}`
  });
}

export function fetchDetailFields(functionCode: string) {
  return request<Api.Workbench.DetailFieldsData>({
    url: `/workbench/detail-fields/${encodeURIComponent(functionCode)}`
  });
}

export function fetchBatchEditFields(functionCode: string) {
  return request<Api.Workbench.AddFieldsData>({
    url: `/workbench/batch-edit-fields/${encodeURIComponent(functionCode)}`
  });
}

export function addRow(functionCode: string, data: Record<string, any>) {
  return request<Api.Workbench.AddResult>({
    url: `/workbench/add-row/${encodeURIComponent(functionCode)}`,
    method: 'post',
    data,
    skipAuthError: true
  });
}

export function deleteRow(functionCode: string, keys: (string | number)[]) {
  return request<Api.Workbench.DeleteResult>({
    url: `/workbench/delete-row/${encodeURIComponent(functionCode)}`,
    method: 'post',
    data: { keys },
    skipAuthError: true
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
    data: { keys, data },
    skipAuthError: true
  });
}

export function batchUpdateRow(functionCode: string, keys: (string | number)[], data: Record<string, any>) {
  return request<Api.Workbench.UpdateResult>({
    url: `/workbench/batch-update-row/${encodeURIComponent(functionCode)}`,
    method: 'post',
    data: { keys, data },
    skipAuthError: true
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

// 表级修改提交
export function submitTableEdit(functionCode: string, data: Api.Workbench.QueryRecord[]) {
  return request<Api.Workbench.UpdateResult>({
    url: `/workbench/table-edit/${encodeURIComponent(functionCode)}`,
    method: 'post',
    data,
    skipAuthError: true
  });
}

// 获取调试信息
export function fetchWorkbenchDebug(functionCode: string, data: Api.Workbench.QueryPayload) {
  return request<Api.Workbench.DebugData>({
    url: `/workbench/debug/${encodeURIComponent(functionCode)}`,
    method: 'post',
    data
  });
}

// 执行数据整理
export function executeUpkeep(functionCode: string) {
  return request<{
    success: boolean;
    message: string;
  }>({
    url: `/workbench/upkeep/${encodeURIComponent(functionCode)}`,
    method: 'post',
    skipAuthError: true
  });
}

/**
 * 图形钻取
 * @param functionCode 功能编码
 * @param payload 钻取参数 [ { 钻取级别 }, { 钻取选项 }, { ...数据点 } ]
 */
export function fetchWorkbenchChartDrill(functionCode: string, payload: any[]) {
  return request<{
    charts: any[];
    drillLevel: number;
    message: string;
  }>({
    url: `/workbench/chart-drill/${encodeURIComponent(functionCode)}`,
    method: 'post',
    data: payload
  });
}

/**
 * 重置图形钻取状态
 */
export function resetWorkbenchChartDrill(functionCode: string) {
  return request<{ message: string }>({
    url: `/workbench/chart-drill-reset/${encodeURIComponent(functionCode)}`,
    method: 'post',
    skipAuthError: true
  });
}
