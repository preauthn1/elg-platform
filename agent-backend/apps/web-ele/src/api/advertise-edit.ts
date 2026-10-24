import { requestClient } from '#/api/request';

/**
 * 廣告編輯器 advertise/edit 專屬 API。
 * advertise/view、dropdown/general_agent 重用 event-write.ts；
 * languages/advertisementPath/agent dropdown/vendor/gameType 重用各自模組。 */

/**
 * 遊戲列表（target/tickets#4167）。
 * 原本 per_page 寫死 10,000 一次撈全站（約 3MB 未壓縮），連結至遊戲的編輯
 * 會渲染上萬個選項凍死分頁。改為必帶 vendor_id、支援名稱關鍵字，最多 50 筆，
 * 由編輯彈窗以「先選遊戲商 → 遠端搜尋」兩段式取用。
 */
export async function getAdvertiseGameListApi(params: {
  name?: string;
  vendor_id: number | string;
}) {
  return requestClient.post<
    Array<{ id: number | string; name: string; vendor_id: number | string }>
  >('/api/game/list', { page: 1, per_page: 50, ...params });
}

/** 內部連結類型 */
export async function getAdvertiseInternalTypesApi() {
  return requestClient.post<Array<{ parameter: string }>>('/api/param/list', {
    key: 'advertise_internal',
  });
}

/** 儲存廣告（破壞性；無 id→api/advertise/add，有 id→api/advertise/edit）。
 *  payload 多型：type 1 game_type/2 game/3 vendor/4 external(json)+open_window/5 internal；
 *  每語系 image[lang]/image_id[lang]/image_pc[lang]/image_pc_id[lang]。 */
export async function saveAdvertiseApi(
  data: Record<string, any>,
  isEdit: boolean,
) {
  return requestClient.post<{ code: number | string; message: string }>(
    isEdit ? '/api/advertise/edit' : '/api/advertise/add',
    data,
    { responseReturn: 'body' },
  );
}
