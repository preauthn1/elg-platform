import { requestClient } from '#/api/request';

/**
 * 會員遊戲限制 API（4126）：下線會員管理 → 會員遊戲限制。
 *
 * 後端在 apis repo 的 Agent 面向（gateway 依 `agent/api/` 前綴轉發到 APIS_URL），
 * 成功碼為 200（與舊 API_Agent 的 1000 不同）。
 *
 * 與遊戲維護（4120，代理層級、有時間區間、到點自動恢復）平行的另一套：
 * 對象是會員或會員群組、永久生效，解除即停用或刪除。前台效果相同（灰化），
 * 差別是不回傳恢復時間。
 */

/** 限制類型：遊戲商（target_ids 送 vendor_id） */
export const MEMBER_RESTRICT_TYPE_VENDOR = 1;

/** 限制類型：遊戲（target_ids 送 game_id） */
export const MEMBER_RESTRICT_TYPE_GAME = 2;

/** 對象範圍：指定會員（送 member_ids） */
export const MEMBER_RESTRICT_SCOPE_MEMBER = 1;

/** 對象範圍：會員群組（送 member_group_ids） */
export const MEMBER_RESTRICT_SCOPE_GROUP = 2;

/** 受限會員（帶 username 供編輯回填顯示，下拉為遠端搜尋不重查就只剩 id） */
export interface RestrictedMember {
  id: number;
  username: string;
}

export interface MemberGameRestrictionRow {
  /** 建立此限制的代理編號 */
  agent_id: number;
  created_at: string;
  id: number;
  memo: null | string;
  /** target_scope=2 時有值 */
  member_group_ids: number[];
  /** target_scope=1 時有值 */
  members: RestrictedMember[];
  /** 1=遊戲商 2=遊戲 */
  restrict_type: number;
  /** 0=停用 1=啟用 */
  status: number;
  /** 依 restrict_type 為 vendor_id 或 game_id */
  target_ids: number[];
  /**
   * 受限標的的名稱，供列表直接顯示、編輯回填不必再查。
   * restrict_type=2（遊戲）時 vendor_name 為所屬遊戲商，用於區分同名遊戲；
   * =1（遊戲商）時為空字串。
   */
  target_names: Array<{
    id: number;
    name: string;
    vendor_id: number;
    vendor_name: string;
  }>;
  /** 1=指定會員 2=會員群組 */
  target_scope: number;
  updated_at: string;
}

/**
 * 限制單列表
 *
 * 後端 successWithPagination 回的是 data:{items,pagination}，此處攤平成
 * useListQuery 期待的 {data, pagination} 形狀，避免呼叫端各自解構。
 */
export async function getMemberGameRestrictionListApi(data: {
  /** 會員群組（涵蓋即列出） */
  member_group_id?: number | string;
  page: number;
  per_page: number;
  restrict_type?: number | string;
  status?: number | string;
  /** 目標用戶類型 1=指定會員 2=會員群組 */
  target_scope?: number | string;
  /** 會員帳號關鍵字（涵蓋即列出） */
  username?: string;
}) {
  const res = await requestClient.post<{
    code: number | string;
    data?: {
      items?: MemberGameRestrictionRow[];
      pagination?: { current_page: number; per_page: number; total: number };
    };
    message: string;
  }>('/agent/api/member-game-restriction/list', data, {
    responseReturn: 'body',
  });

  return {
    code: res.code,
    data: res.data?.items ?? [],
    message: res.message,
    pagination: res.data?.pagination,
  };
}

/** 單筆詳情（含受限標的與對象，供編輯回填） */
export async function getMemberGameRestrictionViewApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: MemberGameRestrictionRow;
    message: string;
  }>(
    '/agent/api/member-game-restriction/view',
    { id },
    { responseReturn: 'body' },
  );
}

/**
 * 新增／編輯
 *
 * 帶 id 為編輯；target_ids 與對象清單整批覆寫，未列出的既有關聯會被移除。
 * 依 target_scope 擇一送 member_ids 或 member_group_ids。
 */
export async function saveMemberGameRestrictionApi(data: {
  agent_id: number | string;
  id?: number | string;
  member_group_ids?: number[];
  member_ids?: number[];
  memo?: string;
  restrict_type: number | string;
  status?: number | string;
  target_ids: number[];
  target_scope: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/agent/api/member-game-restriction/save',
    data,
    { responseReturn: 'body' },
  );
}

/**
 * 表單用的會員遠端搜尋
 *
 * 下線動輒數萬筆，未輸入關鍵字不查、且限量回傳，避免整批灌進下拉凍死彈窗。
 */
export async function searchRestrictionMembersApi(data: {
  keyword: string;
  limit?: number;
}) {
  return requestClient.post<{
    code: number | string;
    data?: RestrictedMember[];
    message: string;
  }>('/agent/api/member-game-restriction/member-list', data, {
    responseReturn: 'body',
  });
}
