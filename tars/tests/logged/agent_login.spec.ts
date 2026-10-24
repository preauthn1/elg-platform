import { test, expect, type Page } from '@playwright/test';
import { getOperatorSite, type OperatorSite } from '../../config/operators';
import { BackendLoginPage } from '../../pages/backend/LoginPage';

/**
 * 代理後台登入流程。
 *
 * 例外說明：這個案例驗證的就是「登入」本身，所以不用 backendPage fixture
 * （fixture 會直接給登入態），改在 beforeAll 自建乾淨 page、原子操作間共用同一 session。
 */
test.describe.serial('[後台] 驗證登入後可進入後台首頁', () => {
  let page: Page;
  let site: OperatorSite | undefined;

  test.beforeAll(async ({ browser }, testInfo) => {
    const operator = testInfo.project.name.replace(/-logged$/, '');
    site = getOperatorSite(`${operator}-backend`);
    // 自建 page（未登入）：讓「登入」操作從未登入狀態真實執行
    page = await browser.newPage({ baseURL: site?.baseURL });
  });

  test.afterAll(async () => {
    await page?.close();
  });

  // 自建 page 不走 fixture，失敗時不會自動截圖，這裡手動補上供 QA 除錯
  test.afterEach(async ({}, testInfo) => {
    if (page && testInfo.status !== testInfo.expectedStatus) {
      await testInfo.attach('失敗當下畫面（後台）', {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
      });
    }
  });

  test('[後台] 登入', async () => {
    test.skip(!site?.credentials, '未設定後台管理帳密');

    await test.step('以管理帳密登入並確認導向首頁', () =>
      new BackendLoginPage(page).login(site!.credentials!));
  });

  test('[後台] 進入後台首頁', async () => {
    test.skip(!site?.credentials, '未設定後台管理帳密');

    // 沿用上一步「登入」的 session
    await test.step('開啟後台首頁', () => page.goto('/'));

    await test.step('確認未被導回登入頁', async () => {
      await expect(page, '不應被導回登入頁').not.toHaveURL(/login/i);
      await expect(page.locator('body')).toBeVisible();
    });
  });
});
