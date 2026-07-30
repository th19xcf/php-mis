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
}) {
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

export function fetchContractV2Submit(contractNo: string, workflowCode = 'contract_approval') {
  return request({
    url: '/contractV2/submit',
    method: 'post',
    data: { contractNo, workflowCode }
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
    responseType: 'blob'
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
