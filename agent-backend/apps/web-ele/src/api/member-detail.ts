import { requestClient } from '#/api/request';

/**
 * 會員詳情抽屜（4274）——五支接口全走 apis（POST agent/api/member/detail/*，成功碼 200）。
 * 舊「代理自訂認證項目」三支（validations／validate-toggle／validation-review）已移除：
 * 正式站零資料且前台無入口；實名認證改由 view 回傳 KYC 申請狀態、跳既有審核頁處理。
 * 各區塊獨立載入（工單 D-T4：部分區塊失敗只錯該區塊），故不做跨接口的合併攤平。
 */

interface ApiBody<T> {
  code: number;
  data: T;
  message: string;
}

export interface MemberDetailView {
  agent_username: string;
  balance: string;
  /** 稽核流水（未通過）：總限制／已達成／差額，4 位小數字串 */
  flow_limit_total: string;
  flow_limit_reach: string;
  flow_limit_gap: string;
  birthday: string;
  /** 上級代理 id（「網域」按鈕用） */
  agent_id: number;
  country_calling_code: string;
  created_at: string;
  device_type: string;
  email: string;
  email_validate: number;
  is_validate: number;
  last_login: string;
  /** 最後登錄 IP（點分字串；沒登入過為空字串） */
  last_login_ip: string;
  member_id: number;
  mobile: string;
  mobile_validate: number;
  nickname: string;
  realname: string;
  /** 實名驗證審核（KYC）最新一筆申請 id；從未申請為 null */
  realname_request_id: null | number;
  /** 實名驗證審核最新狀態：0 待審核／1 通過／2 拒絕；從未申請為 null */
  realname_request_status: null | number;
  realname_validate: number;
  referral_code: string;
  register_country: string;
  register_domain: string;
  register_ip: string;
  rolling_chip: string;
  /** 上級代理的分桶開關（6100）；type 0 且為 1 時帳號類型顯示為份桶會員，同帳號管理列表 */
  shareholder_visible: number;
  status: number;
  /** 會員類型：0 一般／1 份桶／2 佔成 */
  type: number;
  username: string;
}

/** 會員卡片＋基本資料（電話已依權限於服務端遮蔽）；member_id/username 擇一 */
export async function getMemberDetailViewApi(target: {
  memberId?: number;
  username?: string;
}) {
  return requestClient.post<ApiBody<MemberDetailView>>(
    '/agent/api/member/detail/view',
    target.memberId
      ? { member_id: target.memberId }
      : { username: target.username },
    { responseReturn: 'body' },
  );
}

export interface MemberDetailStatistics {
  bet_amount: string;
  company_win_lose: string;
  deposit_amount: string;
  deposit_count: number;
  event_bonus: string;
  last_deposit_at: string;
  last_withdraw_at: string;
  member_win_lose: string;
  rebate: string;
  referral_bonus: string;
  referral_commission: string;
  rewards_total: string;
  total_payout: string;
  vip_bonus: string;
  withdrawal_amount: string;
  withdrawal_count: number;
}

/** 統計摘要 16 項；不帶日期＝全期間 */
/**
 * 登入者能不能用會員詳情抽屜（4438 第 7 項）：系統管理者、總代理（含各自子帳號）、客服可用；
 * 其他身份會員帳號顯示純文字、帳號管理保留「功能」下拉。判斷在後端，前端只讀結果。
 */
export async function getMemberDrawerAccessApi() {
  return requestClient.post<{ can_use_drawer: boolean }>(
    '/agent/api/member/detail/access',
    {},
  );
}

/** 期間送後台時區（GMT+8）牆鐘的日時分秒，成對；皆空為全期間（4438 第 8 項：依使用者時區的日界） */
export async function getMemberDetailStatisticsApi(params: {
  end_time?: string;
  member_id: number;
  start_time?: string;
}) {
  return requestClient.post<ApiBody<MemberDetailStatistics>>(
    '/agent/api/member/detail/statistics',
    params,
    { responseReturn: 'body' },
  );
}

export interface MemberDetailCharts {
  deposit_methods: Array<{
    name: string;
    order_type_id: number;
    total: string;
  }>;
  transfer_in: {
    items: Array<{ name: string; total: string; vendor_id: number }>;
    others_total: string;
  };
  transfer_out: {
    items: Array<{ name: string; total: string; vendor_id: number }>;
    others_total: string;
  };
  window_days: number;
}

/** 三圓餅圖（近 90 天固定時間窗） */
export async function getMemberDetailChartsApi(memberId: number) {
  return requestClient.post<ApiBody<MemberDetailCharts>>(
    '/agent/api/member/detail/charts',
    { member_id: memberId },
    { responseReturn: 'body' },
  );
}

/** 遊戲商下拉（啟用中） */
export async function getMemberDetailVendorListApi() {
  return requestClient.post<ApiBody<Array<{ id: number; name: string }>>>(
    '/agent/api/member/detail/vendor-list',
    {},
    { responseReturn: 'body' },
  );
}

/**
 * 會員遊戲商帳號查詢：回傳該會員在此遊戲商底下「所有」啟用中的帳號（陣列，
 * 可能為空——空陣列顯示「帳號不存在」）。一個會員在同一遊戲商可能同時有
 * 正式線與活動V2線帳號，皆為有效帳號，全部回傳而非只挑一筆。
 * vendor_info_type 對應 vendor_infos.type（0測試/1正式/2活動v2），
 * type=2 時前端在帳號後附註「(活動用)」（op_tickets#3236）。
 */
export async function getMemberDetailVendorUsernameApi(data: {
  member_id: number;
  vendor_id: number;
}) {
  return requestClient.post<
    ApiBody<Array<{ username: string; vendor_info_type: null | number }>>
  >('/agent/api/member/detail/vendor-username', data, {
    responseReturn: 'body',
  });
}
