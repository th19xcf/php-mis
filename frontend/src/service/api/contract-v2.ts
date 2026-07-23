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
