import { test, type Browser } from '@playwright/test';
import { BackendLoginPage } from '../pages/backend/LoginPage';
import { SmsRecordPage } from '../pages/backend/SmsRecordPage';
import { getOperatorSite, type OperatorKey } from '../config/operators';

/**
 * 另開一個瀏覽器 context，「當場登入後台」再到簡訊記錄頁取得驗證碼。
 *
 * 刻意不依賴任何預存的 storageState 檔（.auth/*.json）：這樣即使單獨執行前台註冊測試
 * （例如 UI 模式只點一支 spec、沒跑其他 setup），也能自己登入後台查碼、不會 ENOENT。
 *
 * 依賴：對應營運商的 `<op>-backend` 已於 .env 設定網址與管理帳密。
 *
 * @param browser  Playwright browser（由測試的 browser fixture 提供）
 * @param operator 營運商 key，例如 'dev'
 * @param mobile   完整國際手機號（國碼+本地號碼）
 * @param backendProjectName 要查哪個後台（選填，預設 `${operator}-backend`，即舊後台；
 *   需要查新後台時傳 `${operator}-backend-new`）
 */
export async function fetchOtpFromBackend(
  browser: Browser,
  operator: OperatorKey,
  mobile: string,
  backendProjectName: string = `${operator}-backend`,
): Promise<string> {
  const backend = getOperatorSite(backendProjectName);
  if (!backend) {
    throw new Error(
      `營運商「${operator}」未設定後台（缺 ${operator.toUpperCase()}_BACKEND_URL 或 _NEW），無法從後台取驗證碼`,
    );
  }
  if (!backend.credentials) {
    throw new Error(
      `營運商「${operator}」後台缺管理帳密（${operator.toUpperCase()}_ADMIN_USERNAME/PASSWORD），無法登入後台取碼`,
    );
  }

  const context = await browser.newContext({ baseURL: backend.baseURL });
  try {
    const page = await context.newPage();
    await test.step('[後台] 以管理帳號登入後台', () =>
      new BackendLoginPage(page).login(backend.credentials!));
    return await test.step('[後台] 簡訊記錄頁查詢最新驗證碼', () =>
      new SmsRecordPage(page).getLatestOtp(mobile));
  } finally {
    await context.close();
  }
}
