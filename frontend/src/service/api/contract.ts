import { request } from '../request';

export function fetchContractList(params: {
  page?: number;
  pageSize?: number;
  合同编号?: string;
  合同名称?: string;
  合同状态?: string;
  合同类型?: string;
}) {
  return request({ url: '/contract/list', params });
}

export function fetchContractDetail(guid: number) {
  return request({ url: `/contract/detail/${guid}` });
}

export function fetchContractCreate(data: Api.Contract.ContractCreateParams) {
  return request<{ guid: string; 合同编号: string }>({
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

export function fetchContractDelete(guid: number) {
  return request({
    url: '/contract/delete',
    method: 'post',
    data: { GUID: guid }
  });
}

export function fetchContractSubmit(guid: number) {
  return request({
    url: '/contract/submit',
    method: 'post',
    data: { GUID: guid }
  });
}

export function fetchContractApprove(data: Api.Contract.ContractApproveParams) {
  return request({
    url: '/contract/approve',
    method: 'post',
    data
  });
}

export function fetchContractReject(data: Api.Contract.ContractRejectParams) {
  return request({
    url: '/contract/reject',
    method: 'post',
    data
  });
}

export function fetchContractSign(data: Api.Contract.ContractSignParams) {
  return request({
    url: '/contract/sign',
    method: 'post',
    data
  });
}

export function fetchContractArchive(guid: number) {
  return request({
    url: '/contract/archive',
    method: 'post',
    data: { GUID: guid }
  });
}

export function fetchContractOptions() {
  return request({ url: '/contract/options' });
}

export function fetchContractStats() {
  return request({ url: '/contract/stats' });
}

export function fetchContractFlow(guid: number) {
  return request({ url: '/contract/flow', params: { guid } });
}
