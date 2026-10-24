import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 域名管理 API（4035）：系統管理 → 域名管理。
 *
 * 後端在 apis repo 的 Agent 面向（gateway 依 `agent/api/` 前綴轉發到 APIS_URL）。
 * 成功碼為 200（與舊 API_Agent 的 1000 不同）；分頁資料在 data.items / data.pagination。
 *
 * 管理 `domains`（後台系統自身網域，如代理後台 / 客服後台網址），
 * 與「網域/版型管理」的代理站台網域（agent_domains）為不同資料。
 */

export interface SystemDomainRow {
  /** 所屬代理編號（一域名對一代理） */
  agent_id?: null | number;
  /** 所屬代理帳號 */
  agent_username?: null | string;
  domain?: string;
  domain_name?: null | string;
  domain_type_id?: number;
  domain_type_name?: null | string;
  /** 網站 icon（favicon） */
  icon?: null | string;
  id: number;
  logo_dm?: null | string;
  logo_lm?: null | string;
  /** 該類型是否需要深色 LOGO（僅代理後台雙 LOGO） */
  needs_dark_logo?: boolean;
  /** 該類型是否需要色系（僅客服後台） */
  needs_theme_color?: boolean;
  sort?: number;
  status?: number;
  /** 色系（hex 色碼，僅客服後台使用） */
  theme_color?: null | string;
  [key: string]: any;
}

export interface SystemDomainTypeOption {
  id: number;
  name: string;
  /** 代理後台需淺色 + 深色兩組 LOGO；客服後台僅單一 LOGO */
  needs_dark_logo?: boolean;
  /** 色系僅客服後台使用 */
  needs_theme_color?: boolean;
}

/** 域名列表（域名/名稱模糊、類型與狀態精確） */
export async function getSystemDomainListApi(params?: {
  domain?: string;
  domain_name?: string;
  domain_type_id?: number | string;
  page?: number;
  per_page?: number;
  status?: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: { items?: SystemDomainRow[]; pagination?: Pagination };
    message: string;
  }>(
    '/agent/api/domain-manage/list',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 類型下拉（名稱為中文） */
export async function getSystemDomainTypeListApi() {
  return requestClient.post<{
    code: number | string;
    data?: SystemDomainTypeOption[];
    message: string;
  }>('/agent/api/domain-manage/type-list', {}, { responseReturn: 'body' });
}

/** 域名詳情 */
export async function getSystemDomainViewApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: SystemDomainRow;
    message: string;
  }>('/agent/api/domain-manage/view', { id }, { responseReturn: 'body' });
}

export interface SystemDomainSavePayload {
  /** 所屬代理編號（必填，後台依域名取設定的必要關聯） */
  agent_id: number | string;
  domain: string;
  domain_name?: null | string;
  domain_type_id: number | string;
  /** 網站 icon（favicon）：兩種後台皆適用 */
  icon?: null | string;
  /** 深色 LOGO：僅代理後台使用，客服後台後端會清空 */
  logo_dm?: null | string;
  logo_lm?: null | string;
  sort?: number | string;
  status?: number | string;
  /** 色系（hex 色碼）：僅客服後台使用，代理後台後端會清空 */
  theme_color?: null | string;
}

/** 新增域名 */
export async function createSystemDomainApi(payload: SystemDomainSavePayload) {
  return requestClient.post<{
    code: number | string;
    data?: { id?: number };
    message: string;
  }>('/agent/api/domain-manage/create', payload, { responseReturn: 'body' });
}

/** 編輯域名（含狀態切換） */
export async function updateSystemDomainApi(
  payload: SystemDomainSavePayload & { id: number | string },
) {
  return requestClient.post<{
    code: number | string;
    message: string;
  }>('/agent/api/domain-manage/update', payload, { responseReturn: 'body' });
}
