import type { MerchantGameCustomizedItems } from '#/api/merchant-game';

/** 個性化設置版位狀態 → 語系 key（全部沿用既有 key） */
export const customizedStatusLocaleKey: Record<number, string> = {
  0: 'disable',
  1: 'enable',
  2: 'coming_soon',
};

/** 個性化設置版位狀態 → ElTag 的 type（顏色照 PM 示意圖：停用紅、敬請期待黃） */
export const customizedStatusTagType: Record<
  number,
  'danger' | 'success' | 'warning'
> = {
  0: 'danger',
  1: 'success',
  2: 'warning',
};

/** 商戶遊戲管理的六個查詢條件（送出當下的快照） */
export interface MerchantGameQuerySnapshot {
  bettable: number | string;
  game_code: string;
  group_id: number | string;
  name: string;
  status: number | string;
  vendor_id: number | string;
}

/**
 * 是否改以個性化設置版位取代清單（target/tickets#4373）
 *
 * 工單四條件須同時成立：①遊戲商組別有選 ②遊戲商有選
 * ③遊戲名稱／遊戲代碼／狀態／可否投注全部空白 ④查詢後總筆數為 0；
 * 再加上後端確實有回版位資料（items 非空），否則照舊顯示「查無資料」。
 *
 * 條件④用總筆數而非當頁筆數，與後端的 $total === 0 對齊：
 * 越界分頁時當頁為空但 total 不為 0，那不該出區塊。
 */
export function shouldShowCustomizedHint(
  query: MerchantGameQuerySnapshot,
  total: number,
  hint: MerchantGameCustomizedItems | null | undefined,
): boolean {
  if (!query.group_id || !query.vendor_id) return false;
  if (query.name !== '' || query.game_code !== '') return false;
  if (query.status !== '' || query.bettable !== '') return false;
  if (total > 0) return false;
  return (hint?.items?.length ?? 0) > 0;
}
