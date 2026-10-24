import { requestClient } from '#/api/request';

/**
 * 站點儀表板 API（4155）：選單首頁下方的獨立儀表板。
 *
 * 以總代（站點）切割：admin 可挑任一總代或 ALL 全站；其他人鎖自己所屬總代線。
 * 一律日結 D-1（今日資料不出現），後端整包快取 24 小時。
 *
 * 後端在 apis repo 的 Agent 面向（gateway 依 `agent/api/` 前綴轉發），成功碼 200。
 */

export interface SiteDashboardSite {
  /** 0 = ALL（僅 admin 會拿到） */
  id: number;
  name: string;
}

export interface SiteDashboardQuery {
  /** 前端時區牆鐘 */
  end_time: string;
  /** 站點（總代 id；admin 傳 0 = ALL） */
  site: number;
  start_time: string;
  tz_offset: number;
}

export interface SiteDashboardSummary {
  new_members: number;
  total_agents: number;
  total_members: number;
}

export interface SiteDashboardTrends {
  /** 有效投注金額／日 */
  bet: string[];
  /** 公司角度總輸贏（投注−派獎）／日 */
  company_win: string[];
  dates: string[];
  deposit: string[];
  /** 新註冊會員數／日 */
  registrations: number[];
  /** 反水及活動獎勵／日 */
  rewards: string[];
  withdrawal: string[];
}

/** 站點按鈕清單（admin 回 ALL＋全部總代；其他人只回自己所屬總代） */
export async function getSiteDashboardSitesApi() {
  return requestClient.post<{
    code: number | string;
    data?: SiteDashboardSite[];
    message: string;
  }>('/agent/api/site-dashboard/sites', {}, { responseReturn: 'body' });
}

/** 數字卡（期間新註冊、玩家總人數、代理總人數） */
export async function getSiteDashboardSummaryApi(data: SiteDashboardQuery) {
  return requestClient.post<{
    code: number | string;
    data?: SiteDashboardSummary;
    message: string;
  }>('/agent/api/site-dashboard/summary', data, { responseReturn: 'body' });
}

/** 三張圖的日序列 */
export async function getSiteDashboardTrendsApi(data: SiteDashboardQuery) {
  return requestClient.post<{
    code: number | string;
    data?: SiteDashboardTrends;
    message: string;
  }>('/agent/api/site-dashboard/trends', data, { responseReturn: 'body' });
}
