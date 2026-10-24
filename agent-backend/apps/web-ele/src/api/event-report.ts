import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 活動獎勵領取記錄報表 API（event/prize_report）。 */

export interface EventDropdownRow {
  id: number | string;
  status?: number;
  title?: string;
}

/** 活動下拉 */
export async function getEventDropdownApi() {
  return requestClient.post<EventDropdownRow[]>('/api/dropdown/event', {
    page: 1,
    per_page: 1000,
    status: [0, 1],
  });
}

/** 兼含彙總(event_id=0)與明細(event_id>0)兩種欄位 */
export interface PrizeReportRow {
  agent_username?: string;
  /** 彙總：筆數 */
  cnt?: number | string;
  created_at?: string;
  event_id?: number | string;
  /** 彙總：活動狀態 1 啟用 */
  event_status?: number;
  event_title?: string;
  member_username?: string;
  /** 1=平台點數、否則洗碼量 */
  point_type?: number;
  prize_name?: string;
  prize_point?: number | string;
  /** 明細：領取狀態 1 已領取 */
  status?: number;
  updated_at?: string;
}

/** 活動獎勵領取記錄 */
export async function getPrizeReportApi(params: {
  end_time: string;
  event_id?: number | string;
  page?: number;
  per_page?: number;
  start_time: string;
  status?: number | string;
  time_type?: number | string;
  type?: number | string;
  username?: string;
}) {
  const { type, username, ...rest } = params;
  const body: Record<string, any> = {
    event_id: 0,
    page: 1,
    per_page: 50,
    status: 1,
    time_type: 1,
    ...rest,
  };
  if (username) {
    let key = 'member_username';
    if (Number(type) === 1) key = 'user_username';
    else if (Number(type) === 2) key = 'agent_username';
    body[key] = username;
  }
  return requestClient.post<{
    code: number | string;
    data?: PrizeReportRow[];
    message: string;
    pagination?: Pagination;
  }>('/api/report/prizeReport', body, { responseReturn: 'body' });
}
