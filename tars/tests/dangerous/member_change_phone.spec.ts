import { test, expect, type Page } from '@playwright/test';
import { getOperatorSite, type OperatorKey, type OperatorSite } from '../../config/operators';
import { FrontendLoginPage } from '../../pages/frontend/LoginPage';
import { FrontendSettingsPage } from '../../pages/frontend/SettingsPage';
import { FrontendChangePhonePage } from '../../pages/frontend/ChangePhonePage';
import { fetchOtpFromBackend } from '../../utils/backendOtp';
import { makeAccount } from '../../utils/random';

/**
 * 前台修改手機號（dangerous：會把 .env 固定會員帳號的手機號永久改成新號碼）。
 *
 * 用 .env 固定會員帳號（DEV_MEMBER_ACCOUNT/PASSWORD）測，不用 member fixture 當場註冊——
 * 因為當場註冊需要推廣碼（DEV_REFERRAL_CODE），這組 dev 環境目前沒有設定。
 * 改手機號本來就會留下資料（帳號手機號變更），符合 dangerous 分類的預期。
 *
 * 不用 frontendPage fixture：dangerous 分類的 frontendPage 預設未登入，
 * 這裡改在 beforeAll 自建 page 手動登入，讓「登入」也是看得見的原子操作，
 * 且原子操作之間（登入態、目前手機號、驗證碼）能延續同一個 session。
 */
test.describe.serial('[前台] 驗證會員可修改手機號並生效', () => {
  let page: Page;
  let site: OperatorSite | undefined;
  let operator: OperatorKey;
  let currentMobile = '';
  let currentOtp = '';
  let newLocalPhone = '';
  let newFullMobile = '';
  let newOtp = '';

  test.beforeAll(async ({ browser }, testInfo) => {
    operator = testInfo.project.name.replace(/-dangerous$/, '') as OperatorKey;
    site = getOperatorSite(`${operator}-frontend`);
    page = await browser.newPage({ baseURL: site?.baseURL });
  });

  test.afterAll(async () => {
    await page?.close();
  });

  // 自建 page 不走 fixture，失敗時不會自動截圖，這裡手動補上供 QA 除錯
  test.afterEach(async ({}, testInfo) => {
    if (page && testInfo.status !== testInfo.expectedStatus) {
      await testInfo.attach('失敗當下畫面（前台）', {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
      });
    }
  });

  /** 環境未設定時每個原子操作都要 skip（serial 只會傳遞「失敗」，不會傳遞 skip） */
  const skipIfNotConfigured = () => {
    test.skip(!site, `營運商「${operator}」未設定前台網址（<營運商>_FRONTEND_URL）`);
    test.skip(
      !site?.credentials,
      `營運商「${operator}」未設定前台會員帳密（<營運商>_MEMBER_ACCOUNT/PASSWORD）`,
    );
  };

  test('[前台] 登入並發送目前手機號驗證碼', async () => {
    skipIfNotConfigured();

    await test.step('登入前台（.env 固定會員帳號）', () =>
      new FrontendLoginPage(page).login(site!.credentials!));

    await test.step('回首頁確認會員資料載入完成', async () => {
      // 登入後直接跳設定頁，前台資料還沒載完會先顯示「未綁定」的預設選單（如「綁定手機號」）
      // 而非「修改手機號」；先進首頁、等資料載完，選單才會正確
      await page.goto('/');
      await page.waitForLoadState('networkidle');
    });

    await test.step('進入設定頁，開啟修改手機號', async () => {
      const settings = new FrontendSettingsPage(page);
      await settings.goto();
      await settings.openChangePhone();
    });

    const changePhone = new FrontendChangePhonePage(page);
    currentMobile = await test.step('讀取目前手機號並發送驗證碼', async () => {
      const mobile = await changePhone.readCurrentMobile();
      await changePhone.sendOtpToCurrentPhone();
      return mobile;
    });
  });

  test('[後台] 查詢目前手機號的驗證碼', async ({ browser }) => {
    skipIfNotConfigured();
    test.skip(
      !getOperatorSite(`${operator}-backend`)?.credentials,
      `營運商「${operator}」未設定後台網址或管理帳密，無法從後台查驗證碼`,
    );
    test.skip(!currentMobile, '未取得目前手機號（前面的操作被跳過），無法查驗證碼');

    currentOtp = await fetchOtpFromBackend(browser, operator, currentMobile);
    expect(currentOtp, '後台簡訊記錄應查得 6 碼驗證碼').toMatch(/^\d{6}$/);
  });

  test('[前台] 輸入驗證碼並發送新手機號驗證碼', async () => {
    skipIfNotConfigured();
    test.skip(!currentOtp, '未取得目前手機號驗證碼（前面的操作被跳過）');

    const changePhone = new FrontendChangePhonePage(page);
    await test.step('輸入目前手機號驗證碼，進入下一步', () =>
      changePhone.submitCurrentPhoneOtp(currentOtp));

    newLocalPhone = makeAccount().phone;
    newFullMobile = `${site!.phoneCountryCode ?? '60'}${newLocalPhone}`;
    await test.step('填入新手機號並發送驗證碼', () =>
      changePhone.fillNewPhoneAndSendOtp(newLocalPhone));
  });

  test('[後台] 查詢新手機號的驗證碼', async ({ browser }) => {
    skipIfNotConfigured();
    test.skip(
      !getOperatorSite(`${operator}-backend`)?.credentials,
      `營運商「${operator}」未設定後台網址或管理帳密，無法從後台查驗證碼`,
    );
    test.skip(!newFullMobile, '未取得新手機號（前面的操作被跳過），無法查驗證碼');

    newOtp = await fetchOtpFromBackend(browser, operator, newFullMobile);
    expect(newOtp, '後台簡訊記錄應查得 6 碼驗證碼').toMatch(/^\d{6}$/);
  });

  test('[前台] 完成修改並確認新手機號已生效', async () => {
    skipIfNotConfigured();
    test.skip(!newOtp, '未取得新手機號驗證碼（前面的操作被跳過）');

    const changePhone = new FrontendChangePhonePage(page);
    await test.step('輸入新手機號驗證碼並完成修改', () => changePhone.completeWithOtp(newOtp));

    await test.step('重新開啟修改手機號頁，確認目前手機號已變成新號碼', async () => {
      await changePhone.goto();
      const mobileAfterChange = await changePhone.readCurrentMobile();
      expect(mobileAfterChange, '畫面顯示的目前手機號應為剛修改的新號碼').toBe(newFullMobile);
    });
  });
});
