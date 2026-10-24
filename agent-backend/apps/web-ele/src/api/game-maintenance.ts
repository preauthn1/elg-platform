import { requestClient } from '#/api/request';

/**
 * 遊戲維護 API（4120）：系統管理 → 遊戲商維護 → 遊戲維護。
 *
 * 後端在 apis repo 的 Agent 面向（gateway 依 `agent/api/` 前綴轉發到 APIS_URL），
 * 成功碼為 200（與舊 API_Agent 的 1000 不同）。
 *
 * 與遊戲商維護（vendor_maintenances，整商關閉）平行的另一套：粒度到單一遊戲，
 * 一張單可跨遊戲商。兩者互不影響、可同時生效。
 */

/** 關閉類型：關閉遊戲（工單需求固定，介面不給編輯） */
export const GAME_MAINTENANCE_TYPE_GAME_CLOSED = 1;

/** 解鎖方式：自動（結束時間一到自動解鎖） */
export const GAME_MAINTENANCE_UNLOCK_AUTO = 1;

/** 解鎖方式：手動（保留選項，後端尚未實作） */
export const GAME_MAINTENANCE_UNLOCK_MANUAL = 2;

/** 維護單涵蓋的單一遊戲 */
export interface GameMaintenanceGame {
  id: number;
  name: string;
  type_id: number;
  vendor_id: number;
  vendor_name: null | string;
}

export interface GameMaintenanceRow {
  /** 維護代理編號 */
  agent_id: number;
  created_at: string;
  end_time: string;
  /** 排除代理編號（0 為不排除） */
  exclude_agent_id: number;
  /** 涵蓋的遊戲數 */
  game_count: number;
  /** 涵蓋的遊戲清單（可跨遊戲商） */
  games: GameMaintenanceGame[];
  id: number;
  memo: null | string;
  /**
   * 執行狀態，由後端依狀態與時間推導（不存 DB）。
   * disabled=停用、pending=尚未開始、running=維護中、finished=維護結束
   */
  run_state: 'disabled' | 'finished' | 'pending' | 'running';
  start_time: string;
  /** 0=停用 1=啟用 */
  status: number;
  /** 1=關閉遊戲 */
  type: number;
  /** 1=自動 2=手動 */
  unlock_type: number;
  updated_at: string;
  /** 涵蓋的遊戲商名稱（去重，供列表顯示） */
  vendor_names: string[];
}

/** 表單用的可勾選遊戲 */
export interface SelectableGame {
  id: number;
  name: string;
  type_id: number;
  vendor_id: number;
  vendor_name: null | string;
}

/**
 * 維護單列表
 *
 * vendor_id 篩選採「涵蓋即列出」：一張單可跨商，只要其中有任一款屬於該商的
 * 遊戲就會列出——營運查某商是為了看「有哪些維護影響到它」。
 *
 * 後端 successWithPagination 回的是 data:{items,pagination}，此處攤平成
 * useListQuery 期待的 {data, pagination} 形狀，避免呼叫端各自解構。
 */
export async function getGameMaintenanceListApi(data: {
  page: number;
  per_page: number;
  status?: number | string;
  vendor_id?: number | string;
}) {
  const res = await requestClient.post<{
    code: number | string;
    data?: {
      items?: GameMaintenanceRow[];
      pagination?: { current_page: number; per_page: number; total: number };
    };
    message: string;
  }>('/agent/api/game-maintenance/list', data, { responseReturn: 'body' });

  return {
    code: res.code,
    data: res.data?.items ?? [],
    message: res.message,
    pagination: res.data?.pagination,
  };
}

/** 單筆詳情（含涵蓋的遊戲清單，供編輯回填） */
export async function getGameMaintenanceViewApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: GameMaintenanceRow;
    message: string;
  }>('/agent/api/game-maintenance/view', { id }, { responseReturn: 'body' });
}

/**
 * 新增／編輯
 *
 * 帶 id 為編輯；game_ids 整批覆寫，未列出的既有關聯會被移除。
 */
export async function saveGameMaintenanceApi(data: {
  agent_id: number | string;
  end_time: string;
  exclude_agent_id?: number | string;
  game_ids: number[];
  id?: number | string;
  memo?: string;
  start_time: string;
  status?: number | string;
  unlock_type?: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/agent/api/game-maintenance/save',
    data,
    { responseReturn: 'body' },
  );
}

/**
 * 表單用的可勾選遊戲清單
 *
 * 遊戲商（可多選）與類型只是縮小清單的過濾器，不會落地——
 * 存檔時只有勾選的 game_id 會寫入。
 */
export async function getGameMaintenanceGameListApi(data: {
  keyword?: string;
  type_ids?: number[];
  vendor_ids: number[];
}) {
  return requestClient.post<{
    code: number | string;
    data?: SelectableGame[];
    message: string;
  }>('/agent/api/game-maintenance/game-list', data, {
    responseReturn: 'body',
  });
}
