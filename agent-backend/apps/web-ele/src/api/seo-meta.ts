import { requestClient } from '#/api/request';

/**
 * SEO 元數據 API（4102）：推廣管理 → SEO 元數據。
 *
 * 後端在 apis repo 的 Agent 面向（gateway 依 `agent/api/` 前綴轉發到 APIS_URL）。
 * 成功碼為 200（與舊 API_Agent 的 1000 不同）。
 *
 * 設定綁在域名上：總代底下每個已登記的網域各存一份。可把其中一個域名標為
 * 「預設回退」，該線其他沒有自己設定的域名改用它的設定；同一總代只能有一個
 * 預設，後端存檔時會自動把前一個預設關掉。
 */

/** 可設定 SEO 的前台頁面代碼，表格依此順序建列 */
export const SEO_META_PAGES = ['home'] as const;

export type SeoMetaPage = (typeof SEO_META_PAGES)[number];

export interface SeoMetaRow {
  /** 頁面描述（meta description） */
  description: string;
  /** 關鍵字，多個以逗號分隔（meta keywords） */
  keywords: string;
  /** 頁面代碼 */
  page: SeoMetaPage;
  /** 頁面標題（title） */
  title: string;
}

export interface SeoMetaListResult {
  /** 這次查詢的域名編號 */
  agent_domain_id: number;
  /** 這次操作對象的總代編號；super admin 未選代理時為 null */
  agent_id: null | number;
  /**
   * 是否需要自行選擇代理。
   *
   * true = super admin 及其子帳號，要先選總代才列得出域名；
   * false = 其餘代理，歸屬總代由身分決定，前端隱藏代理下拉。
   */
  can_select_agent: boolean;
  /** 此域名是否為該總代線的預設回退：0=否 1=是 */
  is_default: number;
  items: SeoMetaRow[];
}

export interface SeoMetaAgentOption {
  id: number;
  username: string;
}

export interface SeoMetaDomainOption {
  domain: string;
  id: number;
}

/** 總代下拉（僅 super admin 有資料，其餘代理回空陣列） */
export async function getSeoMetaAgentDropdownApi() {
  return requestClient.post<{
    code: number | string;
    data?: SeoMetaAgentOption[];
    message: string;
  }>('/agent/api/seo-meta/agent-dropdown', {}, { responseReturn: 'body' });
}

/** 域名下拉（super admin 需帶 agentId；其餘代理不帶，後端取其歸屬總代的已登記網域） */
export async function getSeoMetaDomainDropdownApi(agentId?: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: SeoMetaDomainOption[];
    message: string;
  }>(
    '/agent/api/seo-meta/domain-dropdown',
    agentId ? { agent_id: agentId } : {},
    { responseReturn: 'body' },
  );
}

/** 取指定域名的 SEO 設定 */
export async function getSeoMetaListApi(params: {
  agent_domain_id: number | string;
  agent_id?: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: SeoMetaListResult;
    message: string;
  }>('/agent/api/seo-meta/list', params, { responseReturn: 'body' });
}

/**
 * 整批儲存指定域名的 SEO 設定。
 *
 * is_default 為 true 時，後端會把同一總代線原本的預設域名關掉。
 */
export async function saveSeoMetaApi(params: {
  agent_domain_id: number | string;
  agent_id?: number | string;
  is_default: boolean;
  items: SeoMetaRow[];
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/agent/api/seo-meta/save',
    params,
    { responseReturn: 'body' },
  );
}
