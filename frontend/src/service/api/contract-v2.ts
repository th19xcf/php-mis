import { request } from '../request';

export function fetchContractV2List(params: {
  page?: number;
  pageSize?: number;
  contractNo?: string;
  contractName?: string;
  contractType?: string;
  contractStatus?: string;
  partyA?: string;
  partyB?: string;
  signDateStart?: string;
  signDateEnd?: string;
  creator?: string;
  deptCode?: string;
  /**
   * 元数据驱动的筛选条件数组（与通用工作台 filters 协议一致）
   * 后端通过 def_query_column.可筛选 字段映射，由 WorkbenchSqlHelper 转 SQL
   */
  filters?: Api.ContractV2.FilterItem[];
}) {
  // filters 为非空数组时使用 POST，避免 GET URL 过长；其余场景保持 GET 兼容
  if (params.filters && params.filters.length > 0) {
    return request({ url: '/contractV2/list', method: 'post', data: params });
  }
  return request({ url: '/contractV2/list', params });
}

export function fetchContractV2Detail(contractNo: string) {
  return request({ url: '/contractV2/detail', params: { contractNo } });
}

export function fetchContractV2Create(data: Api.ContractV2.ContractCreateParams) {
  return request<{ contractNo: string; guid: number }>({
    url: '/contractV2/create',
    method: 'post',
    data
  });
}

export function fetchContractV2Update(data: Api.ContractV2.ContractUpdateParams) {
  return request({
    url: '/contractV2/update',
    method: 'post',
    data
  });
}

export function fetchContractV2Delete(contractNo: string) {
  return request({
    url: '/contractV2/delete',
    method: 'post',
    data: { contractNo }
  });
}

export function fetchContractV2Submit(contractNo: string) {
  return request({
    url: '/contractV2/submit',
    method: 'post',
    data: { contractNo }
  });
}

export function fetchContractV2Approve(data: { taskId: number; action: 'APPROVE' | 'REJECT'; opinion?: string }) {
  return request({
    url: '/contractV2/approve',
    method: 'post',
    data
  });
}

export function fetchContractV2Stats(params?: Record<string, any>) {
  return request({ url: '/contractV2/stats', params });
}

export function fetchContractV2Options() {
  return request({ url: '/contractV2/options' });
}

/**
 * 获取合同 V2 列定义（基于 def_function/def_query_config/def_query_column 元数据）
 *
 * 与通用工作台 PageMeta.columns 结构一致，前端收到非空 columns 时使用配置驱动表格，
 * 否则回退到 contract-v2/index.vue 中硬编码的列定义。
 *
 * @param functionCode 功能编码（如 'contract_v2_list'）
 */
export function fetchContractV2Columns(functionCode: string) {
  return request<Api.ContractV2.ColumnsResult>({
    url: '/contractV2/columns',
    params: { functionCode }
  });
}

/**
 * 获取合同 V2 查询条件元数据（基于 def_query_column.可筛选 字段生成）
 *
 * 与通用工作台 PageMeta.conditions 结构一致，前端收到非空 conditions 时
 * 渲染动态条件面板（Drawer），用户可选择字段/操作符/取值后应用筛选。
 *
 * @param functionCode 功能编码（如 'contract_v2_list'）
 */
export function fetchContractV2Conditions(functionCode: string) {
  return request<Api.ContractV2.ConditionsResult>({
    url: '/contractV2/conditions',
    params: { functionCode }
  });
}

export function fetchContractV2PendingTasks(params?: { page?: number; pageSize?: number }) {
  return request({ url: '/contractV2/pendingTasks', params });
}

export function fetchContractV2DoneTasks(params?: { page?: number; pageSize?: number }) {
  return request({ url: '/contractV2/doneTasks', params });
}

export function fetchContractV2MyContracts(params?: { page?: number; pageSize?: number }) {
  return request({ url: '/contractV2/myContracts', params });
}

export function fetchContractV2FlowDetail(instanceId: number) {
  return request({ url: '/contractV2/flowDetail', params: { instanceId } });
}

export function fetchContractV2UploadDocument(data: {
  contractNo: string;
  docType: 'MAIN' | 'APPROVAL_FORM' | 'ATTACHMENT' | 'SUPPLEMENT';
  docName?: string;
  file: File;
}) {
  const formData = new FormData();
  formData.append('contractNo', data.contractNo);
  formData.append('docType', data.docType);
  if (data.docName) formData.append('docName', data.docName);
  formData.append('file', data.file);

  return request<Api.ContractV2.ContractDocument>({
    url: '/contractV2/uploadDocument',
    method: 'post',
    data: formData
  });
}

export function fetchContractV2DeleteDocument(docId: number) {
  return request({
    url: '/contractV2/deleteDocument',
    method: 'post',
    data: { docId }
  });
}

export function getContractV2DownloadUrl(docId: number) {
  return `/contractV2/downloadDocument/${docId}`;
}

/**
 * 下载合同文档（使用项目统一的 request 实例，自动携带 Authorization 和 Vite 代理前缀）
 * 后端返回二进制文件流，需用 responseType: 'blob' 接收
 */
export async function fetchContractV2DownloadDocument(
  docId: number
): Promise<{ blob: Blob; filename: string }> {
  const { data, error, response } = await request<any, 'blob'>({
    url: `/contractV2/downloadDocument/${docId}`,
    method: 'get',
    responseType: 'blob',
    // OnlyOffice 文档下载首次加载较慢（.doc 转换 + cpolar 内网穿透），覆盖全局 30s 超时
    timeout: 120000
  });

  if (error) {
    throw error;
  }

  // 后端返回 JSON 错误时，@sa/axios 的 transformBlobToJson 会自动将 Blob 转为对象
  if (data && !(data instanceof Blob)) {
    const errorData = data as any;
    throw new Error(errorData?.msg || '下载失败');
  }

  const blob = data as Blob;
  const contentDisposition: string = response?.headers?.['content-disposition'] || '';
  let filename = `document_${docId}`;

  const utf8Match = contentDisposition.match(/filename\*\s*=\s*UTF-8''([^;]+)/i);
  const asciiMatch = contentDisposition.match(/filename\s*=\s*"([^"]+)"/i);

  if (utf8Match) {
    filename = decodeURIComponent(utf8Match[1]);
  } else if (asciiMatch) {
    filename = asciiMatch[1];
  }

  return { blob, filename };
}
