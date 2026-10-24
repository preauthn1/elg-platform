import { defineConfig, devices, type Project } from '@playwright/test';
import 'dotenv/config';
import { OPERATOR_KEYS, resolveOperatorSites, warnMissingEnv } from './config/operators';

const operatorSites = resolveOperatorSites();

if (operatorSites.length === 0) {
  // 沒有任何營運商 URL 被設定：多半是還沒建立 .env
  console.warn(
    '\n⚠️  尚未偵測到任何營運商網址。請先複製 .env.example 為 .env 並填入網址／帳密。\n' +
      '   詳見 README.md 的「快速開始」。\n',
  );
} else {
  // .env 有網址但帳密沒填完：印出缺漏清單，讓 QA 知道哪些測試會被跳過、為什麼
  warnMissingEnv();
}

/**
 * 每個營運商產生三個 project（不分前後台）：
 *
 *   <op>-guest      未登入操作（開登入頁、公開頁）
 *   <op>-logged     需登入態、不留資料的操作
 *   <op>-dangerous  會產生髒資料的操作（註冊、建立代理…）
 *
 * project 不再設定 baseURL / storageState——站台由測試內的
 * `frontendPage`（前台）/ `backendPage`（後台）fixture 決定（見 fixtures/sites.ts），
 * 一個測試案例可以混用前後台的原子操作。
 * 登入態由 <op>-setup 預先產生（.auth/<op>-frontend.json、.auth/<op>-backend.json）。
 */
const projects: Project[] = [];

for (const operator of OPERATOR_KEYS) {
  const sites = operatorSites.filter((s) => s.operator === operator);
  if (sites.length === 0) continue; // 這個營運商沒設定任何網址

  const base = { ...devices['Desktop Chrome'] };
  // 任一站台有帳密就需要 setup（各自寫出該站台的登入檔）
  const hasCredentials = sites.some((s) => s.credentials);

  if (hasCredentials) {
    projects.push({
      name: `${operator}-setup`,
      testMatch: /auth\.setup\.ts/,
      use: base,
    });
  }

  projects.push({
    name: `${operator}-guest`,
    testMatch: /tests\/guest\/.*\.spec\.ts/,
    use: base,
  });

  projects.push({
    name: `${operator}-logged`,
    testMatch: /tests\/logged\/.*\.spec\.ts/,
    use: base,
    dependencies: hasCredentials ? [`${operator}-setup`] : [],
  });

  projects.push({
    name: `${operator}-dangerous`,
    testMatch: /tests\/dangerous\/.*\.spec\.ts/,
    use: base,
    dependencies: hasCredentials ? [`${operator}-setup`] : [],
  });
}

export default defineConfig({
  testDir: '.',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: process.env.CI
    ? [['list'], ['html', { open: 'never' }]]
    : // 本機執行多掛一個歷史保留器：每次的報告存進 reports/history/（最多 20 次），
      // QA 用 Run Task「開啟歷史報告」可回頭看任何一次
      [['html', { open: 'never' }], ['list'], ['./scripts/history-reporter.ts']],
  timeout: 60_000,
  expect: { timeout: 10_000 },
  use: {
    // dev 測試帳密較簡單，避免 Chrome 跳「密碼已外洩，請變更密碼」彈窗干擾畫面/截圖
    launchOptions: {
      args: ['--disable-features=PasswordLeakDetection', '--disable-save-password-bubble'],
    },
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
    locale: 'zh-TW',
    timezoneId: 'Asia/Taipei',
  },
  projects,
});
