import type { VendorManagementItem } from '#/api';

import { tl } from '#/locales';

/**
 * 廠商管理四個新欄位的顯示文字（工單 4400），列表、Excel 匯出與編輯彈窗共用，
 * 確保三處看到的文字一致。
 */

/** 無資料（apis 沒掛 driver 等）時的佔位 */
export const EMPTY_TEXT = '—';

/** 錢包模式：single → 單錢包，transfer → 轉帳錢包 */
export function walletModeText(mode?: null | string): string {
  if (mode === 'single') return tl('single_wallet');
  if (mode === 'transfer') return tl('transfer_wallet');
  return EMPTY_TEXT;
}

/** 通道：null → —，direct → 直連，其餘顯示通道名 */
export function channelText(channel?: null | string): string {
  if (channel === null || channel === undefined || channel === '') {
    return EMPTY_TEXT;
  }
  return channel === 'direct' ? tl('vendor_channel_direct') : channel;
}

/** gamelist API：null → —，true → 有，false → 無 */
export function gameListText(has?: boolean | null): string {
  if (has === null || has === undefined) return EMPTY_TEXT;
  return has ? tl('has') : tl('none');
}

/**
 * 回調路徑：
 * - 沒掛 driver（channel 為 null）→ —，與通道、gamelist API 兩欄一致
 * - 掛了 driver 但不適用（轉帳錢包）→ 不適用
 * - 網址空 → —，否則完整網址
 *
 * 回調欄位為選填（非 admin 時後端不回傳，Pick 會帶上選填修飾），未提供時與 null 同處理。
 */
export function callbackUrlText(
  row: Pick<
    VendorManagementItem,
    'callback_applicable' | 'callback_url' | 'channel'
  >,
): string {
  if (row.channel === null || row.channel === undefined) return EMPTY_TEXT;
  if (row.callback_applicable === false) return tl('not_applicable');
  return row.callback_url || EMPTY_TEXT;
}
