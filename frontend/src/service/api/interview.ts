import { request } from '../request';

export function fetchInterviewTree(menuId?: string) {
  return request<Api.Interview.InterviewTreeNode[]>({
    url: '/interview/tree',
    params: menuId ? { menu_id: menuId } : undefined
  });
}

export function fetchInterviewDetail(guid: string) {
  return request<Api.Interview.InterviewDetail>({
    url: `/interview/detail/${guid}`
  });
}

export function fetchAddInterview(data: Api.Interview.InterviewAddParams) {
  return request<null>({
    url: '/interview/add',
    method: 'post',
    data
  });
}

export function fetchUpdateInterview(data: Api.Interview.InterviewUpdateParams) {
  return request<null>({
    url: '/interview/update',
    method: 'post',
    data
  });
}

export function fetchDeleteInterview(guids: string[]) {
  return request<null>({
    url: '/interview/delete',
    method: 'post',
    data: { guids }
  });
}

export function fetchTransferInterview(data: Api.Interview.InterviewTransferParams) {
  return request<null>({
    url: '/interview/transfer',
    method: 'post',
    data
  });
}

export function fetchInterviewOptions() {
  return request<Api.Interview.InterviewOptions>({
    url: '/interview/options'
  });
}
