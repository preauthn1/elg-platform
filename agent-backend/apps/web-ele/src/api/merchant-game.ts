import { requestClient } from '#/api/request';

/** 商戶遊戲資訊管理 API（vendor_info/game）*/

interface MerchantGameApiBody<T = any> {
  code: number;
  data?: T;
  message?: string;
  pagination?: { current?: number; pages?: number; total?: number };
}

/** 商戶遊戲資訊列表列 */
export interface MerchantGameRow {
  bettable?: number;
  /** 遊戲代碼（games.game_type，target/tickets#4153） */
  game_code?: string;
  game_id?: number;
  game_image?: string;
  game_name?: string;
  status?: number;
  vendor_id?: number;
  vendor_info_id?: number;
  vendor_info_name?: string;
  vendor_name?: string;
  vig_id: number;
}

/** 商戶遊戲資訊單筆明細 */
export interface MerchantGameDetail {
  bettable?: number;
  calc_flow_limit?: number;
  game_name?: string;
  is_hot?: number;
  is_new?: number;
  join_bet_event?: number;
  join_win_event?: number;
  sort?: number | string;
  status?: number;
  valid_bet_rate?: number | string;
  vendor_name?: string;
  vig_id: number;
}

/**
 * 遊戲商個性化設置的一個前台入口版位（vendor_customized_items 一列，target/tickets#4373）
 *
 * status：0 停用／1 啟用／2 敬請期待
 */
export interface MerchantGameCustomizedItem {
  id: number;
  /** 入口類型，對應語系 key game_vendor_entrance_type_{item_type} */
  item_type: number;
  /** 版位名稱；後端在 vci.name 為 null 時已 fallback 成廠商名稱 */
  name: string;
  status: number;
}

/**
 * 查無資料時用來解釋原因的個性化設置資料（target/tickets#4373）
 *
 * 後端只在「清單為空」且「有帶 vendor_id」且「組別有效」時附上，其餘情況為 null。
 */
export interface MerchantGameCustomizedItems {
  items: MerchantGameCustomizedItem[];
  vendor: { id: number; name: string };
  /** 該廠商在本組織綁定的全部商戶，含停用者（status 0 停用／1 啟用；可 0～多筆） */
  vendor_infos: Array<{ id: number; name: string; status: number }>;
}

/** 商戶遊戲資訊列表回應（比通用信封多一個 customized_items） */
export interface MerchantGameListBody extends MerchantGameApiBody<
  MerchantGameRow[]
> {
  customized_items?: MerchantGameCustomizedItems | null;
}

/** 商戶遊戲資訊列表 */
export async function getMerchantGameListApi(params: {
  bettable?: number | string;
  game_code?: number | string;
  group_id?: number | string;
  name?: string;
  page?: number;
  per_page?: number;
  status?: number | string;
  vendor_id?: number | string;
}) {
  return requestClient.post<MerchantGameListBody>(
    '/api/vendor_info/gameList',
    { page: 1, per_page: 50, ...params },
    { responseReturn: 'body' },
  );
}

/** 商戶遊戲資訊單筆 */
export async function getMerchantGameApi(data: {
  group_id?: number | string;
  vig_id: number | string;
}) {
  return requestClient.post<MerchantGameApiBody<MerchantGameDetail>>(
    '/api/vendor_info/getGame',
    data,
    { responseReturn: 'body' },
  );
}

/** 切換單筆商戶遊戲的狀態/可投注欄位 */
export async function toggleMerchantGameFieldApi(data: {
  field: 'bettable' | 'status';
  group_id?: number | string;
  value: 0 | 1;
  vig_id: number | string;
}) {
  return requestClient.post<MerchantGameApiBody>(
    '/api/vendor_info/toggleGameField',
    data,
    { responseReturn: 'body' },
  );
}

/** 批次更新選取列的狀態/可投注欄位 */
export async function batchUpdateMerchantGamesApi(data: {
  field: 'bettable' | 'status';
  group_id?: number | string;
  value: 0 | 1;
  vig_ids: number[];
}) {
  return requestClient.post<MerchantGameApiBody>(
    '/api/vendor_info/batchUpdateGames',
    data,
    { responseReturn: 'body' },
  );
}

/** 儲存商戶遊戲設定 */
export async function saveMerchantGameApi(data: {
  bettable: 0 | 1;
  calc_flow_limit: 0 | 1;
  group_id?: number | string;
  is_hot: 0 | 1;
  is_new: 0 | 1;
  join_bet_event: 0 | 1;
  join_win_event: 0 | 1;
  sort: number | string;
  status: 0 | 1;
  valid_bet_rate: number | string;
  vig_id: number | string;
}) {
  return requestClient.post<MerchantGameApiBody>(
    '/api/vendor_info/updateGame',
    data,
    { responseReturn: 'body' },
  );
}
