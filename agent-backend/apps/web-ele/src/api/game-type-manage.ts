import { requestClient } from '#/api/request';

/**
 * 遊戲類別管理 API。 */

export interface GameTypeManageRow {
  id: number | string;
  name?: string;
  parent?: number | string;
  status?: number;
}

export interface GameTypeManageDetail extends GameTypeManageRow {
  names?: Array<{ language_id: number | string; name: string }>;
}

export async function getGameTypeManageListApi() {
  return requestClient.post<GameTypeManageRow[]>('/api/gameType/list', {});
}

export async function getGameTypeViewApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: GameTypeManageDetail;
    message: string;
  }>('/api/gameType/view', { id }, { responseReturn: 'body' });
}

/** 新增/編輯遊戲類別 */
export async function saveGameTypeApi(payload: {
  id: number | string;
  name: string;
  names?: Record<string, string>;
  parent: number | string;
  status: number;
}) {
  const isAdd = !payload.id || Number(payload.id) === 0;
  return requestClient.post<{ code: number | string; message: string }>(
    isAdd ? '/api/gameType/add' : '/api/gameType/edit',
    payload,
    { responseReturn: 'body' },
  );
}

export async function toggleGameTypeStatusApi(
  id: number | string,
  status: number,
) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/gameType/edit',
    { id, status },
    { responseReturn: 'body' },
  );
}
