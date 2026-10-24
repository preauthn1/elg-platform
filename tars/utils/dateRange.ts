/**
 * 後台報表查詢用的時間字串（格式 `YYYY-MM-DD HH:mm`）。
 * 測試以 Asia/Taipei 執行（見 playwright.config.ts），與後台報表時間一致。
 */
export function dayRange(date = new Date()): { start: string; end: string } {
  const pad = (n: number) => String(n).padStart(2, '0');
  const day = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  return { start: `${day} 00:00`, end: `${day} 23:59` };
}
