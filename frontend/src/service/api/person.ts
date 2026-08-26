import { request } from '../request';

/** 主档列表树 */
export function fetchPersonTree() {
  return request<Api.Person.PersonTreeNode[]>({
    url: '/person/tree'
  });
}

/** 主档详情 */
export function fetchPersonDetail(code: string) {
  return request<Api.Person.PersonDetail>({
    url: `/person/detail/${code}`
  });
}

/** 修改主档 */
export function fetchUpdatePerson(data: Api.Person.PersonUpdateParams) {
  return request<null>({
    url: '/person/update',
    method: 'post',
    data,
    skipAuthError: true
  });
}

/** 重档合并 */
export function fetchMergePerson(data: Api.Person.PersonMergeParams) {
  return request<null>({
    url: '/person/merge',
    method: 'post',
    data,
    skipAuthError: true
  });
}

/** 主档查重 */
export function fetchPersonDedup(data: Api.Person.PersonDedupParams) {
  return request<Api.Person.PersonDedupResult>({
    url: '/person/dedup',
    method: 'post',
    data,
    skipAuthError: true
  });
}
