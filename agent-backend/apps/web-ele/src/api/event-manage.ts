import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 活動管理 API（V1 活動列表） */

export interface EventManageDetail {
  action_id: number | string;
  end_time: string;
  point: number | string;
  start_time: string;
}

export interface EventManageRow {
  agent_username: string;
  detail: EventManageDetail[];
  id: number | string;
  prize_expire_date: string;
  prize_is_repeatedly: number | string;
  prize_type_id: number | string;
  status: number | string;
  title: string;
}

export interface EventManageListBody {
  code: number;
  data: EventManageRow[];
  message: string;
  pagination?: Pagination;
}

/** 活動列表可排序欄位（對齊 apis EventListSortFieldEnum，值即表頭 prop） */
export type EventManageSortField =
  | 'agent_username'
  | 'condition'
  | 'end_time'
  | 'id'
  | 'prize_expire_date'
  | 'prize_is_repeatedly'
  | 'prize_type_id'
  | 'start_time'
  | 'status'
  | 'title';

export interface EventManageListParams {
  /** 活動 ID 精確比對（target/tickets#2983：「帶入既有活動設定」下拉以 ID 搜尋） */
  id?: number | string;
  page: number;
  per_page: number;
  sort_direction?: 'asc' | 'desc';
  sort_field?: EventManageSortField;
  status?: number | string;
  title?: string;
  username?: string;
}

/**
 * 活動列表（target/tickets#4320：自 API_Agent 遷入 apis，改後端分頁＋排序，不再一次全取）。
 * apis 回巢狀 `{code:200, data:{items, pagination}}`，此處攤平成 useListQuery 吃的扁平信封。
 */
export async function getEventManageListApi(
  params: EventManageListParams,
): Promise<EventManageListBody> {
  const res = await requestClient.post<{
    code: number | string;
    data?: { items?: EventManageRow[]; pagination?: Pagination };
    message?: string;
  }>('/agent/api/event/list', params, { responseReturn: 'body' });

  return {
    code: Number(res.code),
    data: res.data?.items ?? [],
    message: res.message ?? '',
    pagination: res.data?.pagination,
  };
}

export interface EventPrizeType {
  id: number | string;
  name: string;
}

/** 獎勵遊戲類型清單 */
export async function getPrizeTypeListApi() {
  return requestClient.post<EventPrizeType[]>('/api/prizeType/list', {});
}

export interface EventActionParam {
  parameter: string;
  value: number | string;
}

/** 條件類型清單 */
export async function getEventActionsApi() {
  return requestClient.post<EventActionParam[]>('/api/param/list', {
    key: 'event_action',
  });
}

/** 活動啟用/停用（status 需送字串，後端用 `!= ''` 判空，數字 0 會被當成空值忽略） */
export async function toggleEventStatusApi(
  id: number | string,
  status: number | string,
) {
  return requestClient.post<{ code: number; message: string }>(
    '/api/event/status',
    { id, status: String(status) },
    { responseReturn: 'body' },
  );
}

// ---------------------------------------------------------------------------
// 派獎/取消列表 event/payout_prize
// ---------------------------------------------------------------------------
export interface EventPayoutCancelRow {
  agent_username: string;
  created_at: string;
  event_payout_cancel_id: number | string;
  event_title: string;
  first_name: string;
  last_login_ip: number | string;
  member_username: string;
  mobile: string;
  operator: string;
  register_ip: number | string;
  remark: string;
  status: number | string;
  type: number | string;
}

export interface EventPayoutCancelListBody {
  code: number;
  data: EventPayoutCancelRow[];
  message: string;
  pagination?: Pagination;
}

/** 派獎/取消列表 */
export async function getEventPayoutCancelListApi(params: {
  end_time: string;
  event_id?: number | string;
  page: number;
  per_page: number;
  start_time: string;
  type?: number | string;
  username?: string;
}) {
  return requestClient.post<EventPayoutCancelListBody>(
    '/api/event/payout_cancel_list',
    params,
    { responseReturn: 'body' },
  );
}

/** 派發獎勵 */
export async function payoutCancelPrizeApi(
  id: number | string,
  remark: string,
) {
  return requestClient.post<{ code: number; message: string }>(
    '/api/event/payout_cancel_prize',
    { id, remark },
    { responseReturn: 'body' },
  );
}
