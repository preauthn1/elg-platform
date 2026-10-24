import { requestClient } from '#/api/request';

/**
 * 會員管理 API */

export interface MemberRow {
  account_type?: string;
  agent_code?: string;
  agent_member_username?: string;
  agent_username?: string;
  balance?: number | string;
  bank_account_count?: number;
  birthday?: string;
  created_at?: string;
  email?: string;
  first_name?: string;
  id: number;
  invitation_code?: string;
  /** 最後存款時間，target/tickets#4306 新增欄位，待後端接上前一律為 undefined */
  last_deposit_time?: string;
  last_ip?: number;
  last_login?: string;
  /** 最後出款時間，target/tickets#4306 新增欄位，待後端接上前一律為 undefined */
  last_withdrawal_time?: string;
  login_fail_count?: number;
  /** 總代（階層最上層代理）帳號，target/tickets#4306 新增欄位，待後端接上前一律為 undefined */
  master_agent_username?: string;
  member_code?: string;
  /** 會員群組名稱，target/tickets#4306 新增欄位，待後端接上前一律為 undefined */
  member_group_name?: string;
  mobile?: string;
  mobile_validate?: number;
  parent?: number;
  payment_pwd_fail_count?: number;
  realname_validate?: number;
  register_domain?: string;
  register_ip?: number;
  risk_status?: number;
  rolling_chip?: number | string;
  /** 會員可用積分（工單4233；member_wallets type 4 投影列，無列時後端回 0） */
  score?: number | string;
  status?: number;
  type?: number;
  vip_name?: string;
  [key: string]: any;
}

export interface Pagination {
  current: number;
  pages: number;
  total: number;
}

export interface MemberListParams {
  account_no?: string;
  account_type?: string;
  agent_username?: string;
  certification?: number | string;
  created_end_time?: string;
  created_start_time?: string;
  downline_query?: number;
  first_name?: string;
  fuzzy_query?: number;
  id?: number; // 查看下線
  login_end_time?: string;
  login_start_time?: string;
  /** 會員群組篩選：不帶 = 全部；0 = 未指派群組 */
  member_group_id?: number | string;
  member_invitation_code?: string;
  mobile?: string;
  mobile_authentication?: number | string;
  page?: number;
  per_page?: number;
  register_invitation_code?: string;
  register_ip?: string;
  status?: number | string;
  username?: string;
}

/**
 * 列表上方合計（target/tickets#4306）：查詢結果全部筆數的加總，不受分頁影響。
 * 待後端補上此欄位前，getMemberListApi 一律回傳 undefined，畫面顯示 0。
 */
export interface MemberListSummary {
  balance_total?: number | string;
  rolling_chip_total?: number | string;
}

export interface MemberListResult {
  /**
   * 能否開啟會員銀行卡明細（target/tickets#4486）：後端依帳號角色判定
   * （系統管理員／總代／客服，子帳號看主帳號）。
   */
  bankAccountVisible: boolean;
  code: number;
  list: MemberRow[];
  message: string;
  pagination: Pagination;
  summary?: MemberListSummary;
}

/** 會員列表 */
export async function getMemberListApi(
  params: MemberListParams,
): Promise<MemberListResult> {
  const res = await requestClient.post<{
    bank_account_visible?: boolean | number;
    code: number;
    data: MemberRow[];
    message: string;
    pagination: Pagination;
    summary?: MemberListSummary;
  }>(
    '/api/member/list',
    { page: 1, per_page: 50, ...params },
    { responseReturn: 'body' },
  );
  return {
    // #4486 後端依帳號角色判定（系統管理員／總代／客服，子帳號看主帳號）；
    // 缺值一律視為不可開啟（fail-closed，比照 #4400 callback_visible）
    bankAccountVisible:
      res.bank_account_visible === true || res.bank_account_visible === 1,
    code: res.code,
    list: res.code === 1000 ? (res.data ?? []) : [],
    message: res.message,
    pagination: res.pagination ?? { current: 1, pages: 0, total: 0 },
    summary: res.summary,
  };
}

/**
 * 啟用/停用會員（target/tickets#4438 由 API_Agent member/edit_status 改打 apis，成功碼 200）。
 * 帳號管理「功能」下拉與會員詳情抽屜的狀態開關共用；與現值相同、越權皆回非 200。
 */
export async function editMemberStatusApi(data: {
  member_id: number;
  remark: string;
  status: number;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/agent/api/member/status',
    data,
    { responseReturn: 'body' },
  );
}

/** 帶分頁的回應 */
export interface PagedResult<T> {
  code: number;
  data: T[];
  message: string;
  pagination?: Pagination;
}

export interface StatusLogRow {
  after_status: number;
  before_status: number;
  created_at: string;
  operator_username: string;
  remark: string;
}

/** 會員狀態變更紀錄 */
export async function getMemberStatusLogApi(params: {
  member_id: number;
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<PagedResult<StatusLogRow>>(
    '/api/member/status_log_list',
    { page: 1, per_page: 8, ...params },
    { responseReturn: 'body' },
  );
}

export interface StatusLogReportRow {
  after_status: number;
  agent_username: string;
  before_status: number;
  created_at: string;
  member_username: string;
  operator_username: string;
  type: number;
}

export interface StatusLogReportParams {
  after_status?: number | string;
  agent_username?: string;
  before_status?: number | string;
  end_time?: string;
  fuzzy_query?: number;
  member_username?: string;
  operator_username?: string;
  page?: number;
  per_page?: number;
  reason?: number | string;
  start_time?: string;
}

/** 會員狀態紀錄（跨會員列表報表） */
export async function getMemberStatusLogReportApi(
  params: StatusLogReportParams,
) {
  return requestClient.post<PagedResult<StatusLogReportRow>>(
    '/api/member/status_log_list_report',
    { page: 1, per_page: 30, ...params },
    { responseReturn: 'body' },
  );
}

export interface LoginLogRow {
  created_at: string;
  ip: string;
  status: number;
}

/** 會員登入 IP 紀錄 */
export async function getMemberLoginLogApi(params: {
  id: number;
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<PagedResult<LoginLogRow>>(
    '/api/member/getLoginLog',
    { page: 1, per_page: 10, ...params },
    { responseReturn: 'body' },
  );
}

/** 代理網域清單 */
export async function getAgentDomainsApi(agentId: number) {
  return requestClient.post<Array<{ domain: string; id: number }>>(
    '/api/agentDomain/self',
    { agent_id: agentId, show_all: 1 },
  );
}

/**
 * 讀單一會員資料：開洗分彈窗、補點彈窗、會員編輯頁共用。
 * target/tickets#4438 由 API_Agent member/view 改打 apis（欄位同舊接口，不再回支付密碼雜湊）。
 */
export async function getMemberViewApi(id: number) {
  return requestClient.post<MemberRow>('/agent/api/member/profile', {
    member_id: id,
  });
}

export interface BankAccountRow {
  account_id: number;
  account_nickname: string;
  is_deposit: number;
  is_withdrawal: number;
}

/**
 * 開洗分可選的銀行帳號（操作者主帳號支付組別、自助開分金流底下啟用中的帳號）。
 * target/tickets#4438 由 API_Agent bank/dropdownAccountList 改打 apis；前端依 is_deposit／is_withdrawal 分兩個下拉。
 */
export async function getBankAccountListApi() {
  return requestClient.post<BankAccountRow[]>(
    '/agent/api/member/transaction/bank-accounts',
    {},
  );
}

/**
 * 會員轉帳/開洗分
 * type: 1=存入 2=取出 4=補償 5=贈送禮碼 6=扣除活動錢包 7=扣除禮碼 */
export async function memberTransactionApi(data: {
  account_id: number;
  audit_amount?: number;
  member_id: number;
  memo: string;
  point: number;
  type: number;
}) {
  return requestClient.post('/api/memberTransaction', data, {
    responseReturn: 'body',
  });
}

/**
 * 人工發放積分（工單4233；開洗分彈窗「積分」選項）——走 apis（POST agent/api/member/score/grant，成功碼 200）。
 * hours 不帶＝無限期；remark 必填。權限節點 member_add_score，後端 CheckAgentPermission 亦擋。
 */
export async function grantMemberScoreApi(data: {
  hours?: null | number;
  member_id: number;
  remark: string;
  score: number | string;
}) {
  return requestClient.post<{
    code: number;
    data: {
      balance: string;
      batch_id: number;
      expired_at: null | string;
      member_id: number;
      score: string;
    };
    message: string;
  }>('/agent/api/member/score/grant', data, { responseReturn: 'body' });
}

/**
 * 人工扣除積分（工單4233；開洗分彈窗「積分扣除」選項）——走 apis（POST agent/api/member/score/deduct，成功碼 200）。
 * 填總量，後端依到期日由近到遠自動拆批（與活動消耗同一套 FIFO）；餘額不足回 400 且 message 帶可用／要求數。
 * 權限節點 member_deduct_score。
 */
export async function deductMemberScoreApi(data: {
  member_id: number;
  remark: string;
  score: number | string;
}) {
  return requestClient.post<{
    code: number;
    data: {
      balance: string;
      deductions: Array<{
        batch_id: number;
        expired_at: null | string;
        score: string;
      }>;
      member_id: number;
      score: string;
    };
    message: string;
  }>('/agent/api/member/score/deduct', data, { responseReturn: 'body' });
}

/**
 * 修改會員密碼/支付密碼
 * type: 'password' | 'payment_password' */
export async function modifyMemberPasswordApi(data: {
  confirm_password: [REDACTED];
  member_id: number;
  password: [REDACTED];
  remark: string;
  type: 'password' | 'payment_password';
}) {
  return requestClient.post('/api/member/modifyPassword', data, {
    responseReturn: 'body',
  });
}

/**
 * 驗證登入者自己的支付密碼（會員／代理開洗分、錢包操作彈窗送出前）。
 * target/tickets#4438 由 API_Agent verifyAgentPaymentPassword 改打 apis：只驗登入者本人、不再傳 agent_id；
 * 密碼錯誤回 HTTP 400，訊息由請求攔截器統一顯示並丟例外，呼叫端 catch 住停在原畫面即可。
 */
export async function verifyAgentPaymentPasswordApi(paymentPassword: [REDACTED] {
  return requestClient.post<{ code: number | string; message: string }>(
    '/agent/api/payment-password/verify',
    { payment_password: [REDACTED] },
    { responseReturn: 'body' },
  );
}

export interface MemberBankAccountRow {
  account_no: string;
  bank_name: string;
  currency_code: string;
}

/** 會員銀行卡列表 */
export async function getMemberBankAccountApi(id: number) {
  return requestClient.post<MemberBankAccountRow[]>('/api/member/bankAccount', {
    id,
  });
}

/** 修改會員帳號類型 */
export async function modifyMemberTypeApi(data: {
  id: number;
  remark: string;
  type: number | string;
}) {
  return requestClient.post('/api/member/modify_type', data, {
    responseReturn: 'body',
  });
}

/**
 * 交易是否維護中：true 時隱藏開洗分入口（帳號管理、會員詳情抽屜）。
 * target/tickets#4438 由 API_Agent system_maintenance/transactions 改打 apis。
 */
export async function getTransactionMaintenanceApi(): Promise<boolean> {
  const res = await requestClient.post<{ maintaining: boolean }>(
    '/agent/api/member/transaction/maintenance',
    {},
  );
  return res?.maintaining === true;
}

/** 調帳類別下拉 */
export async function getAdjustmentTypesApi() {
  return requestClient.post<Array<{ id: number; name: string }>>(
    '/api/dropdown/adjustment_type',
    {},
  );
}

/** 會員調帳 */
export async function memberAdjustmentApi(data: {
  member_id: number;
  memo: string;
  point: number;
  type: number;
}) {
  return requestClient.post('/api/memberAdjustment', data, {
    responseReturn: 'body',
  });
}

export interface FlowLimitRow {
  bonus: string;
  created_at: string;
  event_id: number;
  flow_limit: string;
  id: number;
  remark: null | string;
  status: number;
  status_text: string;
  title: string;
  type: number;
  updated_at: string;
  valid_flow_limit: string;
}

export interface FlowLimitListResult {
  items: FlowLimitRow[];
  pagination: { total: number };
  /** 是否還有未通過的流水（決定「全部刪除」按鈕） */
  status_zero_exists: boolean;
}

/** 會員稽核流水列表（apis，4438 由 API_Agent member/flowLimit 移植） */
export async function getMemberFlowLimitApi(params: {
  member_id: number;
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<FlowLimitListResult>(
    '/agent/api/member/flow-limits',
    { page: 1, per_page: 10, ...params },
  );
}

/**
 * 取消稽核流水（apis，4438 由 API_Agent member/cancel_flow_limit 移植）
 * 單筆帶 id；取消該會員全部未通過帶 member_id */
export async function cancelMemberFlowLimitApi(
  data: { id: number; remark: string } | { member_id: number; remark: string },
) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/agent/api/member/flow-limits/cancel',
    data,
    {
      responseReturn: 'body',
    },
  );
}

/** 營運商列表 */
export async function getVendorListApi() {
  return requestClient.post<
    Array<{ id: number; is_free?: number; name: string }>
  >('/api/vendor/list', {});
}

/** 會員在某營運商的遊戲帳號 */
export async function getVendorUsernameApi(data: {
  member_id: number;
  vendor_id: number;
}) {
  return requestClient.post('/api/vendor/username', data, {
    responseReturn: 'body',
  });
}

/** 會員身分驗證列表 */
export async function getMemberValidationListApi(memberId: number) {
  return requestClient.post(
    '/api/memberValidation/list',
    {
      member_id: memberId,
    },
    { responseReturn: 'body' },
  );
}

/** 切換會員認證狀態 */
export async function editMemberValidateApi(data: {
  id: number;
  is_validate: number;
}) {
  return requestClient.post('/api/member/edit', data, {
    responseReturn: 'body',
  });
}

export interface CountryRow {
  code: string;
  country_code: string;
  id: number;
  name: string;
}

/** 國家下拉 */
export async function getCountryDropdownApi() {
  return requestClient.post<CountryRow[]>('/api/country/dropdown', {});
}

/** 訂單類型清單 */
export async function getOrderTypeListApi() {
  return requestClient.post<Array<{ id: number; name: string }>>(
    '/api/orderType/list',
    {},
  );
}

/** 會員儲值報表 */
export async function getMemberDepositReportApi(data: {
  end_date: string;
  member_id: number;
  start_date: string;
}) {
  return requestClient.post<Array<{ total: number; type: number }>>(
    '/api/report/deposit',
    data,
    { responseReturn: 'body' },
  );
}

/** 會員轉點報表 */
export async function getMemberTransactionReportApi(data: {
  end_date: string;
  member_id: number;
  start_date: string;
  type_id: number;
}) {
  return requestClient.post<Array<{ total: number; type: number }>>(
    '/api/report/transaction',
    data,
    { responseReturn: 'body' },
  );
}

/** 身分驗證單筆檢視 */
export async function getMemberValidationViewApi(id: number) {
  return requestClient.post(
    '/api/memberValidation/view',
    { id },
    {
      responseReturn: 'body',
    },
  );
}

/** 身分驗證審核 */
export async function editMemberValidationApi(data: {
  id: number;
  remark: string;
  status: number | string;
}) {
  return requestClient.post('/api/memberValidation/edit', data, {
    responseReturn: 'body',
  });
}

export interface MemberActivityRow {
  agent_username?: string;
  deposit_count?: number;
  deposit_point_sum?: number | string;
  deposit_sub_withdrawal_amount?: number | string;
  first_name?: string;
  last_login?: string;
  mobile?: string;
  registered_time?: string;
  username?: string;
  withdrawal_count?: number;
  withdrawal_point_sum?: number | string;
}

export interface PasswordRecordRow {
  agent_username?: string;
  created_at?: string;
  id: number;
  ip?: string;
  member_username?: string;
  modifier_username?: string;
  type?: string;
}

/** 會員密碼修改紀錄 */
export async function getMemberPasswordRecordsApi(params: {
  agent_username?: string;
  end_time?: string;
  ip?: string;
  member_username?: string;
  modifier_username?: string;
  page?: number;
  per_page?: number;
  start_time?: string;
  type?: string;
}) {
  return requestClient.post<PagedResult<PasswordRecordRow>>(
    '/api/member/modifyPasswordRecords',
    { page: 1, per_page: 30, ...params },
    { responseReturn: 'body' },
  );
}

export interface MemberInactiveRow {
  agent_username?: string;
  first_name?: string;
  last_bet_time?: string;
  last_deposit_time?: string;
  last_login_time?: string;
  last_withdrawal_time?: string;
  mobile?: string;
  register_time?: string;
  username?: string;
}

/** 不活躍會員紀錄 */
export async function getMemberInactiveApi(params: {
  agent_username: string;
  date_time_enable?: number;
  end_date?: string;
  never_deposit_members?: number;
  not_bet_days?: number | string;
  not_deposit_days?: number | string;
  not_login_days?: number | string;
  page?: number;
  per_page?: number;
  start_date?: string;
}) {
  return requestClient.post<PagedResult<MemberInactiveRow>>(
    '/api/member/inactive',
    { page: 1, per_page: 30, ...params },
    { responseReturn: 'body' },
  );
}

/** 會員活動紀錄 */
export async function getMemberActivitiesApi(params: {
  agent_username?: string;
  amount_search_range?: number | string;
  amount_search_type?: number | string;
  amount_search_value?: number | string;
  end_date: string;
  include_backend_deposit?: number;
  just_registered?: number;
  page?: number;
  per_page?: number;
  search_all_members?: number;
  start_date: string;
  username?: string;
}) {
  return requestClient.post<PagedResult<MemberActivityRow>>(
    '/api/member/activities',
    { page: 1, per_page: 30, ...params },
    { responseReturn: 'body' },
  );
}

export interface RealnameValidateRow {
  bank_account?: string;
  bank_name?: string;
  birthday?: null | string;
  bsb_code?: string;
  created_at?: string;
  id: number;
  id_back_image?: null | string;
  id_front_image?: null | string;
  id_number?: null | string;
  operator?: string;
  realname?: string;
  remark?: string;
  status?: number;
  updated_at?: string;
  username?: string;
  validator_id?: null | number;
}

/** 會員實名驗證審核列表 */
export async function getMemberRealnameValidateListApi(params: {
  bank_account?: string;
  end_time?: string;
  page?: number;
  per_page?: number;
  start_time?: string;
  status?: number | string;
  username?: string;
}) {
  return requestClient.post<PagedResult<RealnameValidateRow>>(
    '/api/member/realname_validate/list',
    { page: 1, per_page: 30, ...params },
    { responseReturn: 'body' },
  );
}

/** 實名驗證單筆檢視 */
export async function getMemberRealnameValidateViewApi(requestId: number) {
  return requestClient.post<RealnameValidateRow>(
    '/api/member/realname_validate/view',
    { request_id: requestId },
  );
}

/** 實名驗證審核 */
export async function reviewMemberRealnameValidateApi(data: {
  id: number;
  remark: string;
  status: number;
}) {
  return requestClient.post('/api/member/realname_validate/review', data, {
    responseReturn: 'body',
  });
}

export interface MemberRestrictionRow {
  account_status?: number;
  amend?: number;
  id: number;
  operator?: string;
  previous_account_status?: number;
  previous_remark?: string;
  previous_risk?: number;
  remark?: string;
  risk?: number;
  updated_at?: string;
  username?: string;
}

/** 會員限制列表 */
export async function getMemberRestrictionListApi(params: {
  account_status?: number | string;
  page?: number;
  per_page?: number;
  username?: string;
}) {
  return requestClient.post<PagedResult<MemberRestrictionRow>>(
    '/api/member_restriction/list',
    { page: 1, per_page: 10, ...params },
    { responseReturn: 'body' },
  );
}

/** 會員限制單筆檢視 */
export async function getMemberRestrictionViewApi(id: number) {
  return requestClient.post<MemberRestrictionRow>(
    '/api/member_restriction/view',
    { id },
  );
}

/** 新增會員限制 */
export async function addMemberRestrictionApi(data: {
  account_status: number;
  remark: string;
  risk: number;
  username: string;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/member_restriction/add',
    data,
    { responseReturn: 'body' },
  );
}

/** 編輯會員限制 */
export async function editMemberRestrictionApi(data: {
  account_status: number;
  id: number;
  remark: string;
  risk: number;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/member_restriction/edit',
    data,
    { responseReturn: 'body' },
  );
}

/** 刪除會員限制 */
export async function deleteMemberRestrictionApi(id: number) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/member_restriction/delete',
    { id },
    { responseReturn: 'body' },
  );
}

export interface UnlockEventModeRow {
  balance?: number | string;
  created_at?: string;
  flow_limit?: number | string;
  /** 遊戲錢包餘額（多錢包拆分，target/tickets#4290） */
  game_wallet?: number | string;
  id: number;
  /** 主錢包餘額（多錢包拆分，target/tickets#4290） */
  main_wallet?: number | string;
  member_username?: string;
  /** 該會員上級代理是否開啟多錢包（agent setting 6008），target/tickets#4290 */
  multiple_wallet_switch?: number | string;
  remark?: string;
  /** 禮碼餘額（多錢包拆分，target/tickets#4290） */
  rolling_chip?: number | string;
  status?: number;
}

/** 清除流水審核列表 */
export async function getUnlockEventModeListApi(params: {
  end_time: string;
  member_username?: string;
  page?: number;
  per_page?: number;
  start_time: string;
  status?: number | string;
}) {
  return requestClient.post<PagedResult<UnlockEventModeRow>>(
    '/api/unlock_event_mode/list',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 清除流水單筆檢視 */
export async function getUnlockEventModeViewApi(id: number) {
  return requestClient.post<UnlockEventModeRow>('/api/unlock_event_mode/view', {
    id,
  });
}

/** 清除流水審核 */
export async function reviewUnlockEventModeApi(data: {
  id: number;
  remark: string;
  status: number;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/unlock_event_mode/review',
    data,
    { responseReturn: 'body' },
  );
}

export interface VendorAccountItem {
  vendor_id: number;
  vendor_info_id?: number;
  vendor_name?: string;
  vendor_status?: number;
  vendor_unbind?: number;
  vendor_username?: string;
  vendor_username_orig?: string;
}

export interface VendorAccountSummary {
  chatDB_member?: {
    member_id?: number | string;
    parent_agent_member_id?: number | string;
    parent_agent_user_id?: number | string;
  };
  commonInfo?: { gcp_project_id?: string };
  member?: {
    agent_id?: number;
    agent_name?: string;
    chat_group_id?: number | string;
    customized_setting_group_id?: number | string;
    member_id?: number;
    member_username?: string;
  };
  vendor_accounts?: VendorAccountItem[];
  vendor_accounts_mappingDic?: Record<string, VendorAccountItem>;
}

/** 遊戲帳號查詢總覽 */
export async function getVendorAccountSummaryApi(username: string) {
  return requestClient.post<{
    code: number;
    data?: VendorAccountSummary;
    message: string;
  }>('/api/vendor/account/summary', { username }, { responseReturn: 'body' });
}

export interface Sr06Row {
  agent_username?: string;
  balance?: number | string;
  created_at?: string;
  created_at_utc?: string;
  id: number;
  member_nickname?: string;
  member_username?: string;
  point?: number | string;
  status?: number;
  transaction_no?: string;
  type?: number;
  vendor?: string;
  vendor_id?: number;
}

/** 近 30 筆轉帳紀錄 */
export async function getSr06Api(params: {
  end_date: string;
  member_username: string;
  page?: number;
  per_page?: number;
  start_date: string;
  status?: number;
}) {
  return requestClient.post<PagedResult<Sr06Row>>(
    '/api/report/sr06',
    { page: 1, per_page: 30, status: 0, ...params },
    { responseReturn: 'body' },
  );
}

/** 遊戲錢包餘額查詢 */
export async function getVendorBalanceApi(data: {
  member_id: number | string;
  vendor_id: number | string;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/member/vendor/balance',
    data,
    { responseReturn: 'body' },
  );
}

/** 遊戲錢包餘額取回 */
export async function vendorTransferInApi(data: {
  account: string;
  vendor_id: number | string;
  vendor_info_id: number | string;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/internal/api/vendor/transferIn',
    data,
    { responseReturn: 'body' },
  );
}

/** 解綁遊戲帳號 */
export async function unbindVendorAccountApi(data: {
  account: string;
  vendor_id: number | string;
  vendor_info_id: number | string;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/vendor/unbindAccount',
    data,
    { responseReturn: 'body' },
  );
}

/** 轉帳補償 */
export async function refundTransactionApi(transactionId: number | string) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/refundTransaction',
    { transaction_id: transactionId },
    { responseReturn: 'body' },
  );
}

/** 遊戲商下拉 */
export async function getVendorDropdownListApi() {
  return requestClient.post<Array<{ id: number; name: string }>>(
    '/api/vendor/dropdownList',
    {},
  );
}

export interface VendorAccountRow {
  agent_username?: string;
  binded_at?: string;
  event_v2_detail_id?: number | string;
  id: number;
  member_id?: number;
  member_nickname?: string;
  member_username?: string;
  vendor_id?: number;
  vendor_name?: string;
  vendor_username?: string;
}

/** 遊戲商帳號列表 */
export async function getVendorAccountListApi(params: {
  account_name?: string;
  page?: number;
  per_page?: number;
  username?: string;
  vendor_id: number | string;
}) {
  return requestClient.post<
    PagedResult<VendorAccountRow> & {
      meta?: { showResetPasswordButton?: boolean };
    }
  >(
    '/api/vendor/account/list',
    { page: 1, per_page: 10, ...params },
    { responseReturn: 'body' },
  );
}

/** 重置遊戲商帳號密碼 */
export async function resetVendorPasswordApi(data: {
  member_id: number | string;
  vendor_id: number | string;
  vendor_username: string;
}) {
  return requestClient.post<{ code: number; message: string }>(
    '/agent/api/vendor/reset-password',
    data,
    { responseReturn: 'body' },
  );
}

export interface BehaviorTotals {
  total_bet?: number | string;
  total_bonus?: number | string;
  total_deposit?: number | string;
  total_rebate?: number | string;
  total_winlose?: number | string;
  total_withdrawal?: number | string;
}

export interface BehaviorParams {
  end_time: string;
  search_username: string;
  start_time: string;
  username_type: number | string;
}

/** 會員行為資料合計 */
export async function getMemberBehaviorTotalApi(params: BehaviorParams) {
  return requestClient.post<{
    code: number;
    data?: BehaviorTotals[];
    message: string;
  }>('/api/report/member_behavior_data_total', params, {
    responseReturn: 'body',
  });
}

export interface BehaviorRow extends BehaviorTotals {
  agent_username?: string;
  created_at?: string;
  member_id?: number;
  member_username?: string;
}

/** 會員行為資料列表 */
export async function getMemberBehaviorDataApi(
  params: BehaviorParams & {
    hidden_zero_data: number | string;
    page?: number;
    per_page?: number;
  },
) {
  return requestClient.post<PagedResult<BehaviorRow>>(
    '/api/report/member_behavior_data',
    { page: 1, per_page: 30, ...params },
    { responseReturn: 'body' },
  );
}

export interface BehaviorDetailCell {
  amount?: number | string;
  type?: string;
}

export interface BehaviorBonusDetail {
  compensate?: BehaviorDetailCell[];
  event?: BehaviorDetailCell[];
  mission?: BehaviorDetailCell[];
  other_reward?: BehaviorDetailCell[];
  vip?: BehaviorDetailCell[];
}

export interface SaveMemberData {
  address?: string;
  birthday?: string;
  city?: string;
  country_id?: number | string;
  email?: string;
  first_name?: string;
  id?: number | string;
  ip_change_email_verify?: number;
  ip_change_mobile_verify?: number;
  last_name?: string;
  mobile?: string;
  mobile_validate?: number;
  nickname?: string;
  parent?: number | string;
  password?: string;
  password_confirmation?: string;
  payment_password?: string;
  payment_password_confirmation?: string;
  realname_validate?: number;
  status?: number;
  username?: string;
}

/** 建立/編輯會員 */
export async function saveMemberApi(data: SaveMemberData) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    data.id ? '/api/member/edit' : '/api/member/add',
    data,
    { responseReturn: 'body' },
  );
}

/** 重置會員手機號 */
export async function resetMemberMobileApi(id: number | string) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/member/resetMobile',
    { id },
    { responseReturn: 'body' },
  );
}

/** 會員行為資料明細彈窗 */
export async function getMemberBehaviorDetailApi(params: {
  end_time: string;
  member_id: number | string;
  search_username: string;
  start_time: string;
  total_kind: string;
  username_type: number | string;
}) {
  return requestClient.post<{
    code: number;
    data?: BehaviorBonusDetail | BehaviorDetailCell[];
    message: string;
  }>('/api/report/member_behavior_data_detail', params, {
    responseReturn: 'body',
  });
}
