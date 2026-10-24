import { requestClient } from '#/api/request';

/**
 * 代理群組遊戲商自訂項設定（agent/vendor_customized_items_setting，
 * 從 group_list 的 type5 遊戲商群組帶 group_id 進入）。 */

interface BodyOf<T> {
  code: number | string;
  data?: T;
  message: string;
}

/** 自訂項目卡 */
export interface VendorCustomizedItemRow {
  front_page_image?: string;
  game_id?: number | string;
  id: number | string;
  image?: string;
  is_event?: boolean | number;
  is_hot?: boolean | number;
  is_new?: boolean | number;
  item_type?: number | string;
  name?: string;
  path?: string;
  status?: number | string;
  type_id?: number | string;
  vendor_id?: number | string;
}

/** 遊戲商下拉選項 */
export interface VendorCustomizedVendorOption {
  id: number | string;
  image?: string;
  name?: string;
  [key: string]: any;
}

/** 遊戲類型下拉選項 */
export interface VendorCustomizedGameType {
  id: number | string;
  name?: string;
}

/** 取群組自訂項清單 */
export async function getVendorCustomizedItemListApi(groupId: number | string) {
  return requestClient.post<
    BodyOf<{
      game_types?: VendorCustomizedGameType[];
      group?: { id?: number | string; name?: string };
      items?: VendorCustomizedItemRow[];
      vendors?: VendorCustomizedVendorOption[];
    }>
  >(
    '/api/agent/vendor_customized_item/list',
    { group_id: groupId },
    { responseReturn: 'body' },
  );
}

/** 取單一項目 */
export async function getVendorCustomizedItemApi(id: number | string) {
  return requestClient.post<
    BodyOf<{
      item?: VendorCustomizedItemRow;
      vendor?: VendorCustomizedVendorOption;
    }>
  >(
    '/api/agent/vendor_customized_item/get_item',
    { id },
    { responseReturn: 'body' },
  );
}

/** 依遊戲商取遊戲清單 */
export async function getVendorCustomizedGamesApi(vendorId: number | string) {
  return requestClient.post<{
    code: number | string;
    games?: Array<{ id: number | string; name?: string }>;
    message: string;
  }>(
    '/api/agent/vendor_customized_item/get_games',
    { vendor_id: vendorId },
    { responseReturn: 'body' },
  );
}

/** 儲存項目（is_new/is_hot/is_event 端點只認 'on' 為開啟） */
export async function saveVendorCustomizedItemApi(params: {
  game_id?: number | string;
  group_id: number | string;
  id?: number | string;
  is_event?: boolean;
  is_hot?: boolean;
  is_new?: boolean;
  item_type?: number | string;
  name?: string;
  path?: string;
  status?: number | string;
  type_id?: number | string;
  vendor_front_page_image?: string;
  vendor_id?: number | string;
  vendor_image?: string;
}) {
  const { is_event, is_hot, is_new, ...rest } = params;
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/agent/vendor_customized_item/save_item',
    {
      ...rest,
      is_event: is_event ? 'on' : '',
      is_hot: is_hot ? 'on' : '',
      is_new: is_new ? 'on' : '',
    },
    { responseReturn: 'body' },
  );
}

/** 刪除項目 */
export async function removeVendorCustomizedItemApi(id: number | string) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/agent/vendor_customized_item/remove_item',
    { id },
    { responseReturn: 'body' },
  );
}

/** 更新排序 */
export async function updateVendorCustomizedSortsApi(params: {
  group_id: number | string;
  ids: string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/agent/vendor_customized_item/update_sorts',
    params,
    { responseReturn: 'body' },
  );
}

/** 內部連結類型 */
export async function getVendorCustomizedInternalTypesApi() {
  return requestClient.post<BodyOf<Array<{ parameter: string }>>>(
    '/api/param/list',
    { key: 'advertise_internal' },
    { responseReturn: 'body' },
  );
}
