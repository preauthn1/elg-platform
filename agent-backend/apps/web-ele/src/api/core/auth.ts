import { baseRequestClient, requestClient } from '#/api/request';

export namespace AuthApi {
  /** 登入接口參數 */
  export interface LoginParams {
    action?: string;
    domain?: string;
    mobile_auth?: boolean;
    password?: string;
    secret?: string;
    username?: string;
  }

  /** 真實 API 統一回應格式 */
  export interface ApiResult<T = any> {
    code: number;
    data: T;
    message: string;
  }

  /** 登入成功後 data 內可能出現的欄位 */
  export interface LoginData {
    [key: string]: any;
    action?: string;
    id?: number;
    is_mobile_validate_required?: null | number;
    is_payment_password_required?: null | number;
    is_update_password_required?: null | number;
    mobile?: string;
    username?: string;
  }
}

/**
 * 登入：回傳完整 { code, data, message } 以便依 action 分支
 */
export async function loginApi(data: AuthApi.LoginParams) {
  return requestClient.post<AuthApi.ApiResult<AuthApi.LoginData>>(
    '/api/login',
    data,
    { responseReturn: 'body' },
  );
}

/**
 * 退出登入：清除 gateway session
 */
export async function logoutApi() {
  return baseRequestClient.post('/logout');
}

/** 取得網域設定。 */
export async function domainInfoApi(domain: string) {
  return requestClient.post(
    '/api/domain/info',
    { domain, setting_group: 5000 },
    { responseReturn: 'body' },
  );
}

/**
 * 取得語系清單
 */
export async function languagesApi() {
  return requestClient.post('/api/languages', {}, { responseReturn: 'body' });
}

/** 取得使用者權限碼。 */
export async function getAccessCodesApi(): Promise<string[]> {
  const map = await requestClient.post<Record<string, number>>(
    '/agent/api/agent-permission/code-map',
    {},
  );
  return Object.entries(map ?? {})
    .filter(([, v]) => Number(v) === 1)
    .map(([k]) => k);
}
