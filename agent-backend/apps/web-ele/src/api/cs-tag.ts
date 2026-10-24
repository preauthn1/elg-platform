import { requestClient } from '#/api/request';

/**
 * 客服標籤 API（target/tickets#4169，參考 TTN Chat Pin）。
 *
 * 後端實作在 WEB_CustomerServiceSocket feature/4169（CsTagController，
 * chat DB 自建 cs_tags / cs_member_tags / cs_tag_logs，與平台 DB 的
 * 玩家標籤 tags/member_tags 是兩套）。端點路徑與欄位別名收斂在本檔。
 */

export interface CsTag {
  background_color?: string;
  id?: number | string;
  name?: string;
  status?: number;
  text_color?: string;
  update_user?: string;
  updated_at?: string;
}

export interface CsTagLogRow {
  /** pin=貼上標籤 unpin=移除標籤 */
  action?: string;
  background_color?: string;
  created_at?: string;
  /** 操作的客服帳號（cs_tag_logs.admin → users.username） */
  operator?: string;
  tag_id?: number | string;
  tag_name?: string;
  text_color?: string;
  username?: string;
}

export interface CsApiBody {
  code: number;
  data?: unknown;
  message: string;
}

/** 容忍後端欄位別名（label_name/name、color/background_color、updated_by/update_user） */
function normalizeCsTag(raw: Record<string, any>): CsTag {
  return {
    background_color: raw.background_color ?? raw.color ?? '',
    id: raw.id,
    name: raw.name ?? raw.label_name ?? '',
    status: raw.status === undefined ? undefined : Number(raw.status),
    text_color: raw.text_color ?? '',
    update_user: raw.update_user ?? raw.updated_by ?? '',
    updated_at: raw.updated_at ?? '',
  };
}

function normalizeCsTagLog(raw: Record<string, any>): CsTagLogRow {
  return {
    action: raw.action ?? raw.status ?? '',
    background_color: raw.background_color ?? raw.tag?.background_color ?? '',
    created_at: raw.created_at ?? '',
    operator: raw.operator ?? raw.update_user ?? raw.admin_username ?? '',
    tag_id: raw.tag_id ?? raw.tag?.id,
    tag_name: raw.tag_name ?? raw.tag?.name ?? raw.name ?? '',
    text_color: raw.text_color ?? raw.tag?.text_color ?? '',
    username: raw.username ?? raw.member_username ?? '',
  };
}

/**
 * 兩個標籤是否為同一顆：優先比 id；room/enter 的 member_tags 不保證帶 id，
 * 退而以 name 比對。
 */
export function sameCsTag(
  a: Record<string, any> | undefined,
  b: Record<string, any> | undefined,
) {
  if (!a || !b) return false;
  if (a.id !== undefined && b.id !== undefined) {
    return String(a.id) === String(b.id);
  }
  return !!a.name && a.name === b.name;
}

/** 標籤清單；status 1=只取啟用中 */
export async function getCsTagListApi(params: { status?: number } = {}) {
  const rows = await requestClient.post<Record<string, any>[]>(
    '/cs/api/tag/list',
    params,
  );
  return (rows ?? []).map((r) => normalizeCsTag(r));
}

/** 新增/編輯標籤（無 id=新增；同端點也可只帶 {id, status} 切換狀態） */
export function saveCsTagApi(data: {
  background_color?: string;
  id?: number | string;
  name?: string;
  status?: number;
  text_color?: string;
}) {
  return requestClient.post<CsApiBody>('/cs/api/tag/save', data, {
    responseReturn: 'body',
  });
}

/** 更新會員標籤（全量置換；後端 diff 舊/新自動寫 pin/unpin 異動紀錄） */
export function saveCsMemberTagsApi(data: {
  member_id: number | string;
  tag_ids: Array<number | string>;
}) {
  return requestClient.post<CsApiBody>('/cs/api/tag/member/update', data, {
    responseReturn: 'body',
  });
}

/**
 * 標籤異動紀錄。後端一次回全部、前端只做表內關鍵字過濾（此端點本身沒有分頁，
 * 與已改成伺服器端分頁的 user/list、room/list 不同）。後端有 LOG_LIST_LIMIT 筆
 * 上限，回傳筆數剛好等於上限時代表可能被截斷，呼叫端要提示縮小時間範圍。
 */
export const CS_TAG_LOG_LIMIT = 2000;

export async function getCsTagLogListApi(params: {
  action?: string;
  end_time?: string;
  start_time?: string;
  username?: string;
}) {
  const rows = await requestClient.post<Record<string, any>[]>(
    '/cs/api/tag/log/list',
    params,
  );
  return (rows ?? []).map((r) => normalizeCsTagLog(r));
}
