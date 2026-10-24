import type { OperateLogRow } from '#/api/agent';

/**
 * 訂單狀態異動的顯示文字：「異動前 → 異動後」。
 *
 * 兩個欄位由後端各自判斷，缺資料時回空字串。少一邊就湊不出一次完整的異動，
 * 所以兩邊都有值才顯示，否則回空字串。
 */
export function orderStatusChangeText(
  row: Pick<OperateLogRow, 'orderStatusAfter' | 'orderStatusBefore'>,
): string {
  const before = row.orderStatusBefore ?? '';
  const after = row.orderStatusAfter ?? '';

  return before && after ? `${before} → ${after}` : '';
}
