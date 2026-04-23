const DEFAULT_SUCCESS_CODE = '0000';
const DEFAULT_LOGOUT_CODES = ['8888', '8889'];
const DEFAULT_MODAL_LOGOUT_CODES = ['7777', '7778'];
const DEFAULT_EXPIRED_TOKEN_CODES = ['9999', '9998', '3333'];

function normalizeCode(code: string) {
  return code.trim();
}

function parseCodeList(value: string | undefined, fallback: string[]) {
  const raw = value?.trim();

  if (!raw) {
    return fallback;
  }

  const unique = new Set(raw.split(',').map(normalizeCode).filter(Boolean));

  return unique.size > 0 ? [...unique] : fallback;
}

export const SERVICE_CODE_CONFIG = {
  successCode: normalizeCode(import.meta.env.VITE_SERVICE_SUCCESS_CODE || DEFAULT_SUCCESS_CODE),
  logoutCodes: parseCodeList(import.meta.env.VITE_SERVICE_LOGOUT_CODES, DEFAULT_LOGOUT_CODES),
  modalLogoutCodes: parseCodeList(import.meta.env.VITE_SERVICE_MODAL_LOGOUT_CODES, DEFAULT_MODAL_LOGOUT_CODES),
  expiredTokenCodes: parseCodeList(import.meta.env.VITE_SERVICE_EXPIRED_TOKEN_CODES, DEFAULT_EXPIRED_TOKEN_CODES)
} as const;
