import type { Pagination } from './member';

import { requestClient } from '#/api/request';

/**
 * 專屬優惠 › 積分紀錄（工單4232）——兩支接口皆走 apis（POST agent/api/score/records*，成功碼 200，
 * 分頁在 data.items / data.pagination）。這裡攤平成 useListQuery 吃的 { code, data[], pagination } 形狀。
 * 時間欄位以後台時區（+8）進出，畫面顯示與查詢送出各經 fromApiTime / toApiTime 平移。
 */

interface ApisPagedBody<T> {
  code: number | string;
  data?: { items?: T[]; pagination?: Pagination };
  message: string;
}

interface ListResult<T> {
  code: number | string;
  data: T[];
  message: string;
  pagination?: Pagination;
}

function flatten<T>(res: ApisPagedBody<T>): ListResult<T> {
  return {
    code: res.code,
    data: res.data?.items ?? [],
    message: res.message,
    pagination: res.data?.pagination,
  };
}

/** 主表一列（每會員一列，只含有積分錢包列的會員） */
export interface ScoreRecordRow {
  agent_username: string;
  /** 4 位小數字串，顯示限 2 位 */
  available_score: string;
  /** 歷來累計已過期，只增不減 */
  expired_score: string;
  granted_score: string;
  /** 後台時區 */
  last_changed_at: null | string;
  member_id: number;
  /** 最近到期時間（後台時區）：還有剩餘、到期最早的那一刻；全部無期限或沒剩餘為 null（target/tickets#4438） */
  next_expire_at: null | string;
  /** 最近到期那一刻會失效的剩餘積分；無則 null */
  next_expire_score: null | string;
  /** 已使用＝系統消耗＋人工扣除（不含到期失效） */
  used_score: string;
  username: string;
}

export interface ScoreRecordListParams {
  agent_username?: string;
  page: number;
  per_page: number;
  username?: string;
}

export async function getScoreRecordListApi(
  params: ScoreRecordListParams,
): Promise<ListResult<ScoreRecordRow>> {
  const res = await requestClient.post<ApisPagedBody<ScoreRecordRow>>(
    '/agent/api/score/records',
    params,
    { responseReturn: 'body' },
  );
  return flatten(res);
}

/** 明細一筆；「來源」文案由前端以 type × reference_type 組 */
export interface ScoreLogRow {
  /** 異動後可用積分 */
  after_score: string;
  /** 活動 V2 參照時的活動名稱 */
  activity_name: null | string;
  /** 被扣／被發的批次 id；跨批扣除拆成多筆時可對回同一批的發放列 */
  batch_id: number;
  /** 後台時區 */
  changed_at: string;
  /** 1 增加 2 扣除 */
  direction: number;
  /** 該批到期日（後台時區）；無限期或扣除類為 null */
  expired_at: null | string;
  log_id: number;
  operator: null | string;
  reference_id: number;
  /** '' 無、'event_v2_user_join' 活動 V2 */
  reference_type: string;
  remark: null | string;
  score: string;
  /** 1 系統發放 2 系統消耗 3 人工調整 4 到期失效 */
  type: number;
}

export interface ScoreRecordLogsParams {
  /** 只看某一批 */
  batch_id?: number;
  /** 1 增加 2 扣除；與 type 併用分出人工發放／人工扣除 */
  direction?: number;
  /** 後台時區 YYYY-MM-DD HH:mm:ss */
  end_time?: string;
  member_id: number;
  page: number;
  per_page: number;
  start_time?: string;
  /** 1 系統發放 2 系統消耗 3 人工調整 4 到期失效 */
  type?: number;
}

export async function getScoreRecordLogsApi(
  params: ScoreRecordLogsParams,
): Promise<ListResult<ScoreLogRow>> {
  const res = await requestClient.post<ApisPagedBody<ScoreLogRow>>(
    '/agent/api/score/records/logs',
    params,
    { responseReturn: 'body' },
  );
  return flatten(res);
}
