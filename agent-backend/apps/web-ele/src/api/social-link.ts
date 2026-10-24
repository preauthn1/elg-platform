import { requestClient } from '#/api/request';

/**
 * 社群入口配置 API（4106）：營運商管理 → 社群入口配置。
 *
 * 後端在 apis repo 的 Agent 面向（gateway 依 `agent/api/` 前綴轉發到 APIS_URL）。
 * 成功碼為 200（與舊 API_Agent 的 1000 不同）。
 *
 * 設定綁在總代身上，該線路下所有代理共用同一份；下線只能檢視、不另外設定。
 * 每個總代上限 10 筆。
 */

/** 連結類型：網址，前台點擊後跳轉 */
export const SOCIAL_LINK_TYPE_URL = 1;

/**
 * 連結類型：檔案，前台點擊後觸發下載（如 APK）。
 *
 * 一樣是貼網址，差別只在前台的行為——營運自行把檔案放到可公開存取的位置
 * （雲端硬碟、CDN 等）再貼連結，後台不代管檔案。
 */
export const SOCIAL_LINK_TYPE_FILE = 2;

/** 每個總代可設定的上限，與後端 AgentSocialLink::MAX_PER_AGENT 一致 */
export const SOCIAL_LINK_MAX = 10;

export interface SocialLinkRow {
  /** 前台顯示的 icon 圖片 URL */
  icon_url: null | string;
  /** 既有列才有；新增的列沒有 id */
  id?: number;
  /** 1=網址(點擊跳轉) 2=檔案(點擊下載) */
  link_type: number;
  /** 連結網址，或上傳後的檔案 URL */
  link_url: null | string;
  /** 顯示名稱 */
  name: string;
  /** 顯示順序，小的在前 */
  sort: number;
  /** 0=關閉 1=啟用 */
  status: number;
}

export interface SocialLinkListResult {
  /** 這次操作對象的總代編號；super admin 未選代理時為 null */
  agent_id: null | number;
  /**
   * 是否需要自行選擇代理。
   *
   * true = super admin 及其子帳號，要先選總代才看得到設定；
   * false = 其餘代理，歸屬總代由身分決定，前端隱藏下拉直接顯示設定。
   */
  can_select_agent: boolean;
  items: SocialLinkRow[];
}

export interface SocialLinkAgentOption {
  id: number;
  username: string;
}

/** 取社群入口配置（super admin 需帶 agentId；其餘代理不帶，後端取其歸屬總代） */
export async function getSocialLinkListApi(agentId?: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: SocialLinkListResult;
    message: string;
  }>('/agent/api/social-link/list', agentId ? { agent_id: agentId } : {}, {
    responseReturn: 'body',
  });
}

/**
 * 整批儲存。
 *
 * 送完整清單：既有列帶 id、新增列不帶；清單中沒有的既有列會被刪除。
 */
export async function saveSocialLinkApi(params: {
  agent_id?: number | string;
  items: SocialLinkRow[];
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/agent/api/social-link/save',
    params,
    { responseReturn: 'body' },
  );
}

/** 總代下拉（僅 super admin 有資料，其餘代理回空陣列） */
export async function getSocialLinkAgentDropdownApi() {
  return requestClient.post<{
    code: number | string;
    data?: SocialLinkAgentOption[];
    message: string;
  }>('/agent/api/social-link/agent-dropdown', {}, { responseReturn: 'body' });
}
