import { test as setup } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { getOperatorSite, type OperatorKey } from '../../config/operators';
import { FrontendLoginPage } from '../../pages/frontend/LoginPage';
import { BackendLoginPage } from '../../pages/backend/LoginPage';

/**
 * 登入 setup：由 `<op>-setup` project 執行，為「有設定帳密的站台」各產生一份登入檔：
 *
 *   前台會員 → .auth/<op>-frontend.json
 *   後台管理 → .auth/<op>-backend.json
 *
 * logged / dangerous project 已設定 dependencies，QA 執行測試時會自動先跑這支，
 * 不需要手動操作。測試內的 frontendPage / backendPage fixture 會讀取對應登入檔。
 */
setup('authenticate', async ({ browser }, testInfo) => {
  const operator = testInfo.project.name.replace(/-setup$/, '') as OperatorKey;
  mkdirSync('.auth', { recursive: true });

  const frontend = getOperatorSite(`${operator}-frontend`);
  if (frontend?.credentials) {
    await setup.step('前台會員登入並保存登入檔', async () => {
      const context = await browser.newContext({ baseURL: frontend.baseURL });
      const page = await context.newPage();
      await new FrontendLoginPage(page).login(frontend.credentials!);
      await context.storageState({ path: `.auth/${operator}-frontend.json` });
      await context.close();
    });
  }

  const backend = getOperatorSite(`${operator}-backend`);
  if (backend?.credentials) {
    await setup.step('後台管理登入並保存登入檔', async () => {
      const context = await browser.newContext({ baseURL: backend.baseURL });
      const page = await context.newPage();
      await new BackendLoginPage(page).login(backend.credentials!);
      await context.storageState({ path: `.auth/${operator}-backend.json` });
      await context.close();
    });
  }

  if (!frontend?.credentials && !backend?.credentials) {
    throw new Error(
      `營運商「${operator}」前台/後台皆未設定帳密，請檢查 .env（<營運商>_MEMBER_ACCOUNT/PASSWORD、<營運商>_ADMIN_USERNAME/PASSWORD）`,
    );
  }
});
