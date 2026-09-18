import { request } from '../request';

export function fetchContractList(params: {
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
}) {
  return request({ url: '/contract/list', params });
}

export function fetchContractDetail(contractNo: string) {
  return request({ url: '/contract/detail', params: { contractNo } });
}

export function fetchContractCreate(data: Api.Contract.ContractCreateParams) {
  return request<{ contractNo: string; guid: number }>({
    url: '/contract/create',
    method: 'post',
    data
  });
}

export function fetchContractUpdate(data: Api.Contract.ContractUpdateParams) {
  return request({
    url: '/contract/update',
    method: 'post',
    data
  });
}

export function fetchContractDelete(contractNo: string) {
  return request({
    url: '/contract/delete',
    method: 'post',
    data: { contractNo }
  });
}

export function fetchContractSubmit(contractNo: string, workflowCode = '') {
  return request({
    url: '/contract/submit',
    method: 'post',
    data: { contractNo, workflowCode }
  });
}

export function fetchContractApprove(data: { taskId: number; action: 'APPROVE' | 'REJECT'; opinion?: string }) {
  return request({
    url: '/contract/approve',
    method: 'post',
    data
  });
}

export function fetchContractStats(params?: Record<string, any>) {
  return request({ url: '/contract/stats', params });
}

export function fetchContractOptions() {
  return request({ url: '/contract/options' });
}

export function fetchContractPendingTasks(params?: { page?: number; pageSize?: number }) {
  return request({ url: '/contract/pendingTasks', params });
}

export function fetchContractDoneTasks(params?: { page?: number; pageSize?: number }) {
  return request({ url: '/contract/doneTasks', params });
}

export function fetchContractMyContracts(params?: { page?: number; pageSize?: number }) {
  return request({ url: '/contract/myContracts', params });
}

export function fetchContractFlowDetail(instanceId: number) {
  return request({ url: '/contract/flowDetail', params: { instanceId } });
}

export function fetchContractUploadDocument(data: {
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

  return request<Api.Contract.ContractDocument>({
    url: '/contract/uploadDocument',
    method: 'post',
    data: formData
  });
}

export function fetchContractDeleteDocument(docId: number) {
  return request({
    url: '/contract/deleteDocument',
    method: 'post',
    data: { docId }
  });
}

export function getContractDownloadUrl(docId: number) {
  return `/contract/downloadDocument/${docId}`;
}

/**
 * 下载合同文档（使用项目统一的 request 实例，自动携带 Authorization 和 Vite 代理前缀）
 * 后端返回二进制文件流，需用 responseType: 'blob' 接收
 */
export async function fetchContractDownloadDocument(
  docId: number
): Promise<{ blob: Blob; filename: string }> {
  const { data, error, response } = await request<any, 'blob'>({
    url: `/contract/downloadDocument/${docId}`,
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
