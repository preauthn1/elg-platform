import { requestClient } from '#/api/request';

/**
 * 站內信管理 API（4152）：營運商管理 → 站內信管理。
 *
 * 對會員發信。四種發送類型：指定會員／會員群組於存檔時展開收件人（快照，
 * 之後轉群組仍收得到）；全平臺／代理及子代理不展開、前台動態判定（發信後
 * 才註冊的會員看不到）。發布時間到點後整封信鎖定，僅能查看。
 *
 * 後端在 apis repo 的 Agent 面向（gateway 依 `agent/api/` 前綴轉發），成功碼 200。
 */

/** 發送類型：指定會員（member_ids 必填） */
export const MAIL_SEND_TYPE_MEMBER = 1;

/** 發送類型：會員群組（member_group_ids 必填，存檔時展開） */
export const MAIL_SEND_TYPE_GROUP = 2;

/** 發送類型：全平臺（同組織所有會員，動態判定） */
export const MAIL_SEND_TYPE_ORGANIZATION = 3;

/** 發送類型：代理及子代理（hierarchy_agent_id 必填，動態判定） */
export const MAIL_SEND_TYPE_AGENT = 4;

/** 英文語系 id — 多語系內容的 fallback 底線，後台必填 */
export const MAIL_LANGUAGE_EN = 1;

export interface MemberMailLanguageRow {
  content: string;
  language_id: number;
  title: string;
}

export interface MemberMailRow {
  agent_id: number;
  created_at: string;
  hierarchy_agent_id: number;
  /** send_type=4 時有值，供列表顯示發送對象 */
  hierarchy_agent_username: string;
  id: number;
  /** 已到發布時間即鎖定，僅能查看 */
  is_published: boolean;
  languages: MemberMailLanguageRow[];
  /** send_type=2 時有值，供編輯回填（收件人已展開，此清單僅供顯示） */
  member_group_ids: number[];
  /** send_type=1 時有值，供編輯回填 */
  members: Array<{ id: number; username: string }>;
  memo: null | string;
  /** send_type=3 時有值 */
  organization_id: number;
  /** send_type=3 時有值，供列表顯示發送對象 */
  organization_name: string;
  publish_time: string;
  /** 已讀人數（觸達率分子） */
  read_count: number;
  /** 應收人數（觸達率分母，存檔時計算） */
  recipient_count: number;
  send_type: number;
  status: number;
  updated_at: string;
}

/**
 * 站內信列表
 *
 * 後端 successWithPagination 回 data:{items,pagination}，此處攤平成
 * {data, pagination} 形狀，呼叫端不必各自解構。
 */
export async function getMemberMailListApi(data: {
  keyword?: string;
  page: number;
  per_page: number;
  send_type?: number | string;
  status?: number | string;
}) {
  const res = await requestClient.post<{
    code: number | string;
    data?: {
      items?: MemberMailRow[];
      pagination?: { current_page: number; per_page: number; total: number };
    };
    message: string;
  }>('/agent/api/member-mail/list', data, { responseReturn: 'body' });

  return {
    code: res.code,
    data: res.data?.items ?? [],
    message: res.message,
    pagination: res.data?.pagination,
  };
}

/** 單筆詳情（含多語系與對象，供編輯回填） */
export async function getMemberMailViewApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: MemberMailRow;
    message: string;
  }>('/agent/api/member-mail/view', { id }, { responseReturn: 'body' });
}

/**
 * 新增／編輯站內信
 *
 * 帶 id 為編輯。已到發布時間的信後端一律擋下（published_locked）。
 * languages 必須包含英文（language_id=1）。
 */
export async function saveMemberMailApi(data: {
  hierarchy_agent_id?: number | string;
  id?: number | string;
  languages: MemberMailLanguageRow[];
  member_group_ids?: number[];
  member_ids?: number[];
  memo?: string;
  organization_id?: number | string;
  publish_time: string;
  send_type: number | string;
  status?: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/agent/api/member-mail/save',
    data,
    { responseReturn: 'body' },
  );
}

/** 刪除站內信（僅發布前可刪） */
export async function deleteMemberMailApi(id: number | string) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/agent/api/member-mail/delete',
    { id },
    { responseReturn: 'body' },
  );
}

/**
 * 表單用的會員遠端搜尋
 *
 * 未輸入關鍵字不查、限量回傳，避免整批下線灌進下拉凍死彈窗。
 */
export async function searchMailMembersApi(data: {
  keyword: string;
  limit?: number;
}) {
  return requestClient.post<{
    code: number | string;
    data?: Array<{ id: number; username: string }>;
    message: string;
  }>('/agent/api/member-mail/member-list', data, { responseReturn: 'body' });
}
