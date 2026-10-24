import { requestClient } from '#/api/request';

/** 帳戶交易明細 bank_transaction/index：帳戶下拉 + 彙總(存提合計) + 明細列表。 */

export interface BankTransactionApiBody<T = any> {
  code: number;
  data?: T;
  message: string;
  pagination?: { current: number; pages: number; total: number };
}

/** 交易明細列 */
export interface BankTransactionRow {
  account_nickname?: string;
  agent_name?: string;
  before_amount?: number | string;
  created_at?: string;
  id: number | string;
  member_name?: string;
  operator_username?: string;
  order_id?: string;
  point?: number | string;
  remark?: string;
  source?: number | string;
  [key: string]: any;
}

/** 帳戶下拉 */
export async function getBankTransactionAccountsApi(id: number | string) {
  return requestClient.post<
    Array<{ account_id: number | string; account_nickname: string }>
  >('/api/bank/dropdownAccountList', { id });
}

/** 存提合計 */
export async function getBankTransactionTotalApi(data: {
  account_id: number | string;
  end_date: string;
  source_temp: number | string;
  start_date: string;
}) {
  return requestClient.post<
    BankTransactionApiBody<
      Array<{
        total_deposit: number | string;
        total_withdrawl: number | string;
      }>
    >
  >('/api/bank/transactionTotal', data, { responseReturn: 'body' });
}

/** 交易明細 */
export async function getBankTransactionDetailApi(data: {
  account_id: number | string;
  end_date: string;
  page: number;
  per_page: number;
  source_temp: number | string;
  start_date: string;
}) {
  return requestClient.post<BankTransactionApiBody<BankTransactionRow[]>>(
    '/api/bank/transactionDetail',
    data,
    { responseReturn: 'body' },
  );
}
