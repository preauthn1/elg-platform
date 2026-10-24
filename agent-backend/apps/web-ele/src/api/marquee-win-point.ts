import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/** 贏分跑馬燈 marquee_win_point API */

export interface MarqueeWinPointRow {
  agent_name: string;
  exclude_agent_name: string;
  id: number | string;
  status: number | string;
  trigger_amount: number | string;
  vendor_name: string;
}

/** 贏分跑馬燈列表 */
export async function getMarqueeWinPointListApi(params: {
  page: number;
  per_page: number;
  status?: number | string;
  type?: number | string;
}) {
  return requestClient.post<{
    code: number;
    data: MarqueeWinPointRow[];
    message: string;
    pagination?: Pagination;
  }>('/api/marquee/win_point/list', params, { responseReturn: 'body' });
}

/** 贏分跑馬燈詳細 */
export async function getMarqueeWinPointViewApi(id: number | string) {
  return requestClient.post<{
    marquee_win_points: {
      agent_id: number | string;
      agent_name: string;
      exclude_agent_id: number | string;
      exclude_agent_name: string;
      status: number | string;
      trigger_amount: number | string;
      vendor_id: number | string;
    };
  }>('/api/marquee/win_point/view', { id });
}

/** 儲存贏分跑馬燈 */
export async function saveMarqueeWinPointApi(payload: {
  agent_id: number | string;
  exclude_agent_id: number | string;
  id?: number | string;
  status: number | string;
  trigger_amount: number | string;
  vendor_id: number | string;
}) {
  const url = payload.id
    ? '/api/marquee/win_point/edit'
    : '/api/marquee/win_point/add';
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    url,
    payload,
    { responseReturn: 'body' },
  );
}

/** 贏分跑馬燈啟用/停用 */
export async function toggleMarqueeWinPointApi(
  id: number | string,
  status: number | string,
) {
  return requestClient.post<{ code: number; message: string }>(
    '/api/marquee/win_point/toggleStatus',
    { id, status },
    { responseReturn: 'body' },
  );
}
