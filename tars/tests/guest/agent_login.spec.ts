import { test, expect } from '../../fixtures/sites';

/**
 * 代理後台登入頁（未登入）。
 */
test.describe('[後台] 驗證登入頁可正常載入', () => {
  test('[後台] 開啟登入頁並確認登入表單', async ({ backendPage }) => {
    await test.step('開啟後台登入頁', () => backendPage.goto('/login.php'));

    await test.step('確認帳號、密碼欄位與登入按鈕存在', async () => {
      await expect(backendPage.getByRole('textbox', { name: 'Username' })).toBeVisible();
      await expect(backendPage.getByRole('textbox', { name: 'Password' })).toBeVisible();
      await expect(backendPage.getByRole('button', { name: 'Login' })).toBeVisible();
    });
  });
});
