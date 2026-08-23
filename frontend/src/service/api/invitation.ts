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

/**
 * 人员主档查重（邀约新增保存前确认 / 导入确认页共用）
 *
 * 入参：姓名、手机号码（必填）、身份证号（可选）
 * 返回 level：hard（证件号精确命中）| soft（姓名+手机号疑似，需人工确认）| none（无命中）
 */
export function fetchInvitationDedup(data: Api.Invitation.PersonDedupParams) {
  return request<Api.Invitation.PersonDedupResult>({
    url: '/invitation/dedup',
    method: 'post',
    data,
    skipAuthError: true
  });
}

/**
 * 邀约次数建议（新增表单实时提示，人工确认后填写，不自动计算）
 *
 * 入参：姓名、手机号码（必填）、身份证号（可选，优先精确匹配）
 * 返回：matched / invitationCount / maxCount / suggestedNext（=maxCount+1）/ latestDate 等
 */
export function fetchInvitationStats(data: Api.Invitation.InvitationStatsParams) {
  return request<Api.Invitation.InvitationStatsResult>({
    url: '/invitation/stats',
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
