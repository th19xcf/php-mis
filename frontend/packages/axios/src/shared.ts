import type { AxiosHeaderValue, AxiosResponse, InternalAxiosRequestConfig } from 'axios';
import type { ResponseType } from './type';

export function getContentType(config: InternalAxiosRequestConfig) {
  const contentType: AxiosHeaderValue = config.headers?.['Content-Type'] || 'application/json';

  return contentType;
}

/**
 * check if http status is success
 *
 * @param status
 */
export function isHttpSuccess(status: number) {
  const isSuccessCode = status >= 200 && status < 300;
  return isSuccessCode || status === 304;
}

/**
 * is response json
 *
 * @param response axios response
 */
export function isResponseJson(response: AxiosResponse) {
  const { responseType } = response.config;

  return responseType === 'json' || responseType === undefined;
}

export async function transformResponse(response: AxiosResponse) {
  const responseType: ResponseType = (response.config?.responseType as ResponseType) || 'json';
  if (responseType === 'json') return;

  if (!isHttpSuccess(response.status)) return;

  // axios 1.19+ 的 AxiosHeaderValue 类型为 string | number | boolean | string[] | AxiosHeaders | null
  // 仅 string / string[] 支持 includes，先做类型收敛建立窄化分支
  const contentType = response.headers['content-type'];
  const isJson = typeof contentType === 'string' && contentType.includes('application/json');
  if (!isJson) return;

  if (responseType === 'blob') {
    await transformBlobToJson(response);
  }

  if (responseType === 'arrayBuffer') {
    await transformArrayBufferToJson(response);
  }
}

export async function transformBlobToJson(response: AxiosResponse) {
  try {
    let data = response.data;

    if (typeof data === 'string') {
      data = JSON.parse(data);
    }

    if (Object.prototype.toString.call(data) === '[object Blob]') {
      const json = await data.text();
      data = JSON.parse(json);
    }

    response.data = data;
  } catch {}
}

export async function transformArrayBufferToJson(response: AxiosResponse) {
  try {
    let data = response.data;

    if (typeof data === 'string') {
      data = JSON.parse(data);
    }

    if (Object.prototype.toString.call(data) === '[object ArrayBuffer]') {
      const json = new TextDecoder().decode(data);
      data = JSON.parse(json);
    }

    response.data = data;
  } catch {}
}
