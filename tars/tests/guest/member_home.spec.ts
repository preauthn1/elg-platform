import { test, expect } from '../../fixtures/sites';

/**
 * 會員站首頁（未登入）。
 * 這支在未調整任何 selector 的情況下就能通過，可當作環境設定是否正確的第一道檢查。
 */
test.describe('[前台] 驗證首頁可正常載入', () => {
  test('[前台] 開啟首頁並確認載入', async ({ frontendPage }) => {
    const response = await test.step('開啟前台首頁', () => frontendPage.goto('/'));

    await test.step('確認頁面正常回應且有內容', async () => {
      expect(response?.ok(), `首頁 HTTP 狀態應為 2xx，實際為 ${response?.status()}`).toBeTruthy();
      await expect(frontendPage, '頁面標題不應為空').toHaveTitle(/.+/);
      await expect(frontendPage.locator('body')).toBeVisible();
    });
  });
});
