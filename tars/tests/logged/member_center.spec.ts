import { test, expect } from '../../fixtures/sites';
import { getOperatorSite } from '../../config/operators';

/**
 * 會員中心（登入後、不留資料）。
 * frontendPage 在 logged 分類會自動帶入 .env 固定會員帳號的登入態。
 * 注意：固定帳號的資料會跨執行累積，斷言不要假設帳號是乾淨的。
 */
test.describe.serial('[前台] 驗證登入態可進入會員中心', () => {
  test('[前台] 確認首頁為登入態', async ({ frontendPage }, testInfo) => {
    await test.step('開啟前台首頁', () => frontendPage.goto('/'));

    await test.step('確認未被導回登入/註冊頁', async () => {
      await expect(frontendPage).not.toHaveURL(/signup|login/i);
      await expect(frontendPage.locator('body')).toBeVisible();
    });

    const operator = testInfo.project.name.replace(/-logged$/, '');
    console.log(
      `使用固定會員帳號（.env）：${getOperatorSite(`${operator}-frontend`)?.credentials?.username}`,
    );
  });

  test('[前台] 進入會員中心', async ({ frontendPage }) => {
    await test.step('開啟會員中心頁', () => frontendPage.goto('/member'));

    await test.step('確認未被導回登入頁', async () => {
      await expect(frontendPage).not.toHaveURL(/login/i);
      await expect(frontendPage.locator('body')).toBeVisible();
    });
  });
});
