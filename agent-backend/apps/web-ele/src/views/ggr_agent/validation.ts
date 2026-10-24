/**
 * 推廣代理 GGR 表單的數值驗證（4123/4124 共用）。
 *
 * 與後端 Request 規則對齊：金額為正數/非負數且最多兩位小數、
 * 最低輸贏與最大 GGR 只能正整數；錯誤顯示為欄位下方即時紅字。
 *
 * 上限同樣要對齊：後端欄位是 decimal/int，值太大時若前端放行，
 * 使用者拿到的會是後端的整段錯誤而非欄位級提示。
 */

/** 金額類上限，對齊後端 decimal(18,2) 欄位的 Request 規則 */
export const MAX_AMOUNT = 999_999_999_999.99;

/** 加權%上限，對齊後端 weight_rate decimal(8,2) */
export const MAX_RATE = 999_999.99;

/** 用戶人數上限，對齊後端 user_count 的 signed int */
export const MAX_USER_COUNT = 2_147_483_647;

/** 最低輸贏／最大 GGR 上限，對齊後端 Request 的 MAX_INT_AMOUNT */
export const MAX_INT_AMOUNT = 999_999_999_999;

/** 正數、最多兩位小數（計算金額、加權%） */
export function isPositiveMoney(value: string): boolean {
  return /^\d+(\.\d{1,2})?$/.test(value) && Number(value) > 0;
}

/** 非負數、最多兩位小數（4124 門檻，0=無要求） */
export function isNonNegativeMoney(value: string): boolean {
  return /^\d+(\.\d{1,2})?$/.test(value);
}

/** 非負整數（最低遊戲輸贏、最大 GGR——0 為「無限制」哨兵，送後端轉 null） */
export function isNonNegativeInt(value: string): boolean {
  return /^\d+$/.test(value);
}

/** 正整數（用戶人數） */
export function isPositiveInt(value: string): boolean {
  return /^[1-9]\d*$/.test(value);
}

/** 數值是否落在上限內（呼叫端已先確認格式合法） */
export function isWithinMax(value: string, max: number): boolean {
  return Number(value) <= max;
}
