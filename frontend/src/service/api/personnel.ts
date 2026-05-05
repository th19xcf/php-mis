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
    data
  });
}

export function fetchBatchUpdateTrain(data: Api.Train.TrainBatchUpdateParams) {
  return request<null>({
    url: '/train/batchUpdate',
    method: 'post',
    data
  });
}

export function fetchDeleteTrain(guids: string[]) {
  return request<null>({
    url: '/train/delete',
    method: 'post',
    data: { guids }
  });
}

export function fetchTransferTrain(data: Api.Train.TrainTransferParams) {
  return request<null>({
    url: '/train/transfer',
    method: 'post',
    data
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
    data
  });
}

export function fetchBatchUpdateEmployee(data: Api.Employee.EmployeeBatchUpdateParams) {
  return request<null>({
    url: '/employee/batchUpdate',
    method: 'post',
    data
  });
}

export function fetchDeleteEmployee(guids: string[]) {
  return request<null>({
    url: '/employee/delete',
    method: 'post',
    data: { guids }
  });
}

export function fetchEmployeeOptions() {
  return request<Api.Employee.EmployeeOptions>({
    url: '/employee/options'
  });
}
