import { requestClient } from '#/api/request';

/** 優惠券批次列表列 */
export interface CouponBatchRow {
  agent_username?: string;
  amount?: number | string;
  batch_code?: string;
  created_at?: string;
  id: number | string;
  quantity?: number | string;
  /** 1:固定金額 2:隨機獎勵 */
  reward_mode?: number | string;
  status?: number | string;
  type?: number | string;
  used_count?: number | string;
}

/** 隨機獎勵獎項（建立時送出 / 詳細回傳）。 */
export interface CouponPrize {
  amount: number | string;
  /** 詳細回傳：已派發數量 */
  awarded?: number | string;
  flow_limit_multiple: number | string;
  id?: number | string;
  /** 建立送出：{ language_id: url }；詳細回傳：[{ language_id, image_url }] */
  images?:
    | Array<{ image_url: string; language_id: number | string }>
    | Record<string, string>;
  point_type: number | string;
  quantity: number | string;
  weight: number | string;
}

/** 批次列表 */
export async function getCouponListApi(params: {
  agent_id?: number | string;
  batch_code?: string;
  code?: string;
}) {
  return requestClient.post<CouponBatchRow[]>('/api/coupon/list', params);
}

/** 批次詳細 */
export interface CouponDetail {
  agent_username?: string;
  all_downline?: number | string;
  amount?: number | string;
  batch_code?: string;
  created_at?: string;
  end_time?: string;
  flow_limit_multiple?: number | string;
  id: number | string;
  mobile_daily_limit?: number | string;
  quantity?: number | string;
  realname_required?: number | string;
  receive_limit?: number | string;
  /** 隨機獎勵獎池（reward_mode=2 時有值） */
  prizes?: CouponPrize[];
  /** 1:固定金額 2:隨機獎勵 */
  reward_mode?: number | string;
  start_time?: string;
  status?: number | string;
  type?: number | string;
  unused_count?: number | string;
  used_count?: number | string;
}

export async function getCouponViewApi(id: number | string) {
  return requestClient.post<{
    code: number;
    data?: CouponDetail;
    message: string;
  }>('/api/coupon/view', { id }, { responseReturn: 'body' });
}

/** 兌換明細列 */
export interface CouponCodeRow {
  code?: string;
  member_username?: string;
  mobile?: string;
  used_at?: string;
}

export async function getCouponCodesApi(batchId: number | string) {
  return requestClient.post<{
    code: number;
    data?: CouponCodeRow[];
    message: string;
  }>('/api/coupon/codes', { batch_id: batchId }, { responseReturn: 'body' });
}

/** 建立批次 */
export async function createCouponApi(params: {
  agent_id: number | string;
  all_downline: number;
  amount: number | string;
  end_time: string;
  flow_limit_multiple: number | string;
  mobile_daily_limit: number | string;
  /** reward_mode=2 隨機獎勵時的獎池 */
  prizes?: CouponPrize[];
  quantity: number | string;
  realname_required: number;
  receive_limit: number | string;
  /** 1:固定金額 2:隨機獎勵 */
  reward_mode: number | string;
  start_time: string;
  type: number | string;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/coupon/create',
    params,
    { responseReturn: 'body' },
  );
}

/** 更新批次（隨機獎勵獎項圖片等） */
export async function updateCouponApi(params: {
  id: number | string;
  prizes?: CouponPrize[];
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/coupon/update',
    params,
    { responseReturn: 'body' },
  );
}

/** 切換批次狀態 */
export async function toggleCouponStatusApi(params: {
  id: number | string;
  status: number | string;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/coupon/edit',
    params,
    { responseReturn: 'body' },
  );
}

/** 獎項圖片上傳（base64） → 回傳 path */
export async function uploadCouponImageApi(base64Image: string) {
  return requestClient.post<{
    code: number | string;
    data?: { path?: string };
    message?: string;
  }>('/api/image/base64/upload', { base64Image }, { responseReturn: 'body' });
}

/** 遊戲商/遊戲類型限制列（vendor_id/game_type_id 為 0 表示該維度不限制） */
export interface CouponEventModeRow {
  game_type_id: number | string;
  id: number | string;
  vendor_id: number | string;
}

/** 取得批次的遊戲商/遊戲類型限制 */
export async function getCouponEventModeApi(id: number | string) {
  return requestClient.post<{
    code: number;
    data?: CouponEventModeRow[];
    message: string;
  }>('/api/coupon/get_event_mode', { id }, { responseReturn: 'body' });
}

/** 儲存批次的遊戲商/遊戲類型限制（兩個等長陣列，第 i 筆為一組限制） */
export async function saveCouponEventModeApi(data: {
  game_type_id_restriction: Array<number | string>;
  id: number | string;
  vendor_id_restriction: Array<number | string>;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/coupon/update_event_mode',
    {
      flow_limit_game_types: data.game_type_id_restriction,
      flow_limit_vendors: data.vendor_id_restriction,
      id: data.id,
    },
    { responseReturn: 'body' },
  );
}
