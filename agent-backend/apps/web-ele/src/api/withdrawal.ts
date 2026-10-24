import type { Pagination } from '#/api/member';
import type { OrderRequestLogRow } from '#/api/order';

import { requestClient } from '#/api/request';

/** 提款（洗分訂單審核 + 出款銀行管理）API。 */

// ---------- 提款訂單列表 ----------
export interface WithdrawalListRow {
  account_name?: string;
  account_no?: string;
  amount?: number | string;
  bank_code?: string;
  bank_name?: string;
  bank_type?: number | string;
  bsb_code?: string;
  create_at?: string;
  order_id?: number | string;
  remark?: string;
  status?: number | string;
  transaction_no?: string;
  username?: string;
  [key: string]: any;
}

/** type：1=訂單號 order_no、2=會員帳號 member_username、3=戶口 account_no、4=第三方單號 transaction_no */
export async function getWithdrawalListApi(params: {
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
    '/api/withdrawal/list',
    { page: 1, per_page: 20, ...params },
    {
      responseReturn: 'body',
    },
  );
}

/** 出款戶口下拉（標記已付款時選帳號），type=2 */
export async function getWithdrawalAccountListApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: Array<{ account_id: number | string; account_nickname: string }>;
    message: string;
  }>(
    '/api/bank/dropdownAccountList',
    { id, type: 2 },
    {
      responseReturn: 'body',
    },
  );
}

// ---------- 訂單審核詳情 ----------
export interface WithdrawalOrderInfo {
  /** 下單代理的帳號（wo.agent_id）；會員單的上級代理另在 agent_username */
  a_username?: string;
  account_name?: string;
  account_no?: string;
  agent_username?: string;
  amount?: number | string;
  bank_code?: string;
  bank_name?: string;
  bank_type?: number | string;
  bsb_code?: string;
  check_time?: string;
  created_at?: string;
  detail?: Array<Record<string, any>>;
  id?: number | string;
  member_id?: number | string;
  member_tags?: Array<{
    background_color?: string;
    name?: string;
    text_color?: string;
  }>;
  operator_name?: string;
  point?: number | string;
  rate?: number | string;
  real_name?: string;
  remark?: string;
  status?: number | string;
  tax?: number | string;
  username?: string;
  [key: string]: any;
}

export async function getWithdrawalOrderInfoApi(orderId: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: WithdrawalOrderInfo;
    message: string;
  }>(
    '/api/withdrawal/getOrderInfo',
    { order_id: orderId },
    {
      responseReturn: 'body',
    },
  );
}

/** 一筆代付請求的三方往返紀錄。
 * 🔴 **一張提款單可能送出多次代付**（重試、換渠道），所以是一筆 payout record 一組，
 * 不是整張單一張大表——併起來就看不出哪一次請求配哪幾筆往返，而「上一次為什麼沒成功」
 * 正是要看的東西。列的內容與代收共用 OrderRequestLogRow：APIs 只回純量，往返內容不回。 */
export interface WithdrawalRequestLogGroup {
  logs: OrderRequestLogRow[];
  payout_record_id: number;
  third_party_transaction_no: null | string;
  transaction_no: null | string;
}

/** 提款訂單的三方往返紀錄（依代付請求分組） */
export async function getWithdrawalRequestLogsApi(orderId: number | string) {
  return requestClient.post<{
    code: number | string;
    data: WithdrawalRequestLogGroup[];
    message: string;
  }>(
    '/agent/api/withdrawal/request-logs',
    { order_id: orderId },
    { responseReturn: 'body' },
  );
}

/** Sr11 有效流水 */
export async function getWithdrawalSr11FlowApi(orderId: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: Array<{ sr11?: null | number | string }>;
    message: string;
  }>(
    '/api/withdrawal/getSr11ValidFlowLimit',
    { order_id: orderId },
    {
      responseReturn: 'body',
    },
  );
}

/** 提款次數 */
export async function getWithdrawalTimeApi(orderId: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: { times?: number | string; times_limit?: number | string };
    message: string;
  }>(
    '/api/withdrawal/getWithdrawalTime',
    { order_id: orderId },
    {
      responseReturn: 'body',
    },
  );
}

/** 流水檢查 */
export async function getWithdrawalCheckFlowApi(orderId: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: { value?: number | string };
    message: string;
  }>(
    '/api/withdrawal/checkFlowLimit',
    { order_id: orderId },
    {
      responseReturn: 'body',
    },
  );
}

/** 審核訂單 */
export async function reviewWithdrawalOrderApi(params: {
  account_id?: number | string;
  id: number | string;
  original_status: number | string;
  remark?: string;
  status: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: any;
    message: string;
  }>('/api/withdrawal/reviewOrder', params, { responseReturn: 'body' });
}

/**
 * 代付停留單(21/22/23)人工定案：補三方單號（target/tickets#4137）。
 * 21 走 APIs 新接口轉代付處理中(22)；23 為存量過渡，仍走舊接口（維持 23 不轉狀態）。
 */
export async function editPayoutTransactionNoApi(params: {
  id: number | string;
  original_status: number | string;
  remark?: string;
  third_party_transaction_no: string;
}) {
  if (Number(params.original_status) === 21) {
    return requestClient.post<{
      code: number | string;
      data?: any;
      message: string;
    }>(
      '/agent/api/withdrawal/fill-third-party-no',
      {
        order_id: params.id,
        remark: params.remark,
        third_party_order_no: params.third_party_transaction_no,
      },
      { responseReturn: 'body' },
    );
  }
  return requestClient.post<{
    code: number | string;
    data?: any;
    message: string;
  }>(
    '/api/withdrawal/payout/edit-transaction-no',
    {
      id: params.id,
      remark: params.remark,
      third_party_transaction_no: params.third_party_transaction_no,
    },
    { responseReturn: 'body' },
  );
}

/**
 * 代付停留單(21/22/23)人工定案：標記為代付回應失敗(29)（target/tickets#4137）。
 * 21/22 走 APIs 新接口；23 為存量過渡，仍走舊接口。
 */
export async function markPayoutResponseFailedApi(params: {
  id: number | string;
  original_status: number | string;
  remark?: string;
}) {
  if ([21, 22].includes(Number(params.original_status))) {
    return requestClient.post<{
      code: number | string;
      data?: any;
      message: string;
    }>(
      '/agent/api/withdrawal/mark-failed',
      { order_id: params.id, remark: params.remark },
      { responseReturn: 'body' },
    );
  }
  return requestClient.post<{
    code: number | string;
    data?: any;
    message: string;
  }>(
    '/api/withdrawal/payout/mark-response-failed',
    { id: params.id, remark: params.remark },
    { responseReturn: 'body' },
  );
}

// ---------- 代付通道 ----------
export interface PayoutChannel {
  agent_cash_flow_id: number | string;
  agent_cash_flow_name: string;
  cash_flow_id?: number | string;
  reason?: string;
}

/** 取得可用/不可用代付通道 */
export async function getWithdrawalChannelsApi(orderId: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: { available?: PayoutChannel[]; unavailable?: PayoutChannel[] };
    message: string;
  }>(
    '/agent/api/withdrawal/channels',
    { order_id: orderId },
    {
      responseReturn: 'body',
    },
  );
}

/** 執行代付。cash_flow_id>62 走 agent API，否則舊 API。 */
export async function doWithdrawalPayoutApi(params: {
  agent_cash_flow_id: number | string;
  cash_flow_id: number | string;
  order_id: number | string;
  source?: string;
}) {
  const cashFlowId = Number(params.cash_flow_id) || 0;
  let url = '/api/withdrawal/payout-process';
  if (cashFlowId > 62) {
    url =
      params.source === 'agent'
        ? '/agent/api/agent/withdrawal'
        : '/agent/api/withdrawal/payout-process';
  }
  return requestClient.post<{
    code: number | string;
    data?: any;
    message: string;
  }>(url, params, { responseReturn: 'body' });
}

// ---------- sr02_limit5（最近五筆開洗分） ----------
export interface Sr02Limit5Row {
  /** 代理列才有：transaction_log_agents.type 的原始值；會員列為 null（target/tickets#4555） */
  agent_type?: null | number | string;
  agent_username?: string;
  balance?: number | string;
  created_at?: string;
  id?: number | string;
  member_first_name?: string;
  member_username?: string;
  operator_username?: string;
  platform?: number;
  point?: number | string;
  source?: string;
  status?: string;
  third_party_order_no?: string;
  to_agent_username?: string;
  transaction_no?: string;
  type?: number | string;
  [key: string]: any;
}

/**
 * `isAgent` 查的是代理自助洗分（transaction_log_agents），會員單走原本的 member_username。
 * 代理自助洗分的 from_agent_id／to_agent_id 都是代理自己，所以 filter=1（比對 from）查得到；
 * deposit_to=1 代表「僅代理」，不去 union 會員那塊。帶錯邊會查不到資料（target/tickets#4555）。 */
export async function getSr02Limit5Api(username: string, isAgent = false) {
  return requestClient.post<{
    code: number | string;
    data?: Sr02Limit5Row[];
    message: string;
  }>(
    '/api/report/transactionList_limit5',
    {
      agent_username: isAgent ? username : '',
      cash_flow_id: '',
      deposit_to: isAgent ? 1 : 0,
      filter: 1,
      is_created_period: 0,
      member_username: isAgent ? '' : username,
      page: 1,
      per_page: 5,
      source: 0,
    },
    { responseReturn: 'body' },
  );
}

// ---------- 出款銀行管理 ----------
export interface WithdrawalType {
  id: number;
  name: string;
}

/** 出款方式列表 */
export async function getWithdrawalTypeListApi() {
  return requestClient.post<WithdrawalType[]>('/api/withdrawal/type', {});
}

/** 出款方式列表 */
export async function getWithdrawalTypesApi() {
  return requestClient.post<WithdrawalType[]>(
    '/api/withdrawal/getWithdrawalType',
    {},
  );
}

/** 幣別列表 */
export async function getWithdrawalCurrencyListApi() {
  return requestClient.post<
    Array<{ code?: string; id: number; name?: string }>
  >('/api/currency/list', {});
}

export interface WithdrawalBankRow {
  bank_code?: string;
  bank_id: number | string;
  bank_name?: string;
  bank_rate?: number | string;
  bank_tax?: number | string;
  bank_type?: number | string;
  currency_name?: string;
  image_url?: string;
  max_amount?: number | string;
  min_amount?: number | string;
  sort?: number | string;
  status?: number | string;
  tax_type?: number | string;
  [key: string]: any;
}

export async function getWithdrawalBankListApi(params: {
  name?: string;
  page?: number;
  per_page?: number;
  status?: number | string;
  type?: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: WithdrawalBankRow[];
    message: string;
    pagination?: Pagination;
  }>(
    '/api/withdrawal/showBankList',
    { name: '', page: 1, per_page: 10, status: 1, type: -1, ...params },
    { responseReturn: 'body' },
  );
}

export interface WithdrawalBankInfo {
  bank_code?: string;
  bank_name?: string;
  bank_rate?: number | string;
  bank_tax?: number | string;
  bank_type?: number | string;
  currency_id?: number | string;
  id?: number | string;
  image_url?: string;
  max_amount?: number | string;
  min_amount?: number | string;
  sort?: number | string;
  status?: number | string;
  tax_type?: number | string;
  [key: string]: any;
}

export async function getWithdrawalBankInfoApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: WithdrawalBankInfo;
    message: string;
  }>('/api/withdrawal/getBankInfo', { id }, { responseReturn: 'body' });
}

/** 新增/編輯出款銀行；無 id 為新增 */
export async function saveWithdrawalBankApi(
  data: Record<string, any>,
  isEdit: boolean,
) {
  return requestClient.post<{
    code: number | string;
    data?: any;
    message: string;
  }>(isEdit ? '/api/withdrawal/editBank' : '/api/withdrawal/addBank', data, {
    responseReturn: 'body',
  });
}

/** 取得銀行多語系名稱 */
export async function getWithdrawalBankNameApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: Array<{ bank_name?: string; id: number; name: string }>;
    message: string;
  }>('/api/withdrawal/getBankName', { id }, { responseReturn: 'body' });
}

/** 編輯銀行多語系名稱 */
export async function editWithdrawalBankNameApi(params: {
  bank: Record<number, string>;
  id: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: any;
    message: string;
  }>('/api/withdrawal/editBankName', params, { responseReturn: 'body' });
}

/** 啟用/停用出款銀行（status 需送字串，後端用 `!= ''` 判空，數字 0 會被當成空值忽略） */
export async function toggleWithdrawalBankStatusApi(params: {
  id: number | string;
  status: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: any;
    message: string;
  }>(
    '/api/withdrawal/status',
    { id: params.id, status: String(params.status) },
    { responseReturn: 'body' },
  );
}

/** 依幣別批次調整匯率 */
export async function updateWithdrawalRateByCurrencyApi(params: {
  currency: number | string;
  rate: number | string;
  status: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: any;
    message: string;
  }>('/api/withdrawal/updateRateByCurrency', params, {
    responseReturn: 'body',
  });
}
