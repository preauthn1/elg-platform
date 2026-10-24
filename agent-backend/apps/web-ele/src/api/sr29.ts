import { requestClient } from '#/api/request';

/**
 * SR29 代理遊戲明細表（target/tickets#4318）——走 apis（POST agent/api/report/sr29，成功碼 200）。
 *
 * 需求文件標題寫「SR26 代理遊戲明細表」，但 SR26／SR27 兩個編號都已被既有報表佔用
 * （SR26 佔成錢包紀錄明細表、SR27 獎勵發放明細表），經 PM 確認改掛 SR29。
 *
 * 同一支端點服務主表與所有下鑽層，層級由參數決定：
 * - 不帶 `vendor_id`／`game_type_id` → 第 0 層主表（遊戲商 × 遊戲類別）。
 *   查詢表單的遊戲商／類別篩選走 `filter_vendor_id`／`filter_game_type_id`，
 *   兩者各自獨立、可只給一個，只作用在主表。
 * - 帶了 → 下鑽層，列出 `agent_id`（不帶＝登入者自己，即第 1 層）的直屬下級。
 *   這兩個條件在整段下鑽過程鎖定不變。
 *
 * 分頁（使用者 2026-10-02 指定）：主表不分頁、一次回整層，排序在前端做；下鑽層後端分頁
 * （page／per_page，畫面給 10～1000、不提供「全部」），回應帶 pagination。
 *
 * `start_time`／`end_time` 是使用者所選時區的牆鐘，時區由 `tz_offset` 指定，
 * 與代理數據看板、SR27 同一套規則，前端不自行平移時間。
 *
 * 🔴 後端 `third_party_fee`／`company_fee` 目前固定回 null：需求文件⑥註明這兩欄的
 * 資料源要逐一與每家遊戲商確認，未定前只做版位。資料源確認後後端換成真正的聚合，
 * 這裡的型別與畫面都不必改。
 */

interface ApiBody<T> {
  code: number;
  data: T;
  message: string;
}

/** 主表列是遊戲商×類別；下鑽層是代理或會員 */
export type Sr29RowType = 'agent' | 'member' | 'vendor';

/**
 * 每一列與合計列共用的數字欄位。
 *
 * 🔴 `bet_amount` 與 `valid_bet` 恆為同一個數字：資料源 summary_bet_records 的 bet
 * 欄位存的就是有效下注，整張彙總表沒有原始下注金額（經 PM 確認照此實作）。
 * 兩欄看起來一樣不是畫面 bug。
 */
export interface Sr29Metrics {
  bet_amount: string;
  bet_count: number;
  /** 需求文件⑥：資料源待確認，目前恆為 null，畫面顯示「—」 */
  company_fee: null | string;
  /** 恆為 `member_win_lose × (−1)`，不含任何費用 */
  company_win_lose: string;
  member_win_lose: string;
  payout: string;
  /**
   * 期間內有投注的不重複會員數。
   *
   * 🔴 不可跨列相加：合計列另外算一次全域 DISTINCT，所以合計 ≠ 各列相加
   * （同一會員玩兩種類別只算一人）。下鑽層的會員列恆為 null，畫面顯示「—」。
   */
  player_count: null | number;
  /** 需求文件⑥：資料源待確認，目前恆為 null，畫面顯示「—」 */
  third_party_fee: null | string;
  valid_bet: string;
}

export interface Sr29Row extends Sr29Metrics {
  /** 遊戲類別 id，主表才有；0 ＝未分類 */
  game_type_id?: number;
  /** null ＝未分類（game_type_id 0），畫面顯示「未分類」 */
  game_type_name?: null | string;
  /** 下鑽層的列識別：代理列為 agents.id、會員列為 members.id */
  row_id?: number;
  row_type: Sr29RowType;
  /** 下鑽層的帳號；主表為 undefined */
  username?: null | string;
  vendor_id?: number;
  vendor_name?: null | string;
}

export interface Sr29Pagination {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface Sr29Result {
  /** 下鑽層的目標代理帳號，主表為 undefined */
  agent_username?: null | string;
  /** 主表為 0；下鑽層為 null（層數由前端的麵包屑長度決定） */
  level: null | number;
  /**
   * 主表：整層的列，下注金額由大到小，畫面依使用者選的欄位再排。
   * 下鑽層：當頁的列，直屬會員在前、下級代理在後，各組內下注金額由大到小。
   */
  list: Sr29Row[];
  /** 只有下鑽層有；主表不分頁 */
  pagination?: Sr29Pagination;
  /**
   * 🔴 整層的合計。前端一律直接顯示這個值，不可以自行加總 `list`：
   * 玩家人數是 COUNT DISTINCT，各列相加會多算。
   */
  summary: Sr29Metrics;
  /** 後端實際採用的時區，形如 `+08:00` */
  time_basis: string;
}

export interface Sr29Params {
  /** 下鑽目標代理；不帶＝登入者自己。需與 vendor_id／game_type_id 同時出現 */
  agent_id?: number;
  agent_username?: string;
  end_time: string;
  /**
   * 主表的查詢表單篩選，與 `filter_vendor_id` 各自獨立。允許 0（未分類）。
   *
   * 🔴 不可以拿 `game_type_id` 當篩選送：那是下鑽鍵，後端看到它就判定為下鑽層
   * （只選一個會被成對檢查擋成 422，兩個都選會直接回下鑽層的資料）。
   */
  filter_game_type_id?: number;
  /** 主表的查詢表單篩選，與 `filter_game_type_id` 各自獨立 */
  filter_vendor_id?: number;
  /** 下鑽鍵。允許 0（未分類）。必須與 vendor_id 成對出現，後端會擋 */
  game_type_id?: number;
  /** 只作用在下鑽層 */
  page?: number;
  /** 只作用在下鑽層；畫面給 10～1000，匯出送 100000 一次取回整層 */
  per_page?: number;
  start_time: string;
  tz_offset: number;
  /** 下鑽鍵，必須與 game_type_id 成對出現 */
  vendor_id?: number;
}

export async function getSr29ListApi(params: Sr29Params) {
  return requestClient.post<ApiBody<Sr29Result>>(
    'agent/api/report/sr29',
    params,
    { responseReturn: 'body' },
  );
}
