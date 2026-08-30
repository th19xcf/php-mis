import { request } from '../request';

/** 主档列表树（与 2015 邀约树同结构：属地 → 渠道类型 → 招聘渠道 → 人员叶子） */
export function fetchPersonTree(extra: Partial<Record<string, any>> = {}) {
  return request<Api.Person.PersonTreeNode[]>({
    url: '/person/tree',
    ...extra
  });
}

/** 主档详情（按 GUID 定位，返回工作台格式字段） */
export function fetchPersonDetail(guid: string | number) {
  return request<Api.Person.PersonDetail>({
    url: `/person/detail/${guid}`
  });
}

/** 新增主档（走通用工作台审计：写入 def_function='def_hr_person' 的 add 日志） */
export function fetchAddPerson(data: Api.Person.PersonAddParams) {
  return request<null>({
    url: '/person/add',
    method: 'post',
    data,
    skipAuthError: true
  });
}

/** 修改主档（GUID 定位，其余字段按需传入） */
export function fetchUpdatePerson(data: Api.Person.PersonUpdateParams) {
  return request<null>({
    url: '/person/update',
    method: 'post',
    data,
    skipAuthError: true
  });
}

/** 删除主档（批量软删，设置有效标识='0' / 删除标识='1'，记录审计） */
export function fetchDeletePerson(guids: Array<string | number>) {
  return request<null>({
    url: '/person/delete',
    method: 'post',
    data: { guids },
    skipAuthError: true
  });
}

/** 主档下拉选项（属地 / 招聘渠道 / 渠道类型 / 性别 / 学历） */
export function fetchPersonOptions(extra: Partial<Record<string, any>> = {}) {
  return request<Api.Person.PersonOptions>({
    url: '/person/options',
    ...extra
  });
}

/** 重档合并：源主档作废，下游邀约/面试/培训/在职全部人员编码改写为目标编码 */
export function fetchMergePerson(data: Api.Person.PersonMergeParams) {
  return request<null>({
    url: '/person/merge',
    method: 'post',
    data,
    skipAuthError: true
  });
}

/** 主档查重：新增 / 合并前核对是否已存在同身份档案 */
export function fetchPersonDedup(data: Api.Person.PersonDedupParams) {
  return request<Api.Person.PersonDedupResult>({
    url: '/person/dedup',
    method: 'post',
    data,
    skipAuthError: true
  });
}
