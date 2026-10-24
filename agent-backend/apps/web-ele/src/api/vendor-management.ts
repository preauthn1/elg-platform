import { requestClient } from '#/api/request';

/**
 * 廠商管理 API（工單 4400）：遊戲商管理 → 廠商管理。
 *
 * 後端在 apis repo 的 Agent 面向（gateway 依 `agent/api/` 前綴轉發到 APIS_URL）。
 * 成功碼為 200（與舊 API_Agent 的 1000 不同）。
 *
 * 列表改由 apis 提供，多帶錢包模式、通道、gamelist API 與回調路徑；編輯彈窗的
 * 基本資料讀寫仍走舊 API_Agent（見 `vendor.ts`），只有回調路徑走這裡。
 * 舊的 `getVendorManagementListApi`（API_Agent）業績查詢頁仍在用，保留不動。
 *
 * 權限：回調路徑只有帳號角色 admin 能看與改。後端以列表的 `callback_visible` 告知，
 * 為 false 時每列不帶四個回調欄位、也不帶 `callback_base_url`，且 callback-path
 * 端點會拒絕；前端一律以 `callback_visible` 為準，不自行判斷角色。
 */

/** 廠商管理列表列 */
export interface VendorManagementItem {
  app_icon?: string;
  /**
   * 是否可設定回調路徑：無縫錢包且 apis 有掛 driver 才為 true。
   * 本欄與 callback_path、callback_url、default_callback_path 只在
   * callback_visible 為 true 時才會回傳
   */
  callback_applicable?: boolean;
  /** 目前生效的回調路徑後綴；不適用或未設定時為 null */
  callback_path?: null | string;
  /** 完整回調網址（callback_base_url + callback_path） */
  callback_url?: null | string;
  /** 'direct' = 直連，其餘為通道名（如 TitanHub）；null = apis 沒掛 driver */
  channel: null | string;
  /** 程式宣告的預設回調路徑後綴；回調路徑留空時即用這個；沒有時為 null */
  default_callback_path?: null | string;
  /** 是否有 gamelist API；null = apis 沒掛 driver，無從判斷 */
  has_game_list: boolean | null;
  id: number;
  image?: string;
  is_single_wallet?: number | string;
  name?: string;
  percentage?: number | string;
  rate?: number | string;
  remark?: string;
  sort?: number | string;
  status?: number | string;
  valid_bet_rate?: number | string;
  /** 錢包模式：single = 單一（無縫）錢包，transfer = 轉帳錢包 */
  wallet_mode: 'single' | 'transfer';
}

export interface VendorManagementListResult {
  /** 回調網址的主機部分，例 https://example.invalid；callback_visible 為 false 時不回傳 */
  callback_base_url?: string;
  /** 目前使用者能否看到與修改回調路徑（後端依帳號角色判定，僅 admin 為 true） */
  callback_visible: boolean;
  /** 通道清單，例 ['direct', 'TitanHub', 'EliteHub'] */
  channels: string[];
  items: VendorManagementItem[];
}

/** 廠商管理列表（含通道清單與回調網址主機） */
export async function fetchVendorManagementListApi() {
  return requestClient.post<{
    code: number | string;
    data?: VendorManagementListResult;
    message: string;
  }>('/agent/api/vendor-management/list', {}, { responseReturn: 'body' });
}

/**
 * 更新遊戲商回調路徑。
 * `callback_path` 傳空字串＝退回預設路徑；非空須以 `/` 開頭（後端驗證）。
 */
export async function updateVendorCallbackPathApi(data: {
  callback_path: string;
  id: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/agent/api/vendor-management/callback-path',
    data,
    { responseReturn: 'body' },
  );
}
