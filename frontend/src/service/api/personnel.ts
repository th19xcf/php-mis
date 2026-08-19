import { request } from '../request';

export function fetchTrainTree(menuId?: string) {
  return request<Api.Train.TrainTreeNode[]>({
    url: '/train/tree',
    params: menuId ? { menu_id: menuId } : undefined
  });
}

export function fetchTrainDetail(guid: string) {
  return request<Api.Train.TrainDetail>({
    url: `/train/detail/${guid}`
  });
}

export function fetchUpdateTrain(data: Api.Train.TrainUpdateParams) {
  return request<null>({
    url: '/train/update',
    method: 'post',
    data,
    skipAuthError: true
  });
}

export function fetchBatchUpdateTrain(data: Api.Train.TrainBatchUpdateParams) {
  return request<null>({
    url: '/train/batchUpdate',
    method: 'post',
    data,
    skipAuthError: true
  });
}

export function fetchDeleteTrain(guids: string[]) {
  return request<null>({
    url: '/train/delete',
    method: 'post',
    data: { guids },
    skipAuthError: true
  });
}

export function fetchTransferTrain(data: Api.Train.TrainTransferParams) {
  return request<null>({
    url: '/train/transfer',
    method: 'post',
    data,
    skipAuthError: true
  });
}

export function fetchTrainOptions() {
  return request<Api.Train.TrainOptions>({
    url: '/train/options'
  });
}

export function fetchEmployeeTree(menuId?: string) {
  return request<Api.Employee.EmployeeTreeNode[]>({
    url: '/employee/tree',
    params: menuId ? { menu_id: menuId } : undefined
  });
}

export function fetchEmployeeDetail(guid: string) {
  return request<Api.Employee.EmployeeDetail>({
    url: `/employee/detail/${guid}`
  });
}

export function fetchUpdateEmployee(data: Api.Employee.EmployeeUpdateParams) {
  return request<null>({
    url: '/employee/update',
    method: 'post',
    data,
    skipAuthError: true
  });
}

export function fetchBatchUpdateEmployee(data: Api.Employee.EmployeeBatchUpdateParams) {
  return request<null>({
    url: '/employee/batchUpdate',
    method: 'post',
    data,
    skipAuthError: true
  });
}

export function fetchDeleteEmployee(guids: string[]) {
  return request<null>({
    url: '/employee/delete',
    method: 'post',
    data: { guids },
    skipAuthError: true
  });
}

export function fetchEmployeeOptions() {
  return request<Api.Employee.EmployeeOptions>({
    url: '/employee/options'
  });
}

/** 调试：获取培训树加载的完整 SQL + 分段耗时（需 debugSql 权限） */
export function fetchTrainDebugTree() {
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
    url: '/train/debug-tree'
  });
}

/** 调试：获取员工树加载的完整 SQL + 分段耗时（需 debugSql 权限） */
export function fetchEmployeeDebugTree() {
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
    url: '/employee/debug-tree'
  });
}
