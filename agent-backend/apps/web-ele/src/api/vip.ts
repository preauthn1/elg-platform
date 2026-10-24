import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/** VIP 功能 API。 */

// ---------- VIP 設定列表 ----------
export interface VipListRow {
  id: number;
  image?: string;
  is_downgrade?: number;
  keep_amount?: number | string;
  level?: number | string;
  name?: string;
  target_amount?: number | string;
  withdrawal_amount_limit?: number | string;
  withdrawal_times_limit?: number | string;
  [key: string]: any;
}

export async function getVipListApi(params?: {
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<{
    code: number | string;
    data?: VipListRow[];
    message: string;
    pagination?: Pagination;
  }>(
    '/api/vip/list',
    { page: 1, per_page: 100, ...params },
    {
      responseReturn: 'body',
    },
  );
}

/** 批次更新 VIP 等級 */
export async function updateVipLevelApi(
  levelData: Record<string, number | string>,
) {
  return requestClient.post<{
    code: number | string;
    data?: unknown;
    message: string;
  }>(
    '/api/vip/update_vip_level',
    { level_data: levelData },
    {
      responseReturn: 'body',
    },
  );
}

/** 其他支援列表 */
export async function getVipOtherSupportApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: Array<number | string>;
    message: string;
  }>('/api/vip/other_support', { id }, { responseReturn: 'body' });
}

// ---------- VIP 內容（新增/編輯） ----------
export interface VipViewData {
  exclusive_service?: number;
  id?: number;
  image?: null | string;
  is_downgrade?: number;
  keep_amount?: number | string;
  level?: number | string;
  name?: string;
  target_amount?: number | string;
  vip_channel?: number;
  vip_names?: Array<{
    image?: null | string;
    language_id: number;
    name: string;
  }>;
  withdrawal_amount_limit?: number | string;
  withdrawal_times_limit?: number | string;
  withdrawal_types?: number[];
  [key: string]: any;
}

export async function getVipViewApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: VipViewData;
    message: string;
  }>('/api/vip/view', { id }, { responseReturn: 'body' });
}

/** 新增/編輯 VIP 內容；有 id 為編輯 */
export async function saveVipContentApi(
  data: Record<string, any>,
  isEdit: boolean,
) {
  return requestClient.post<{
    code: number | string;
    data?: any;
    message: string;
  }>(isEdit ? '/api/vip/edit' : '/api/vip/add', data, {
    responseReturn: 'body',
  });
}

/** base64 圖片上傳，回 advertise_image_id */
export async function uploadVipImageApi(params: {
  agent_id: number | string;
  base64Image: string;
  type: number;
}) {
  return requestClient.post<{
    code: number | string;
    data?: { advertise_image_id: number };
    message: string;
  }>('/api/image/base64/upload', params, { responseReturn: 'body' });
}

// ---------- 獎勵設定 ----------
export interface VipRewardRow {
  condition?: number | string;
  expired_hour?: number | string;
  flow_limit_multiple?: number | string;
  is_reached?: number;
  max_point?: number | string;
  point?: number | string;
  point_percentage?: number | string;
  point_type?: number;
  status?: number;
  type?: number;
  updated_at?: string;
  vip_content_reward_id: number;
  vip_reward_id: number;
  vip_reward_name?: string;
  [key: string]: any;
}

export async function getVipRewardListApi(vipContentId: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: VipRewardRow[];
    message: string;
  }>(
    '/api/vip/vip_reward',
    { vip_content_id: vipContentId },
    {
      responseReturn: 'body',
    },
  );
}

export interface VipRewardDetail {
  condition?: number | string;
  expired_hour?: number | string;
  flow_limit_multiple?: number | string;
  max_point?: number | string;
  point?: number | string;
  point_percentage?: number | string;
  point_type?: number;
  status?: number;
  type?: number;
  vip_content_id?: number;
  vip_content_reward_id?: number;
  vip_reward_id?: number;
  vip_reward_name?: string;
  [key: string]: any;
}

export async function getVipRewardDetailApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: VipRewardDetail;
    message: string;
  }>('/api/vip/vip_reward_detail', { id }, { responseReturn: 'body' });
}

/** 新增/更新獎勵；id=0 為新增 */
export async function saveVipRewardApi(data: Record<string, any>) {
  const isCreate = Number(data.id) === 0;
  return requestClient.post<{
    code: number | string;
    data?: any;
    message: string;
  }>(isCreate ? '/api/vip/create_reward' : '/api/vip/update_reward', data, {
    responseReturn: 'body',
  });
}

// ---------- 返水佔成 ----------
export interface VipRebateRow {
  game_type_id: number;
  id: number;
  proportion: number | string;
  [key: string]: any;
}

export async function getVipRebateApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: VipRebateRow[];
    message: string;
  }>('/api/vip/rebate_percentage', { id }, { responseReturn: 'body' });
}

export async function updateVipRebateApi(data: {
  game_type_id: Array<number | string>;
  ids: Array<number | string>;
  proportion: Array<number | string>;
  vip_content_id: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: any;
    message: string;
  }>('/api/vip/update_rebate_percentage', data, { responseReturn: 'body' });
}

// ---------- VIP 會員查詢 ----------
export interface VipMemberRow {
  /** 累積經驗值（target/tickets#4484）；target_amount 為 0 時顯示「-」 */
  deposit_amount?: number | string;
  id: number;
  /** 保級經驗值（target/tickets#4484）：keep_amount > need_keep_amount 綠字，否則紅字 */
  keep_amount?: number | string;
  limit_withdrawal_times?: number | string;
  need_keep_amount?: number | string;
  now_times?: number | string;
  target_amount?: number | string;
  username?: string;
  vip_name?: string;
  [key: string]: any;
}

export async function getVipMemberSearchApi(params: {
  fuzzy_query?: number;
  page?: number;
  per_page?: number;
  username?: string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: VipMemberRow[];
    message: string;
    pagination?: Pagination;
  }>(
    '/api/vip/member_search',
    { page: 1, per_page: 50, ...params },
    {
      responseReturn: 'body',
    },
  );
}

// ---------- 稽核流水（modal） ----------
export interface VipFlowLimitRow {
  amount?: number | string;
  created_at?: string;
  flow_limit?: number | string;
  id: number;
  status?: number;
  type?: number;
  updated_at?: string;
  valid_flow_limit?: number | string;
  [key: string]: any;
}

export async function getVipFlowLimitApi(params: {
  member_id: number | string;
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<{
    code: number | string;
    data?: VipFlowLimitRow[];
    message: string;
    pagination?: Pagination;
  }>(
    '/api/vip/flow_limit',
    { page: 1, per_page: 10, ...params },
    {
      responseReturn: 'body',
    },
  );
}

export async function cancelVipFlowLimitApi(params: {
  id: number | string;
  remark: string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: any;
    message: string;
  }>('/api/vip/cancel_vip_flow_limit', params, { responseReturn: 'body' });
}

// ---------- VIP 變更紀錄（modal） ----------
export interface VipChangeRecordRow {
  before_vip_name?: string;
  created_at?: string;
  vip_name?: string;
  [key: string]: any;
}

export async function getVipChangeRecordApi(params: {
  member_id: number | string;
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<{
    code: number | string;
    data?: VipChangeRecordRow[];
    message: string;
    pagination?: Pagination;
  }>(
    '/api/vip/vip_change_record',
    { page: 1, per_page: 10, ...params },
    {
      responseReturn: 'body',
    },
  );
}

// ---------- 經驗值變動紀錄（modal，target/tickets#4484） ----------
export interface VipExpLogRow {
  after_deposit_amount?: number | string;
  after_keep_amount?: number | string;
  before_deposit_amount?: number | string;
  before_keep_amount?: number | string;
  created_at?: string;
  /** 變更經驗值：正數綠、負數紅 */
  exp?: number | string;
  [key: string]: any;
}

export async function getVipExpLogApi(params: {
  member_id: number | string;
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<{
    code: number | string;
    data?: VipExpLogRow[];
    message: string;
    pagination?: Pagination;
  }>(
    '/api/vip/member_exp_log',
    { page: 1, per_page: 10, ...params },
    {
      responseReturn: 'body',
    },
  );
}

// ---------- VIP 獎勵紀錄 ----------
export interface VipRewardReportRow {
  created_at?: string;
  point?: number | string;
  point_type?: number;
  status?: number;
  type?: number;
  updated_at?: string;
  username?: string;
  vip_name?: string;
  [key: string]: any;
}

export async function getVipRewardReportApi(params: {
  end_time: string;
  fuzzy_query?: number;
  page?: number;
  per_page?: number;
  reward_id?: number | string;
  start_time: string;
  status?: number | string;
  time_type?: number | string;
  username?: string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: VipRewardReportRow[];
    message: string;
    pagination?: Pagination;
  }>(
    '/api/vip/reward_report',
    { page: 1, per_page: 20, ...params },
    {
      responseReturn: 'body',
    },
  );
}
