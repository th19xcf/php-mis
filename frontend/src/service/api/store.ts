import { request } from '../request';

export function fetchStoreTree(menuId?: string) {
  return request<Api.Store.StoreTreeNode[]>({
    url: '/store/tree',
    params: menuId ? { menu_id: menuId } : undefined
  });
}

export function fetchStoreDetail(guid: string) {
  return request<Api.Store.StoreDetail>({
    url: `/store/detail/${guid}`
  });
}

export function fetchAddStore(data: Api.Store.StoreAddParams) {
  return request<null>({
    url: '/store/add',
    method: 'post',
    data
  });
}

export function fetchUpdateStore(data: Api.Store.StoreUpdateParams) {
  return request<null>({
    url: '/store/update',
    method: 'post',
    data
  });
}

export function fetchDeleteStore(guids: string[]) {
  return request<null>({
    url: '/store/delete',
    method: 'post',
    data: { guids }
  });
}

export function fetchTransferStore(data: Api.Store.StoreTransferParams) {
  return request<null>({
    url: '/store/transfer',
    method: 'post',
    data
  });
}

export function fetchStoreOptions() {
  return request<Api.Store.StoreOptions>({
    url: '/store/options'
  });
}
