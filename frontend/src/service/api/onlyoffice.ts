import { request } from '../request';

export function fetchOnlyOfficeConfig(documentId: number) {
  return request({ url: '/onlyoffice/config', params: { documentId } });
}

export function fetchOnlyOfficeDownloadUrl(documentId: number, token: string) {
  return `/onlyoffice/download?documentId=${documentId}&token=${token}`;
}
