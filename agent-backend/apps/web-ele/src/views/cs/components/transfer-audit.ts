/**
 * 客服「紅包／開洗分」彈窗的稽核流水規則（target/tickets#4494）
 *
 * 抽成純函式以便單元測試；transfer-dialog.vue 只負責接線。
 */

/** 轉帳類型：發紅包 */
export const TRANSFER_TYPE_SEND_RED_ENVELOPE = 1;
/** 轉帳類型：收紅包 */
export const TRANSFER_TYPE_RECEIVE_RED_ENVELOPE = 2;

/**
 * 稽核流水默認倍數（代理個性化設置 6601 TRANSFER_MEMBER_FLOW_LIMIT_MULTIPLE）
 *
 * 非有限數字（undefined／null／''／'abc'／NaN）一律當 0 倍，
 * 不再把設定為 0 的倍率當成 1（target/tickets#4494）。
 */
export function parseFlowLimitMultiple(
  raw: null | number | string | undefined,
): number {
  // Number(null)、Number('') 都是 0，Number(undefined)、Number('abc') 是 NaN，皆落在 0
  const n = Number(raw);
  return Number.isFinite(n) ? n : 0;
}

/**
 * 依類型自動算稽核流水（target/tickets#4494）
 *
 * 只有「發紅包」= 金額 × 倍率（比照舊客服 onChangeAmount 只在 type 1 計算）；
 * 其他類型回 undefined（清空、交客服手填）；發紅包但金額未填（null/undefined）也回 undefined。
 */
export function calcAuditAmount(
  type: number,
  amount: null | number | undefined,
  multiple: number,
): number | undefined {
  if (type !== TRANSFER_TYPE_SEND_RED_ENVELOPE) return undefined;
  if (amount === null || amount === undefined) return undefined;
  return amount * multiple;
}

/**
 * 送出用的稽核流水（target/tickets#4494）
 *
 * 收紅包或多錢包一律 0，其餘送目前值（null/undefined 視為 0）。
 */
export function resolveSubmitAuditAmount(
  type: number,
  isMultiWallet: boolean,
  auditAmount: null | number | undefined,
): number {
  if (type === TRANSFER_TYPE_RECEIVE_RED_ENVELOPE || isMultiWallet) return 0;
  return auditAmount ?? 0;
}
