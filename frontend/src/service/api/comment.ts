import { request } from '../request';

/**
 * 获取批注字段配置
 */
export function fetchCommentFields(functionCode: string) {
  return request<Api.Comment.FieldsData>({ url: `/comment/fields/${encodeURIComponent(functionCode)}` });
}

/**
 * 获取批注列表
 */
export function fetchCommentList(functionCode: string, data: Api.Comment.ListPayload) {
  return request<Api.Comment.ListData>({
    url: `/comment/list/${encodeURIComponent(functionCode)}`,
    method: 'post',
    data
  });
}

/**
 * 添加批注
 */
export function addComment(functionCode: string, data: Api.Comment.AddPayload) {
  return request<Api.Common.SuccessResponse>({
    url: `/comment/add/${encodeURIComponent(functionCode)}`,
    method: 'post',
    data
  });
}
