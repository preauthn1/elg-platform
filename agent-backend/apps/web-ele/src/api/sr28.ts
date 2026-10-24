import { requestClient } from '#/api/request';

/**
 * SR28 代理營運彙總表（target/tickets#4362）——走 apis（POST agent/api/report/sr28，成功碼 200）。
 *
 * 以代理為單位，把查詢期間內的註冊、活躍、存提、下注、公司輸贏、獎金與調整彙總成一列
 * （需求文件「二、25 欄定義」）。貨幣欄與多幣種篩選本案不做，欄位與篩選一併隱藏。
 *
 * `start_time`／`end_time` 是使用者所選時區的牆鐘，時區由 `tz_offset` 指定，
 * 與 SR27／SR29、代理數據看板同一套規則，前端不自行平移時間。
 *
 * 金額一律字串（後端 4 位小數），數量一律整數。
 */

interface ApiBody<T> {
  code: number;
  data: T;
  message: string;
}

/** 每一列與合計列共用的 24 個數字欄位（第 1 欄代理商帳號以外的全部） */
export interface Sr28Metrics {
  /** 調整・調入（加點） */
  adjust_in: string;
  /** 調整・淨額 ＝ 調入 − 調出（後端直接算好） */
  adjust_net: string;
  /** 調整・調出（扣點） */
  adjust_out: string;
  /** 調整・次數 */
  adjust_count: number;
  /**
   * 活躍玩家，沿用 #4124 的 TTN 站定義。
   *
   * 🔴 不可跨列相加：合計列由後端另外算一次全域不重複人數。
   */
  active_player_count: number;
  /** 下注・金額 */
  bet_amount: string;
  /** 下注・筆數 */
  bet_count: number;
  /** 發出獎金 ＝ 活動獎勵 ＋ 返水 ＋ 佣金 */
  bonus: string;
  /** 存款・金額（成功存款） */
  deposit_amount: string;
  /** 存款・筆數（成功存款） */
  deposit_count: number;
  /** 存款・手續費，取數同 #4353 的平台手續費 */
  deposit_fee: string;
  /** 首存・金額 */
  ftd_amount: string;
  /** 首存・人數 */
  ftd_count: number;
  /** GGR ＝ 遊戲總輸贏（公司角度） */
  ggr: string;
  /** 下注・玩家輸贏 */
  member_win_lose: string;
  /** 充提淨額 ＝ 存款金額 − 提款金額（後端直接算好） */
  net_deposit: string;
  /** NGR ＝ GGR − 發出獎金（後端直接算好） */
  ngr: string;
  /** 下注・派彩 */
  payout: string;
  /** 註冊人數：期間內新註冊的下線會員數 */
  register_count: number;
  /** 下注・有效下注 */
  valid_bet: string;
  /**
   * 代理錢包餘額，🔴 當下即時值，不隨查詢期間變動（其餘 23 欄都是期間加總）。
   * 合計列為當頁以外的全部代理餘額加總。
   */
  wallet_balance: string;
  /** 提款・金額（成功提款） */
  withdrawal_amount: string;
  /** 提款・筆數（成功提款） */
  withdrawal_count: number;
  /** 提款・手續費，取數同 #4353 的平台手續費 */
  withdrawal_fee: string;
}

export interface Sr28Row extends Sr28Metrics {
  agent_id: number;
  agent_username: string;
}

export interface Sr28Pagination {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface Sr28Result {
  /** 當頁的代理列 */
  list: Sr28Row[];
  pagination: Sr28Pagination;
  /**
   * 🔴 查詢結果全部筆數的合計（不是當頁）。上方 7 張卡片與表底合計列一律直接用這個值，
   * 不可以在前端加總 `list`：活躍玩家是不重複人數，各列相加會多算。
   */
  summary: Sr28Metrics;
  /** 後端實際採用的時區，形如 `+08:00` */
  time_basis: string;
}

export interface Sr28Params {
  /** 代理商帳號；不帶＝登入者自己 */
  agent_username?: string;
  end_time: string;
  page?: number;
  /** 匯出送 100000 一次取回全部 */
  per_page?: number;
  start_time: string;
  tz_offset: number;
}

export async function getSr28ListApi(params: Sr28Params) {
  return requestClient.post<ApiBody<Sr28Result>>(
    'agent/api/report/sr28',
    params,
    { responseReturn: 'body' },
  );
}
