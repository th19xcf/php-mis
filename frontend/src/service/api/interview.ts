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
    data,
    skipAuthError: true
  });
}

export function fetchUpdateInterview(data: Api.Interview.InterviewUpdateParams) {
  return request<null>({
    url: '/interview/update',
    method: 'post',
    data,
    skipAuthError: true
  });
}

export function fetchDeleteInterview(guids: string[]) {
  return request<null>({
    url: '/interview/delete',
    method: 'post',
    data: { guids },
    skipAuthError: true
  });
}

export function fetchTransferInterview(data: Api.Interview.InterviewTransferParams) {
  return request<null>({
    url: '/interview/transfer',
    method: 'post',
    data,
    skipAuthError: true
  });
}

export function fetchInterviewOptions() {
  return request<Api.Interview.InterviewOptions>({
    url: '/interview/options'
  });
}

/** 调试：获取面试树加载的完整 SQL + 分段耗时（需 debugSql 权限） */
export function fetchInterviewDebugTree() {
  return request<{
    sql: string;
    locationAuthzCondition: string;
    userLocationAuth: string;
    deptAuthzCondition: string;
    rowCount: number;
    treeNodeCount: number;
    timing: {
      contextBuildMs: number;
      queryMs: number;
      buildTreeMs: number;
      totalMs: number;
    };
  }>({
    url: '/interview/debug-tree'
  });
}
