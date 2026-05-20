import { request } from '../request';

/** 获取部门树形结构 */
export function fetchDeptTree() {
  return request<Api.Dept.DeptTreeNode[]>({ url: '/dept/tree' });
}

/** 获取部门详情 */
export function fetchDeptDetail(guid: string) {
  return request<Api.Dept.DeptDetail>({ url: `/dept/detail/${guid}` });
}

/** 新增部门 */
export function fetchAddDept(data: Api.Dept.DeptAddParams) {
  return request<Api.Dept.DeptAddResult>({
    url: '/dept/add',
    method: 'post',
    data
  });
}

/** 修改部门 */
export function fetchUpdateDept(data: Api.Dept.DeptUpdateParams) {
  return request<Api.Dept.DeptUpdateResult>({
    url: '/dept/update',
    method: 'post',
    data
  });
}

/** 删除部门 */
export function fetchDeleteDept(guid: string) {
  return request<Api.Dept.DeptDeleteResult>({
    url: '/dept/delete',
    method: 'post',
    data: { guid }
  });
}

/** 获取部门选项列表 */
export function fetchDeptOptions() {
  return request<Api.Dept.DeptOptions>({ url: '/dept/options' });
}
