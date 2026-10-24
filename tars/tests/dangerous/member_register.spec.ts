import { test, expect, type Page } from '@playwright/test';
import { getOperatorSite, type OperatorKey, type OperatorSite } from '../../config/operators';
import { FrontendRegisterPage } from '../../pages/frontend/RegisterPage';
import { fetchOtpFromBackend } from '../../utils/backendOtp';
import { makeAccount, type RandomAccount } from '../../utils/random';

/**
 * 前台會員註冊（dangerous：每跑一次當場註冊一組全新帳號）。
 *
 * 例外說明：這個案例驗證的就是「註冊」本身，所以不用 member fixture
 * （fixture 會在測試開始前把註冊做完，步驟被收進 Before Hooks 看不見），
 * 改在 beforeAll 自建乾淨 page、原子操作間共用同一 session——
 * 註冊流程的每個階段（填表/發簡訊/後台查碼/送出）都是看得見的原子操作。
 */
test.describe.serial('[前後台] 驗證會員可完成註冊並為登入態', () => {
  let page: Page;
  let site: OperatorSite | undefined;
  let operator: OperatorKey;
  let account: RandomAccount;
  let otp = '';

  test.beforeAll(async ({ browser }, testInfo) => {
    operator = testInfo.project.name.replace(/-dangerous$/, '') as OperatorKey;
    site = getOperatorSite(`${operator}-frontend`);
    account = makeAccount();
    // 自建 page（未登入）：讓「註冊」從未登入狀態真實執行
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
      !site?.referralCode,
      `營運商「${operator}」缺少推廣碼，請於 .env 設定 <營運商>_REFERRAL_CODE`,
    );
  };

  test('[前台] 填寫註冊資料並發送簡訊', async () => {
    skipIfNotConfigured();
    const register = new FrontendRegisterPage(page);

    await test.step('開啟註冊頁並填寫基本資料（帳號/姓名/密碼/推廣碼）', async () => {
      await register.goto();
      await register.fillStep1(account, site!.referralCode!);
    });

    await test.step('填入手機號碼並發送簡訊', () => register.requestSms(account));
  });

  test('[後台] 從簡訊記錄查詢驗證碼', async ({ browser }) => {
    skipIfNotConfigured();
    test.skip(
      !getOperatorSite(`${operator}-backend`)?.credentials,
      `營運商「${operator}」未設定後台網址或管理帳密，無法從後台查驗證碼`,
    );

    const fullMobile = `${site!.phoneCountryCode ?? '60'}${account.phone}`;
    otp = await fetchOtpFromBackend(browser, operator, fullMobile);
    expect(otp, '後台簡訊記錄應查得 6 碼驗證碼').toMatch(/^\d{6}$/);
  });

  test('[前台] 填入驗證碼完成註冊', async () => {
    skipIfNotConfigured();
    // serial 只會傳遞「失敗」不會傳遞 skip，用 otp 是否取得當旗標擋下游
    test.skip(!otp, '未取得驗證碼（前面的操作被跳過），無法完成註冊');

    await test.step('填入驗證碼、送出並確認離開註冊頁', () =>
      new FrontendRegisterPage(page).completeWithOtp(otp));
  });

  test('[前台] 確認註冊後為登入態', async ({}, testInfo) => {
    skipIfNotConfigured();
    test.skip(!otp, '未取得驗證碼（前面的操作被跳過），無法完成註冊');

    await test.step('開啟前台首頁', () => page.goto('/'));

    await test.step('確認已是登入態（未被導回註冊/登入頁）', async () => {
      await expect(page, '不應被導回註冊/登入頁').not.toHaveURL(/signup|login/i);
      await expect(page.locator('body')).toBeVisible();
    });

    // 把帳號附在報告上，方便對照 / 事後清理
    await testInfo.attach('本次註冊的帳號', {
      body: JSON.stringify(
        { username: account.username, phone: account.phone, realName: account.realName },
        null,
        2,
      ),
      contentType: 'application/json',
    });
    console.log(`本次註冊帳號：${account.username}　密碼：${account.password}`);
  });
});
