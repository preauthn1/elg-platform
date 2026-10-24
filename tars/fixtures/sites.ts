import { test as base, type Page, type Browser, type TestInfo } from '@playwright/test';
import { existsSync } from 'node:fs';
import { getOperatorSite, type OperatorKey, type OperatorSite } from '../config/operators';
import { FrontendLoginPage } from '../pages/frontend/LoginPage';
import { BackendLoginPage } from '../pages/backend/LoginPage';
import { FrontendRegisterPage } from '../pages/frontend/RegisterPage';
import { fetchOtpFromBackend } from '../utils/backendOtp';
import { makeAccount, type RandomAccount } from '../utils/random';

/**
 * 站台 fixtures：project 不分前後台（<op>-guest / logged / dangerous），
 * 測試內的原子操作用這裡的 fixture 決定要操作哪個站台：
 *
 *   frontendPage  前台（會員站）頁面
 *   backendPage   後台（代理管理站）頁面
 *   member        當場隨機註冊一組新前台帳號（dangerous 用），註冊完 frontendPage 即為登入態
 *
 * 登入態依 project 分類自動決定：
 *   guest      → 兩站都是全新未登入
 *   logged     → 前台=會員登入態、後台=admin 登入態（讀 setup 產生的 .auth/*.json）
 *   dangerous  → 前台=未登入（配 member 當場註冊）、後台=admin 登入態
 *
 * 對應站台未設定網址/帳密時自動 skip 並寫明原因。
 * fixture 自建的 context 不走內建截圖，失敗時這裡手動附「失敗當下畫面」。
 */

type Category = 'guest' | 'logged' | 'dangerous';

function parseProject(testInfo: TestInfo): { operator: OperatorKey; category: Category } {
  const match = testInfo.project.name.match(/^(.+)-(guest|logged|dangerous)$/);
  if (!match) {
    throw new Error(`無法從 project「${testInfo.project.name}」解析營運商與分類`);
  }
  return { operator: match[1] as OperatorKey, category: match[2] as Category };
}

/** 建立指定站台的 page；needAuth 時優先讀 setup 登入檔，缺檔則當場登入 */
async function openSitePage(
  browser: Browser,
  site: OperatorSite,
  authFile: string | null,
  liveLogin: (page: Page) => Promise<void>,
): Promise<Page> {
  const storageState = authFile && existsSync(authFile) ? authFile : undefined;
  const context = await browser.newContext({ baseURL: site.baseURL, storageState });
  const page = await context.newPage();

  if (authFile && !storageState) {
    await liveLogin(page); // setup 登入檔不存在（例如單獨跑一支測試）→ 當場登入
  }
  return page;
}

/** 測試失敗時把該站台當下畫面附進報告（自建 context 不會自動截圖） */
async function attachOnFailure(page: Page, testInfo: TestInfo, label: string): Promise<void> {
  if (testInfo.status !== testInfo.expectedStatus && !page.isClosed()) {
    await testInfo.attach(label, {
      body: await page.screenshot({ fullPage: true }),
      contentType: 'image/png',
    });
  }
}

type SiteFixtures = {
  frontendPage: Page;
  backendPage: Page;
  member: RandomAccount;
};

export const test = base.extend<SiteFixtures>({
  frontendPage: async ({ browser }, use, testInfo) => {
    const { operator, category } = parseProject(testInfo);
    const site = getOperatorSite(`${operator}-frontend`);
    testInfo.skip(!site, `營運商「${operator}」未設定前台網址（<營運商>_FRONTEND_URL）`);

    const needAuth = category === 'logged';
    testInfo.skip(
      needAuth && !site!.credentials,
      `營運商「${operator}」未設定前台會員帳密（<營運商>_MEMBER_ACCOUNT/PASSWORD）`,
    );

    const page = await openSitePage(
      browser,
      site!,
      needAuth ? `.auth/${operator}-frontend.json` : null,
      (p) => new FrontendLoginPage(p).login(site!.credentials!),
    );

    await use(page);

    await attachOnFailure(page, testInfo, '失敗當下畫面（前台）');
    await page.context().close();
  },

  backendPage: async ({ browser }, use, testInfo) => {
    const { operator, category } = parseProject(testInfo);
    const site = getOperatorSite(`${operator}-backend`);
    testInfo.skip(!site, `營運商「${operator}」未設定後台網址（<營運商>_BACKEND_URL）`);

    // 後台的 logged / dangerous 都需要 admin 登入態
    const needAuth = category !== 'guest';
    testInfo.skip(
      needAuth && !site!.credentials,
      `營運商「${operator}」未設定後台管理帳密（<營運商>_ADMIN_USERNAME/PASSWORD）`,
    );

    const page = await openSitePage(
      browser,
      site!,
      needAuth ? `.auth/${operator}-backend.json` : null,
      (p) => new BackendLoginPage(p).login(site!.credentials!),
    );

    await use(page);

    await attachOnFailure(page, testInfo, '失敗當下畫面（後台）');
    await page.context().close();
  },

  /**
   * 當場隨機註冊一組全新前台帳號並登入（frontendPage 上完成）。
   * 簡訊驗證碼：dev 沒有固定測試碼，由 fetchOtpFromBackend 另開後台 context 查簡訊記錄。
   * 註冊的帳號只屬於該支測試；帳號資訊（username / password / realName / phone）由 member 帶回。
   */
  member: async ({ frontendPage, browser }, use, testInfo) => {
    const { operator } = parseProject(testInfo);
    const site = getOperatorSite(`${operator}-frontend`);
    testInfo.skip(
      !site?.referralCode,
      `營運商「${operator}」缺少推廣碼，請於 .env 設定 <營運商>_REFERRAL_CODE`,
    );

    const account = makeAccount();
    // 外層 step 讓「Before Hooks」展開時一眼可辨這段是當場註冊（內含前後台子步驟）
    await base.step(`【準備】前台註冊新會員 ${account.username}（驗證碼從後台簡訊記錄查）`, () =>
      new FrontendRegisterPage(frontendPage).register(account, {
        referralCode: site!.referralCode!,
        countryCode: site!.phoneCountryCode,
        getOtp: (fullMobile) => fetchOtpFromBackend(browser, operator, fullMobile),
      }));

    // 把帳號附在報告上，測試失敗時方便對照 / 事後清理
    await testInfo.attach('本次註冊的帳號', {
      body: JSON.stringify(
        { username: account.username, phone: account.phone, realName: account.realName },
        null,
        2,
      ),
      contentType: 'application/json',
    });
    await use(account);
  },
});

export { expect } from '@playwright/test';
