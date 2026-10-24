import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 網域樣板管理（domain）API
 * 網域/樣板 index/edit。 */

export interface DomainTemplateRow {
  add_home_image?: string;
  agent_id: number | string;
  default_invitation_code?: string;
  domain: string;
  icon?: string;
  id: number | string;
  logo_mobile?: string;
  logo_pc?: string;
  mobile_templates?: number | string;
  need_login?: number | string;
  organization_name?: string;
  pc_templates?: number | string;
  status: number | string;
  template_id?: number | string;
  title?: string;
  updated_at?: string;
  username?: string;
}

export interface DomainTemplateBody {
  code: number;
  data: DomainTemplateRow[];
  message: string;
  pagination?: Pagination;
}

/** 網域列表 */
export async function getDomainTemplateListApi() {
  return requestClient.post<DomainTemplateBody>(
    '/api/agentDomain/list',
    { page: 1, per_page: 1000 },
    { responseReturn: 'body' },
  );
}

/** 網域詳細 */
export async function getDomainTemplateViewApi(id: number | string) {
  return requestClient.post<DomainTemplateRow>('/api/agentDomain/view', { id });
}

export interface DomainTemplateOption {
  id: number | string;
  name: string;
  thumbnail?: string;
}

/** 樣板清單 */
export async function getDomainTemplateOptionsApi() {
  return requestClient.post<DomainTemplateOption[]>('/api/template/list', {});
}

export interface DomainTemplatePayload {
  add_home_image?: string;
  default_invitation_code?: string;
  domain: string;
  icon?: string;
  id?: number | string;
  logo_mobile?: string;
  logo_pc?: string;
  mobile_templates?: number | string;
  need_login: number | string;
  pc_templates?: number | string;
  status: number | string;
  template_id?: number | string;
  title?: string;
}

export interface DomainTemplateActionResult {
  code: number;
  data?: unknown;
  message: string;
}

/** 儲存網域樣板 */
export async function saveDomainTemplateApi(payload: DomainTemplatePayload) {
  return requestClient.post<DomainTemplateActionResult>(
    '/api/agentDomain/setting',
    {
      ...payload,
      // template_id 未指定時用 mobile_templates
      template_id: payload.template_id ?? payload.mobile_templates,
    },
    { responseReturn: 'body' },
  );
}

/** 清除 Redis */
export async function redisFlushDbApi() {
  return requestClient.post<DomainTemplateActionResult>(
    '/api/internal-helper/redis/flushDB',
    {},
    { responseReturn: 'body' },
  );
}
