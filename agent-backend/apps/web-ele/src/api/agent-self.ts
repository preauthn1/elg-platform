import type { Pagination } from '#/api/member';
import type { AutoDepositRow, OrderRow } from '#/api/order';
import type { WithdrawalListRow } from '#/api/withdrawal';

import { requestClient } from '#/api/request';

/**
 * 代理自助（agent_self_deposit / agent_self_withdrawal）模組 API。
 * 存款列表重用 order 端點（api/order/list、api/order/getAutoDeposit），固定帶 search_agent=1。 */

export interface AgentSelfDepositOrderParams {
  agent_username?: string;
  cash_flow_id?: number | string;
  end_time?: string;
  member_username?: string;
  order_no?: string;
  page?: number;
  per_page?: number;
  start_time?: string;
  status?: number | string;
  third_party_order_no?: string;
  type?: number | string;
  username?: string;
}

/** 代理自助存款訂單列表 */
export async function getAgentSelfDepositOrderListApi(
  params: AgentSelfDepositOrderParams,
) {
  return requestClient.post<{
    code: number;
    data: OrderRow[];
    message: string;
    pagination?: Pagination;
    total_summary?: {
      total_amount?: number | string;
      total_point?: number | string;
    };
  }>(
    '/api/order/list',
    { page: 1, per_page: 20, search_agent: 1, ...params },
    { responseReturn: 'body' },
  );
}

export interface AgentSelfBankDepositParams {
  account_no?: string;
  end_time?: string;
  id?: number | string;
  member_username?: string;
  order_no?: string;
  page?: number;
  per_page?: number;
  start_time?: string;
  status?: number | string;
}

/** 代理自助銀行存款 */
export async function getAgentSelfBankDepositListApi(
  params: AgentSelfBankDepositParams,
) {
  return requestClient.post<{
    code: number;
    data: AutoDepositRow[];
    message: string;
    pagination?: Pagination;
  }>(
    '/api/order/getAutoDeposit',
    { page: 1, per_page: 50, search_agent: 1, ...params },
    { responseReturn: 'body' },
  );
}

/* ── 代理自助提款 agent_self_withdrawal ── */

/**
 * 代理自助提款（審核）列表（api/withdrawal/agentList）。
 * type：1=order_no、2=member_username、3=account_no、4=transaction_no。
 * 審核流程（getOrderInfo / reviewOrder / channels / payout-process）重用 withdrawal 模組。 */
export async function getAgentSelfWithdrawalListApi(params: {
  account_no?: string;
  end_time: string;
  id?: number | string;
  member_username?: string;
  order_no?: string;
  page?: number;
  per_page?: number;
  start_time: string;
  status?: number | string;
  transaction_no?: string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: WithdrawalListRow[];
    message: string;
    pagination?: Pagination;
  }>(
    '/api/withdrawal/agentList',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

export interface WithdrawalTodayLimit {
  currentWithdrawalAmount?: number | string;
  currentWithdrawalTimes?: number | string;
  dailyWithdrawalAmount?: number | string;
  dailyWithdrawalTimes?: number | string;
}

/** 當日提款次數／金額限額 */
export async function getAgentSelfWithdrawalTodayLimitApi() {
  return requestClient.post<{
    code: number | string;
    data?: WithdrawalTodayLimit;
    message: string;
  }>('/api/withdrawal/getTodayLimit', {}, { responseReturn: 'body' });
}

/** 建立代理自助提款單 */
export async function createAgentSelfWithdrawalOrderApi(payload: {
  account_no: string;
  amount: number | string;
  bank_id: number | string;
  bsb_code?: string;
  real_name: string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: string;
    message: string;
  }>('/api/withdrawal/createOrder', payload, { responseReturn: 'body' });
}

/* ── 代理自助快速存款建單 create_fast ── */

export interface DepositFastOrderType {
  cash_flow_id?: number | string;
  choose_bank?: number | string;
  currency?: string;
  currency_id?: number | string;
  display_name?: string;
  image_path?: string;
  max_amount?: number | string;
  min_amount?: number | string;
  /** 父層 order_type_id；0=第二層、>0=第三層 */
  parent?: number | string;
  order_type_id: number | string;
  /** JSON 字串：{data:[{name,type,require,display,placeholder}]} */
  order_type_info?: null | string;
  rate?: number | string;
}

/** 快速存款支付方式列表 */
export async function getDepositFastOrderTypeListApi() {
  return requestClient.post<{
    code: number | string;
    data?: { order_type_list?: DepositFastOrderType[] };
    message: string;
  }>('/api/deposit/orderTypeList', {}, { responseReturn: 'body' });
}

/**
 * cash_flow_id 在 62 以下、但代收已由 APIs 承接的通道。
 * 62 是新舊系統的分界（大於 62 屬 APIs），這裡列的是分界以下的例外：
 * 58 是 TNGPIN（D_TNGPIN）：舊系統 API_Agent 經 target/support 套件的 TngpinPay 也送得出去，
 * 但 TngPin 代收已在 APIs 實作，代理端開分改由 APIs 送單，與會員端走同一套。
 * APIs 端有同一份名單（app/Enums/CashFlow/CashFlowScopeEnum.php 的 APIS_TAKEOVER_CASH_FLOW_IDS），
 * 建單入口會把不在名單、又在分界以下的通道擋下，兩邊要一起改。
 */
const APIS_DEPOSIT_CASH_FLOW_IDS_BELOW_BOUNDARY = new Set([58]);

/**
 * 建立快速存款單。
 * cash_flow_id>62、或在 APIS_DEPOSIT_CASH_FLOW_IDS_BELOW_BOUNDARY 名單內，走新 API agent/api/agent/deposit，
 * 否則 api/deposit/createOrderFast。
 * 回應 data.type：0 未進支付頁（message 為原因，code 998 留在原頁/999 等回調）/1 跳轉/3 html form/4 刷新/9 提示/11 QR/12 圖片。 */
export async function createDepositFastOrderApi(
  payload: Record<string, unknown>,
) {
  const cashFlowId = Number(payload.cash_flow_id ?? 0);
  const url =
    cashFlowId > 62 || APIS_DEPOSIT_CASH_FLOW_IDS_BELOW_BOUNDARY.has(cashFlowId)
      ? '/agent/api/agent/deposit'
      : '/api/deposit/createOrderFast';
  return requestClient.post<{
    code: number | string;
    data?: {
      code?: number | string;
      html?: string;
      message?: string;
      type?: number | string;
      url?: string;
    };
    message: string;
  }>(url, payload, { responseReturn: 'body' });
}

/* ── 代理自助銀行存款建單 create_bank ── */

export interface DepositBankAccount {
  account_name?: string;
  account_no?: string;
  agent_cash_flow_id?: number | string;
  bank_account_id: number | string;
  bank_code?: string;
  bank_icon?: null | string;
  bank_name?: string;
  currency?: string;
  currency_id?: number | string;
  max_amount?: number | string;
  min_amount?: number | string;
  order_type_id?: number | string;
  rate?: number | string;
}

export interface DepositBankAccountsBody {
  code: number | string;
  data?: {
    bank_accounts?: DepositBankAccount[];
    /** type=2 時的處理中訂單 */
    order?: {
      amount?: number | string;
      bank_id?: number | string;
      id?: number | string;
      order_no?: string;
      status?: number | string;
    };
    /** 1=無處理中訂單(可建單)、2=有處理中訂單(待上傳收據) */
    type?: number | string;
  };
  message: string;
}

/** 銀行存款可用帳號列表 */
export async function getDepositBankAccountsApi() {
  return requestClient.post<DepositBankAccountsBody>(
    '/api/deposit/bankAccounts',
    {},
    { responseReturn: 'body' },
  );
}

/** 建立銀行存款單 */
export async function createDepositOrderBankApi(payload: {
  amount: number | string;
  bank_account_id: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/deposit/createOrderBank',
    payload,
    { responseReturn: 'body' },
  );
}

/** 上傳銀行存款收據 */
export async function uploadDepositReceiptImageApi(payload: {
  image: string;
  order_id: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/deposit/uploadImage',
    payload,
    { responseReturn: 'body' },
  );
}

/** 放棄銀行存款單 */
export async function cancelDepositOrderApi(payload: {
  order_id: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/deposit/cancelOrder',
    payload,
    { responseReturn: 'body' },
  );
}
