import { request } from '../request';

export function fetchOnlyOfficeConfig(documentId: number, uiTheme: 'default' | 'dark' | 'light' | 'auto' = 'default') {
  return request({ url: '/onlyoffice/config', params: { documentId, uiTheme } });
}

export function fetchOnlyOfficeDownloadUrl(documentId: number, token: string) {
  return `/onlyoffice/download?documentId=${documentId}&token=${token}`;
}

export function fetchDocumentTimeline(contractNo: string) {
  return request({ url: '/onlyoffice/timeline', params: { contractNo } });
}
