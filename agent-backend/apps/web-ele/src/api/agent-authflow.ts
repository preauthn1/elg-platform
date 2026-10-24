import { requestClient } from '#/api/request';

/**
 * 代理首登強制流程：變更密碼 / 設定支付密碼 / 綁定手機。
 */

export interface AuthFlowBody {
  code: number;
  data?: any;
  message: string;
}

/** 變更本人登入密碼 */
export async function changePasswordBySelfApi(data: {
  new_password: [REDACTED];
  new_password_confirmation: string;
  password: [REDACTED];
}) {
  return requestClient.post<AuthFlowBody>(
    '/api/agent/changePasswordBySelf',
    data,
    { responseReturn: 'body' },
  );
}

/** 設定本人支付密碼 */
export async function setAgentPaymentPasswordApi(data: {
  id: number | string;
  payment_password: [REDACTED];
  payment_password_confirm: string;
  username: string;
}) {
  return requestClient.post<AuthFlowBody>(
    '/api/agent/setPaymentPassword',
    data,
    { responseReturn: 'body' },
  );
}
