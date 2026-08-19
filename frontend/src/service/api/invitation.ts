import { request } from '../request';

export function fetchInvitationTree(menuId?: string) {
  return request<Api.Invitation.InvitationTreeNode[]>({
    url: '/invitation/tree',
    params: menuId ? { menu_id: menuId } : undefined
  });
}

/** 调试：获取邀约树加载的完整 SQL + 分段耗时（需 debugSql 权限） */
export function fetchDebugTree() {
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
    url: '/invitation/debug-tree'
  });
}

export function fetchInvitationDetail(guid: string) {
  return request<Api.Invitation.InvitationDetail>({
    url: `/invitation/detail/${guid}`
  });
}

export function fetchAddInvitation(data: Api.Invitation.InvitationAddParams) {
  return request<null>({
    url: '/invitation/add',
    method: 'post',
    data,
    skipAuthError: true
  });
}

export function fetchUpdateInvitation(data: Api.Invitation.InvitationUpdateParams) {
  return request<null>({
    url: '/invitation/update',
    method: 'post',
    data,
    skipAuthError: true
  });
}

export function fetchDeleteInvitation(guids: string[]) {
  return request<null>({
    url: '/invitation/delete',
    method: 'post',
    data: { guids },
    skipAuthError: true
  });
}

export function fetchTransferInvitation(data: Api.Invitation.InvitationTransferParams) {
  return request<null>({
    url: '/invitation/transfer',
    method: 'post',
    data,
    skipAuthError: true
  });
}

export function fetchInvitationOptions() {
  return request<Api.Invitation.InvitationOptions>({
    url: '/invitation/options'
  });
}
