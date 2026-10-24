import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 代理進階功能 API */

export interface RecallMessageRow {
  agent_username: string;
  content: string;
  member_username: string;
  recalled_at: string;
  type: number | string;
}

export interface RecallMessageListBody {
  code: number;
  data: RecallMessageRow[];
  message: string;
  pagination?: Pagination;
}

/** 客服撤回訊息紀錄 */
export async function getRecallMessageListApi(params: {
  agent_username?: string;
  end_time: string;
  member_username?: string;
  page: number;
  per_page: number;
  start_time: string;
}) {
  return requestClient.post<RecallMessageListBody>(
    '/api/cs/recallMessage/list',
    params,
    { responseReturn: 'body' },
  );
}
