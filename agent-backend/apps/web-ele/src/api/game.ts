import { requestClient } from '#/api/request';

/** 遊戲管理 API。 */

export interface GameApiBody<T = any> {
  code: number;
  data?: T;
  message: string;
  pagination?: { current: number; pages: number; total: number };
}

/** 遊戲列表列 */
export interface GameManageRow {
  game_code?: string;
  game_name?: string;
  game_type?: string;
  id: number;
  image?: string;
  remark?: string;
  status?: number;
  vendor_name?: string;
  [key: string]: any;
}

/** 遊戲單筆 */
export interface GameDetail {
  game_type?: string;
  id?: number | string;
  image?: string;
  memo?: string;
  name?: string;
  names?: Array<{ language_id: number | string; name: string }>;
  remark?: string;
  rtp?: number | string;
  sort?: number | string;
  status?: number | string;
  tags?: Array<number | string>;
  type_id?: number | string;
  vendor_id?: number | string;
  [key: string]: any;
}

// ---------------------------------------------------------------------------
// game/index 列表 + 啟停
// ---------------------------------------------------------------------------

/** 遊戲列表 */
export async function getVendorGameListApi(data: {
  game_code?: number | string;
  game_type?: number | string;
  id?: number | string;
  name?: string;
  page: number;
  per_page: number;
  status?: number | string;
  vendor_id?: number | string;
}) {
  return requestClient.post<GameApiBody<GameManageRow[]>>(
    '/api/vendor/gameList',
    data,
    { responseReturn: 'body' },
  );
}

/** 切換遊戲狀態 */
export async function gameToggleStatusApi(data: {
  id: number | string;
  status: number | string;
}) {
  return requestClient.post<GameApiBody>('/api/game/edit', data, {
    responseReturn: 'body',
  });
}

/**
 * 批次匯入遊戲：取 .json 檔內容轉純 JSON 字串，
 * {datas:<檔字串>} POST 到 api/game/bulk_edit/{vendor_id}（非 multipart）。 */
export async function bulkEditGamesApi(
  vendorId: number | string,
  datas: string,
) {
  return requestClient.post<GameApiBody>(
    `/api/game/bulk_edit/${vendorId}`,
    { datas },
    { responseReturn: 'body' },
  );
}

// ---------------------------------------------------------------------------
// game/edit 新增 / 編輯
// ---------------------------------------------------------------------------

/** 遊戲單筆 */
export async function getGameViewApi(id: number | string) {
  return requestClient.post<GameApiBody<GameDetail>>(
    '/api/game/view',
    { id, page: 1, per_page: 1000 },
    { responseReturn: 'body' },
  );
}

/** 儲存遊戲 */
export async function saveGameApi(data: Record<string, any>) {
  const path = data.id ? '/api/game/edit' : '/api/game/add';
  return requestClient.post<GameApiBody>(path, data, {
    responseReturn: 'body',
  });
}

// ---------------------------------------------------------------------------
// game/sort 拖拉排序
// ---------------------------------------------------------------------------

/** 某商戶可排序遊戲清單 */
export async function getVendorInfoGameListApi(data: {
  status: number | string;
  vendor_id: number | string;
  vendor_info_id: number | string;
}) {
  return requestClient.post<
    Array<{
      game_image?: string;
      game_name?: string;
      id: number;
      type_id?: number | string;
    }>
  >('/api/vendor_info/getGameList', data);
}

/** 遊戲標籤下拉 */
export async function getGameTagDropdownApi() {
  return requestClient.post<Array<{ id: number; tag_name: string }>>(
    '/api/dropdown/game_tag',
  );
}

/** 依標籤取遊戲 */
export async function getGameListByTagApi(data: {
  page: number;
  per_page: number;
  status: number | string;
  tag_id: number | string;
}) {
  return requestClient.post<
    Array<{
      aggt_id?: number;
      aggt_sort?: number;
      id: number;
      image?: string;
      name?: string;
      type_id?: number | string;
    }>
  >('/api/game/list', data);
}

/** 更新商戶遊戲排序 */
export async function updateVendorInfoGameSortApi(data: {
  ids: string;
  vendor_info_id: number | string;
}) {
  return requestClient.post<GameApiBody>(
    '/api/vendor_info/updateGameSort',
    data,
    { responseReturn: 'body' },
  );
}

/** 更新遊戲標籤排序 */
export async function updateGameTagSortApi(data: {
  ids: string;
  tag_id: number | string;
}) {
  return requestClient.post<GameApiBody>('/api/game/updateGameTagSort', data, {
    responseReturn: 'body',
  });
}
