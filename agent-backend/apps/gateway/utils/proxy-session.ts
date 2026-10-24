import type { EventHandlerRequest, H3Event } from 'h3';

import { useSession } from 'h3';

/** 伺服器端 session。 */
export interface ProxySession {
  // 多步驟驗證流程暫存（2FA / m2fa / mobile_auth）
  action?: string;
  balance?: number;
  cash_flow_group_id?: null | number;
  chat_group_id?: null | number;
  code?: string;
  customized_setting_group_id?: null | number;
  domain?: string;
  expire?: number;
  has_transaction?: boolean;
  hierarchy?: number;
  hierarchy_path?: string;
  host_id?: number;
  id?: number;
  is_balance_unlimited?: boolean;
  is_maintenance_whitelist?: boolean;
  mobile?: string;
  organization_id?: null | number;
  parent?: number;
  // 登入後保存的使用者資訊
  password?: string;
  role_id?: number;
  sms_gateway_group_id?: null | number;
  token?: string;
  username?: string;
  vendor_customize_group_id?: null | number;
  withdraw_confirm?: number;

  // 客服(CS)後台：直接登入後保存的 CS token 與身分（與 agent 各自獨立）
  csDomain?: string;
  csId?: number | string;
  csMemberId?: number | string;
  csMemberUsername?: string;
  csNewMessageChannel?: string;
  csRoleId?: number;
  csToken?: string;
}

// session cookie 名稱（內含真實 API token，封存加密、httpOnly）
const SESSION_NAME = 'token';

function getSessionPassword(): string {
  const pwd = [REDACTED] ?? '';
  // h3 useSession 要求密碼長度 >= 32
  return pwd.length >= 32
    ? pwd
    : [REDACTED]';
}

export async function getProxySession(event: H3Event<EventHandlerRequest>) {
  return useSession<ProxySession>(event, {
    name: SESSION_NAME,
    password: [REDACTED],
    cookie: {
      httpOnly: true,
      path: '/',
      sameSite: 'lax',
      // 正式環境（NODE_ENV=production）只在 HTTPS 下送出 cookie；
      // dev 仍走 http 故為 false。亦可用 COOKIE_SECURE=true 強制開啟。
      secure:
        process.env.COOKIE_SECURE === 'true' ||
        process.env.NODE_ENV === 'production',
    },
  });
}
