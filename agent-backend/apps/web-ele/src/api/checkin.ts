import { requestClient } from '#/api/request';

/**
 * 打卡活動 API（apis 後端，路徑 agent/api/checkin/*，成功碼 200） */

/** 打卡設置列 */
export interface CheckinRow {
  agent_name?: string;
  end_date?: string;
  id: number | string;
  start_date?: string;
  status?: number | string;
}
export async function getCheckinListApi() {
  return requestClient.post<{
    code: number | string;
    data?: CheckinRow[];
    message?: string;
  }>('/agent/api/checkin/list', {}, { responseReturn: 'body' });
}

/** 啟用/停用（舊版 ajax checkin_toggle_status → checkin/status） */
export async function updateCheckinStatusApi(payload: {
  id: number | string;
  status: number | string;
}) {
  return requestClient.post<{ code: number | string; message?: string }>(
    '/agent/api/checkin/status',
    payload,
    { responseReturn: 'body' },
  );
}

/** 打卡紀錄列 */
export interface CheckinRecordRow {
  checkin_date?: string;
  prize?: Array<{
    prize_amount?: number | string;
    prize_type?: number | string;
  }>;
  username?: string;
}
export async function getCheckinRecordApi(params: {
  end_date: string;
  page: number;
  per_page: number;
  start_date: string;
  username?: string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: {
      items?: CheckinRecordRow[];
      pagination?: { total?: number };
    };
    message?: string;
  }>('/agent/api/checkin/record', params, { responseReturn: 'body' });
}

/** 單日獎勵設定 */
export interface CheckinPrize {
  day?: number | string;
  prize_amount?: number | string;
  prize_type?: number | string;
}
/** 打卡設置明細 */
export interface CheckinDetail {
  agent_id?: number | string;
  agents?: Array<number | string>;
  checkin_prizes?: CheckinPrize[];
  end_date?: string;
  start_date?: string;
}
export async function getCheckinDetailApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: CheckinDetail;
    message?: string;
  }>('/agent/api/checkin/detail', { id }, { responseReturn: 'body' });
}

/** 儲存打卡設置（舊版 save_checkin/update_checkin → checkin/create|update） */
export async function saveCheckinApi(
  data: Record<string, any>,
  isEdit: boolean,
) {
  return requestClient.post<{ code: number | string; message?: string }>(
    isEdit ? '/agent/api/checkin/update' : '/agent/api/checkin/create',
    data,
    { responseReturn: 'body' },
  );
}
