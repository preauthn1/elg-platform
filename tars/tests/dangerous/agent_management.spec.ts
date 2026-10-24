import { test, expect } from '../../fixtures/sites';
import { makeAccount } from '../../utils/random';
import { getOperatorSite } from '../../config/operators';
import { BackendAgentPage } from '../../pages/backend/AgentPage';
import { BackendLoginPage } from '../../pages/backend/LoginPage';

/**
 * 後台・代理帳號管理（dangerous）。
 *
 * 兩個測試案例都會建立 qaag 開頭的新代理帳號（掛在登入的總代底下），
 * 每跑一次留一個代理帳號，屬正常現象；可在後台用前綴搜尋清理。
 * 帳號等伺服器端狀態跨原子操作共用；backendPage 每個操作各自開啟（同一份 admin 登入態）。
 */
test.describe.serial('[後台] 驗證可建立代理並掛在總代底下', () => {
  const account = makeAccount('qaag');

  test('[後台] 建立代理', async ({ backendPage }) => {
    const agentPage = new BackendAgentPage(backendPage);

    // 建立：上層代理欄位預設即為目前登入的總代
    await test.step(`開啟建立代理頁並送出（帳號 ${account.username}）`, async () => {
      await agentPage.gotoCreate();
      await agentPage.createAgent(account.username, account.password);
    });
  });

  test('[後台] 列表查得到且上層為總代', async ({ backendPage }, testInfo) => {
    // 依 project 名反查總代帳號（後台登入者），不寫死帳號
    const operator = testInfo.project.name.replace(/-dangerous$/, '');
    const admin = getOperatorSite(`${operator}-backend`)?.credentials?.username;
    test.skip(!admin, '未設定後台管理帳密');

    const agentPage = new BackendAgentPage(backendPage);

    await test.step(`到代理列表搜尋 ${account.username}`, () =>
      agentPage.searchAgent(account.username));

    await test.step('確認查得到且上層代理為總代', async () => {
      const row = agentPage.agentRow(account.username);
      await expect(row, '列表應查得到剛建立的代理').toHaveCount(1);
      await expect(row, '上層代理應為總代').toContainText(admin!);
    });

    // 把帳號附在報告上，方便事後對照/清理
    await testInfo.attach('本次建立的代理', {
      body: JSON.stringify({ username: account.username, parent: admin }, null, 2),
      contentType: 'application/json',
    });
  });
});

/**
 * 代理「限制登入 / 解除限制登入」（tickets#4113 / #1988）。
 *
 * 前置條件：登入的後台帳號需持有 agent_sys_lock 權限；
 * 功能尚未部署或無權限時，下拉選單不會出現該項目，自「限制登入」起自動跳過。
 */
test.describe.serial('[後台] 驗證可限制代理登入並解除', () => {
  const account = makeAccount('qaag');
  // 「限制登入」確認功能可用後翻 true；serial 只會在「失敗」時跳過後續，skip 不會，故自行傳遞
  let featureAvailable = false;

  test('[後台] 建立測試代理', async ({ backendPage }) => {
    const agentPage = new BackendAgentPage(backendPage);

    // 建立專屬測試代理，避免影響既有帳號
    await test.step(`開啟建立代理頁並送出（帳號 ${account.username}）`, async () => {
      await agentPage.gotoCreate();
      await agentPage.createAgent(account.username, account.password);
    });

    await test.step('確認代理列表查得到', async () => {
      await agentPage.searchAgent(account.username);
      await expect(agentPage.agentRow(account.username)).toHaveCount(1);
    });
  });

  test('[後台] 限制登入', async ({ backendPage }) => {
    const agentPage = new BackendAgentPage(backendPage);
    await test.step(`到代理列表搜尋 ${account.username}`, () =>
      agentPage.searchAgent(account.username));

    // 功能未部署或帳號無 agent_sys_lock 權限時，下拉選單沒有此項目 → 跳過
    await test.step('確認下拉選單有「限制登入」項目', async () => {
      await agentPage.openRowActions(account.username);
      if (!(await agentPage.sysLockAction(account.username, 'restrict').isVisible())) {
        test.skip(true, '下拉選單無「限制登入」：功能未部署或登入帳號無 agent_sys_lock 權限');
      }
      featureAvailable = true;
      await backendPage.keyboard.press('Escape'); // 收起選單，交給 toggleSysLock 重新展開
    });

    await test.step('執行限制登入（填備註送出）', () =>
      agentPage.toggleSysLock(account.username, 'restrict', 'e2e 限制登入測試'));

    await test.step('確認列表狀態顯示 Restrict login', async () => {
      await agentPage.searchAgent(account.username);
      await expect(
        agentPage.agentRow(account.username),
        '限制後列表狀態應顯示 Restrict login',
      ).toContainText('Restrict login');
    });
  });

  test('[後台] 驗證操作紀錄', async ({ backendPage }) => {
    test.skip(!featureAvailable, '「限制登入」已跳過：功能未部署或無權限');

    const agentPage = new BackendAgentPage(backendPage);
    await test.step('開啟該代理的操作紀錄彈窗', async () => {
      await agentPage.searchAgent(account.username);
      await agentPage.openStatusLog(account.username);
    });

    await test.step('確認出現限制登入的備註', () =>
      expect(
        backendPage.locator('#status_log_list'),
        '操作紀錄應出現限制登入的備註',
      ).toContainText('e2e 限制登入測試'));
  });

  test('[後台] 解除限制', async ({ backendPage }) => {
    test.skip(!featureAvailable, '「限制登入」已跳過：功能未部署或無權限');

    const agentPage = new BackendAgentPage(backendPage);
    await test.step('執行解除限制（填備註送出）', async () => {
      await agentPage.searchAgent(account.username);
      await agentPage.toggleSysLock(account.username, 'remove', 'e2e 解除限制測試');
    });

    await test.step('確認狀態恢復 Enable', async () => {
      await agentPage.searchAgent(account.username);
      const row = agentPage.agentRow(account.username);
      await expect(row, '解除後狀態應恢復 Enable').toContainText('Enable');
      await expect(row, '解除後不應再顯示 Restrict login').not.toContainText('Restrict login');
    });
  });
});

/**
 * 代理帳號「停用」（Enable/Disable 狀態切換）後應無法登入後台。
 *
 * 與上面的「限制登入」（sys_lock）是不同機制：這裡測的是帳號狀態本身。
 * 停用後嘗試登入，後台會跳出瀏覽器原生 alert「Incorrect username or password」，
 * 跟帳密輸入錯誤顯示同一句訊息，不會特別提示「帳號已停用」。
 */
test.describe.serial('[後台] 驗證代理帳號停用後無法登入後台', () => {
  const account = makeAccount('qaag');

  test('[後台] 建立測試代理', async ({ backendPage }) => {
    const agentPage = new BackendAgentPage(backendPage);

    // 建立專屬測試代理，避免影響既有帳號
    await test.step(`開啟建立代理頁並送出（帳號 ${account.username}）`, async () => {
      await agentPage.gotoCreate();
      await agentPage.createAgent(account.username, account.password);
    });

    await test.step('確認代理列表查得到，狀態為 Enable', async () => {
      await agentPage.searchAgent(account.username);
      await expect(agentPage.agentRow(account.username), '新建代理預設應為 Enable').toContainText(
        'Enable',
      );
    });
  });

  test('[後台] 停用該代理', async ({ backendPage }) => {
    const agentPage = new BackendAgentPage(backendPage);
    await test.step(`到代理列表搜尋 ${account.username}`, () =>
      agentPage.searchAgent(account.username));

    await test.step('執行停用（填備註送出）', () =>
      agentPage.toggleStatus(account.username, 'disable', 'e2e 停用測試'));

    await test.step('確認列表狀態顯示 Disable', async () => {
      await agentPage.searchAgent(account.username);
      await expect(
        agentPage.agentRow(account.username),
        '停用後列表狀態應顯示 Disable',
      ).toContainText('Disable');
    });
  });

  test('[後台] 驗證該代理無法登入後台', async ({ browser }, testInfo) => {
    // 例外說明：這一步驗證的是「未登入狀態下嘗試登入」，backendPage fixture 會直接帶
    // 總代登入態，所以改自建一個乾淨、未登入的 page（同 tests/logged/agent_login.spec.ts 的作法）
    const operator = testInfo.project.name.replace(/-dangerous$/, '');
    const site = getOperatorSite(`${operator}-backend`);
    test.skip(!site, `營運商「${operator}」未設定後台網址`);

    const page = await browser.newPage({ baseURL: site!.baseURL });
    try {
      const loginPage = new BackendLoginPage(page);

      await test.step('用已停用的代理帳密嘗試登入', async () => {
        const message = await loginPage.loginExpectFailure({
          username: account.username,
          password: [REDACTED],
        });
        expect(message, '應跳出登入失敗提示').toContain('Incorrect username or password');
      });

      await test.step('確認仍停留在登入頁，未成功進入後台', async () => {
        await expect(page, '停用帳號不應能登入後台').toHaveURL(/login\.php/);
      });
    } finally {
      if (page.isClosed() === false && testInfo.status !== testInfo.expectedStatus) {
        await testInfo.attach('失敗當下畫面（後台）', {
          body: await page.screenshot({ fullPage: true }),
          contentType: 'image/png',
        });
      }
      await page.close();
    }
  });
});
