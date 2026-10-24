import { requestClient } from '#/api/request';

/** 代理個人自助頁（使用者右上下拉）：修改手機 / 修改＋忘記支付密碼。 */

export interface SimpleApiBody {
  code: number;
  data?: any;
  message: string;
}

/** 國碼下拉 */
export async function getCountryDropdownByDomainApi(domain: string) {
  return requestClient.post<
    Array<{
      [key: string]: any;
      country_code?: string;
      id: number | string;
      name?: string;
      rule?: string;
    }>
  >('/api/dropdownByGroupId', { domain });
}

/** 發送簡訊驗證碼 */
export async function sendValidateCodeApi(data: {
  ID?: number | string;
  mobile: string;
  type: number | string;
  username?: string;
  verify_by: number | string;
}) {
  return requestClient.post<SimpleApiBody>('/api/sendValidateCode', data, {
    responseReturn: 'body',
  });
}

/** 驗證簡訊碼 */
export async function checkValidateCodeApi(data: {
  agent_group?: number | string;
  id?: number | string;
  mobile: string;
  token: [REDACTED];
}) {
  return requestClient.post<SimpleApiBody>('/api/checkValidateCode', data, {
    responseReturn: 'body',
  });
}

/** 修改本人支付密碼 */
export async function modifyAgentPaymentPasswordApi(data: {
  id: number | string;
  new_agent_payment_password: [REDACTED];
  new_agent_payment_password_confirmation: string;
  original_agent_payment_password: [REDACTED];
  user_name: string;
}) {
  return requestClient.post<SimpleApiBody>(
    '/api/agent/modifyAgentPaymentPassword',
    data,
    { responseReturn: 'body' },
  );
}

/** 忘記支付密碼 */
export async function forgetAgentPaymentPasswordApi(data: {
  agent_payment_password: [REDACTED];
  agent_payment_password_confirmation: string;
  mobile: string;
  token: [REDACTED];
  username: string;
}) {
  return requestClient.post<SimpleApiBody>(
    '/api/agent/forgetPaymentPassword',
    data,
    { responseReturn: 'body' },
  );
}

/** 忘記登入密碼（登入頁）：以帳號＋手機簡訊驗證碼重設 */
export async function resetPasswordApi(data: {
  mobile: string;
  password: [REDACTED];
  password_confirmation: string;
  token: [REDACTED];
  username: string;
}) {
  return requestClient.post<SimpleApiBody>('/api/resetPassword', data, {
    responseReturn: 'body',
  });
}
