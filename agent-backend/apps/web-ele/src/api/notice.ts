import { requestClient } from '#/api/request';

/** Header 通知中心輪詢端點。每個端點每 10 秒輪詢一次，回 { code, data }。 */

interface NoticeResult<T> {
  code: number | string;
  data?: T;
  message: string;
}

/** 會員自助上分/洗分待處理單數 */
export interface AutoDepositWithdrawNotice {
  Deposit: number;
  hasNewOrder?: boolean | number;
  Withdraw: number;
}
export async function getAutoDepositWithdrawNoticeApi() {
  return requestClient.post<NoticeResult<AutoDepositWithdrawNotice>>(
    '/api/notice/autoDepositAndWithdraw',
    {},
    { responseReturn: 'body' },
  );
}

/** 代理自助洗分待處理單數 */
export async function getAgentSelfWithdrawalNoticeApi() {
  return requestClient.post<
    NoticeResult<{ hasNewOrder?: boolean | number; withdraw_count: number }>
  >('/api/notice/agentSelfWithdrawal', {}, { responseReturn: 'body' });
}

/** 代理自助上分待處理單數 */
export async function getAgentSelfDepositNoticeApi() {
  return requestClient.post<
    NoticeResult<{ deposit_count: number; hasNewOrder?: boolean | number }>
  >('/api/notice/agentSelfDeposit', {}, { responseReturn: 'body' });
}

/** 今日註冊人數 */
export async function getTodayRegisterNoticeApi() {
  return requestClient.post<
    NoticeResult<{ agent_username?: string; count: number; voice?: number }>
  >('/api/notice/todayRegister', {}, { responseReturn: 'body' });
}

/** 清除稽核流水審核申請數 */
export async function getRemoveFlowLimitRequestNoticeApi() {
  return requestClient.post<NoticeResult<{ count: number; voice?: number }>>(
    '/api/notice/removeFlowLimitRequest',
    {},
    { responseReturn: 'body' },
  );
}

/** 自助上分限額達標數 */
export async function getAutoDepositThresholdNoticeApi() {
  return requestClient.post<NoticeResult<{ count: number; voice?: number }>>(
    '/api/notice/autoDepositThreshold',
    {},
    { responseReturn: 'body' },
  );
}

/** 會員實名認證申請數 */
export async function getRealnameValidateNoticeApi() {
  return requestClient.post<NoticeResult<{ count: number; voice?: number }>>(
    '/api/notice/realnameValidate',
    {},
    { responseReturn: 'body' },
  );
}

/** 代理實名認證申請數 */
export async function getAgentRealNameValidateNoticeApi() {
  return requestClient.post<NoticeResult<{ count: number; voice?: number }>>(
    '/api/notice/agentRealNameValidate',
    {},
    { responseReturn: 'body' },
  );
}
