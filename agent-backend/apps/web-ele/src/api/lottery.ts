import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 彩票報表 API（SR16 V-Jackpot / SR17 W-Jackpot / SR17_6d W-Jackpot 6D）。
 * 三個變體只差在端點路徑與下拉來源，集中在 VARIANT_API 設定。 */

export type LotteryVariant = 'sr16' | 'sr17' | 'sr17_6d';

const VARIANT_API: Record<
  LotteryVariant,
  {
    dropdown: string;
    manualDraw: string;
    member: string;
    period: string;
    ticket: string;
    winning: string;
  }
> = {
  sr16: {
    dropdown: 'luckydraw',
    manualDraw: 'v_jackpot/manualDraw',
    member: 'v_jackpot/member',
    period: 'v_jackpot/period',
    ticket: 'v_jackpot/ticket',
    winning: 'v_jackpot/winningList',
  },
  sr17: {
    dropdown: 'wjackpot',
    manualDraw: 'w_jackpot/manualDraw',
    member: 'w_jackpot/member',
    period: 'w_jackpot/period',
    ticket: 'w_jackpot/ticket',
    winning: 'w_jackpot/winningList',
  },
  sr17_6d: {
    dropdown: 'wjackpot_6d',
    manualDraw: 'w_jackpot/6dManualDraw',
    member: 'w_jackpot/6dMember',
    period: 'w_jackpot/6dPeriod',
    ticket: 'w_jackpot/6dTicket',
    winning: 'w_jackpot/6dWinningList',
  },
};

export interface LotteryDropdownRow {
  end_time: string;
  id: number;
  no: number;
  start_time: string;
}

export interface LotteryPeriodRow {
  draw_number?: string;
  /** sr17 多獎號（W-Jackpot 開 prize_list 用） */
  draw_number_2?: string;
  draw_number_3?: string;
  draw_number_4?: string;
  draw_number_5?: string;
  draw_time?: string;
  id: number;
  /** 1=允許手動開獎 */
  manual_draw?: number;
  no: number;
  point?: number | string;
  reward_item_1?: number | string;
  reward_item_2?: number | string;
  reward_item_3?: number | string;
  reward_item_4?: number | string;
  reward_item_5?: number | string;
  status?: number;
  tickets?: number | string;
  v_jackpot?: null | number | string;
}

export interface LotteryMemberRow {
  agent_username?: string;
  created_at?: string;
  /** 0=未開獎 */
  lucky_draw_status?: number;
  member_username?: string;
  no: number;
  point?: number | string;
  /** lang key，空字串=未中獎 */
  prize?: string;
  /** 2=實物獎（point 原值顯示） */
  prize_type?: number;
  /** 0=無實物獎項 */
  reward_item_id?: number;
  /** 0=未領取 */
  status?: number;
  ticket_number?: string;
  time?: string;
}

export interface LotteryTicketRow {
  agent_username?: string;
  id: number;
  member_username?: string;
  no: number;
  ticket_number?: string;
}

export interface LotteryWinningRow {
  agent_username?: string;
  member_username?: string;
  point?: number | string;
  prize_type?: number;
  ticket_number?: string;
}

interface PagedBody<T> {
  code: number | string;
  data?: T[];
  message: string;
  pagination?: Pagination;
}

/** 期數下拉 */
export async function getLotteryDropdownApi(variant: LotteryVariant) {
  return requestClient.post<LotteryDropdownRow[]>(
    `/api/dropdown/${VARIANT_API[variant].dropdown}`,
    {},
  );
}

/** 期數列表 */
export async function getLotteryPeriodApi(
  variant: LotteryVariant,
  params: { page?: number; per_page?: number; period_id: number | string },
) {
  return requestClient.post<PagedBody<LotteryPeriodRow>>(
    `/api/${VARIANT_API[variant].period}`,
    { page: 1, per_page: 3, ...params },
    { responseReturn: 'body' },
  );
}

/** 會員查詢 */
export async function getLotteryMemberApi(
  variant: LotteryVariant,
  params: {
    page?: number;
    per_page?: number;
    period_id: number | string;
    type: number | string;
    username: string;
  },
) {
  const { type, username, ...rest } = params;
  const key = Number(type) === 1 ? 'agent_username' : 'member_username';
  return requestClient.post<PagedBody<LotteryMemberRow>>(
    `/api/${VARIANT_API[variant].member}`,
    { page: 1, per_page: 20, [key]: username, ...rest },
    { responseReturn: 'body' },
  );
}

/** 彩票號碼查詢 */
export async function getLotteryTicketApi(
  variant: LotteryVariant,
  params: {
    page?: number;
    per_page?: number;
    period_id: number | string;
    ticket_number: string;
  },
) {
  return requestClient.post<PagedBody<LotteryTicketRow>>(
    `/api/${VARIANT_API[variant].ticket}`,
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 某期中獎清單 */
export async function getLotteryWinningListApi(
  variant: LotteryVariant,
  periodId: number | string,
) {
  return requestClient.post<LotteryWinningRow[]>(
    `/api/${VARIANT_API[variant].winning}`,
    { period_id: periodId },
  );
}

/** 手動開獎 */
export async function lotteryManualDrawApi(
  variant: LotteryVariant,
  payload: Record<string, any>,
) {
  return requestClient.post<{ code: number | string; message: string }>(
    `/api/${VARIANT_API[variant].manualDraw}`,
    payload,
    { responseReturn: 'body' },
  );
}
