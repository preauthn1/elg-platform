import { test, expect } from '../../fixtures/sites';
import type { Page } from '@playwright/test';
import { getOperatorSite, type OperatorKey, type OperatorSite } from '../../config/operators';
import type { GameVendor } from '../../config/gameVendors';
import { FrontendLoginPage } from '../../pages/frontend/LoginPage';
import { FrontendHomePage } from '../../pages/frontend/HomePage';
import { FrontendGameLobbyPage } from '../../pages/frontend/GameLobbyPage';
import { SlotGame } from '../../pages/frontend/games/SlotGame';
import { BetRecordPage } from '../../pages/backend/BetRecordPage';
import { CashRecordPage } from '../../pages/backend/CashRecordPage';

/**
 * 「前台玩一局 → 後台對報表」的共用測試案例（單一錢包遊戲商）。
 *
 * 每個遊戲商在 `tests/dangerous/` 有一支自己的 spec，內容只有一行呼叫這裡——
 * 這樣 UI 模式的檔案清單一眼就看到有哪幾家、可以單獨跑，共用流程卻只有一份。
 *
 * dangerous：每跑一次都會真的下一注、真的扣點。
 * 用 .env 的「有餘額會員」（<營運商>_GAME_MEMBER_ACCOUNT/PASSWORD），跟 logged 分類
 * 共用的固定會員分開，免得下注把那個帳號的餘額吃掉。
 *
 * 例外說明：不用 frontendPage fixture（那是登入「固定會員」），改在 beforeAll 自建 page
 * 並以有餘額的會員登入，原子操作間共用同一個瀏覽器 session；後台查報表直接用
 * backendPage fixture（已是 admin 登入態）。
 *
 * 視窗固定 1280×720：老虎機是遊戲商用 canvas 畫的，轉動鈕只能按畫面比例點
 * （見 pages/frontend/games/SlotGame.ts），換視窗大小就要重新校準。
 */
export function registerGameBetTests(vendor: GameVendor): void {
  test.describe.serial(
    `[前後台] 驗證會員在 ${vendor.name} 下注後可在後台報表查到注單與扣點`,
    { tag: ['@game-bet', vendor.tag] },
    () => {
      let page: Page;
      let gamePage: Page | undefined;
      let site: OperatorSite | undefined;
      let operator: OperatorKey;
      let balanceBeforeBet = 0;
      let balanceAfterBet = 0;
      let spinStartedAt = new Date();

      test.beforeAll(async ({ browser }, testInfo) => {
        operator = testInfo.project.name.replace(/-dangerous$/, '') as OperatorKey;
        site = getOperatorSite(`${operator}-frontend`);
        // 自建 page（未登入）：登入的是「有餘額的會員」，不是 fixture 的固定會員
        page = await browser.newPage({
          baseURL: site?.baseURL,
          viewport: { width: 1280, height: 720 },
        });
      });

      test.afterAll(async () => {
        await gamePage?.close();
        await page?.close();
      });

      // 自建 page 不走 fixture，失敗時不會自動截圖，這裡手動補上供 QA 除錯
      test.afterEach(async ({}, testInfo) => {
        if (testInfo.status === testInfo.expectedStatus) return;
        if (page && !page.isClosed()) {
          await testInfo.attach('失敗當下畫面（前台）', {
            body: await page.screenshot({ fullPage: true }),
            contentType: 'image/png',
          });
        }
        if (gamePage && !gamePage.isClosed()) {
          await testInfo.attach('失敗當下畫面（遊戲）', {
            body: await gamePage.screenshot(),
            contentType: 'image/png',
          });
        }
      });

      /**
       * 環境未設定時每個原子操作都要 skip（serial 只會傳遞「失敗」，不會傳遞 skip）。
       * 跳過前先印一行原因：VS Code 測試面板只會顯示測試的輸出，沒有這行 QA 會看到
       * 「未報告任何輸出」，分不清是環境沒設定還是測試壞掉。
       */
      const skipIfNotConfigured = () => {
        const upper = operator.toUpperCase();
        const reason = !site
          ? `營運商「${operator}」未設定前台網址（${upper}_FRONTEND_URL）`
          : !site.gameCredentials
            ? `營運商「${operator}」未設定有餘額的會員帳密（${upper}_GAME_MEMBER_ACCOUNT／${upper}_GAME_MEMBER_PASSWORD）`
            : undefined;
        if (!reason) return;

        console.log(`⏭  這支測試被跳過：${reason}。請在專案根目錄的 .env 補上後重跑。`);
        test.skip(true, reason);
      };

      test('[前台] 以有餘額的會員登入並記下餘額', async () => {
        skipIfNotConfigured();

        await test.step('以有餘額的會員帳密登入前台', () =>
          new FrontendLoginPage(page).login(site!.gameCredentials!));

        balanceBeforeBet = await test.step('讀取首頁的威幣餘額', () =>
          new FrontendHomePage(page).getWalletBalance());

        expect(balanceBeforeBet, '會員餘額要大於 0 才有辦法下注').toBeGreaterThan(0);
        console.log(`下注前餘額：${balanceBeforeBet}（會員 ${site!.gameCredentials!.username}）`);
      });

      test(`[前台] 進入 ${vendor.name} 並開啟遊戲`, async () => {
        skipIfNotConfigured();
        // serial 只會傳遞「失敗」不會傳遞 skip，用餘額當旗標擋下游
        test.skip(!balanceBeforeBet, '前一步沒讀到餘額，不繼續下注');

        const lobby = new FrontendGameLobbyPage(page);

        await test.step(`開啟 ${vendor.name} 的遊戲列表`, () =>
          lobby.gotoVendor(vendor.id, vendor.name));

        gamePage = await test.step(`點開遊戲「${vendor.gameName}」（會另開視窗）`, () =>
          lobby.openGame(vendor.gameName));

        await test.step('確認遊戲商的遊戲畫面已載入', () =>
          new SlotGame(gamePage!, vendor).waitForLoaded());
      });

      test('[前台] 下一注並記下結算後的餘額', async () => {
        skipIfNotConfigured();
        test.skip(!gamePage, '遊戲沒開起來，無法下注');

        spinStartedAt = new Date();

        // spinOnce 會等遊戲商收到這一注（下注請求）才算完成，按空了會在這裡就報錯
        await test.step('按下轉動鈕下一注，等這一局結算完', () =>
          new SlotGame(gamePage!, vendor).spinOnce());

        balanceAfterBet = await test.step('關掉遊戲、回前台首頁重新讀餘額', async () => {
          await gamePage!.close(); // 跟玩家一樣：玩完關掉遊戲視窗再回大廳看餘額
          const home = new FrontendHomePage(page);
          try {
            return await home.readBalanceAfterSettlement(balanceBeforeBet);
          } catch {
            // 開遊戲會另發一組 token，前台原本的登入有機會被頂掉（餘額讀成 0）→ 重新登入再讀
            await new FrontendLoginPage(page).login(site!.gameCredentials!);
            return home.readBalanceAfterSettlement(balanceBeforeBet);
          }
        });

        // 這裡刻意不斷言「餘額一定要變」：中獎金額剛好等於押注時餘額本來就一樣。
        // 這一局有沒有真的記帳，交給後面兩步用後台報表核對（注單存在 + 金額對得上）。
        expect(balanceAfterBet, '應讀得到結算後的餘額').toBeGreaterThan(0);
        console.log(
          `下注後餘額：${balanceAfterBet}（前後差額 ${(balanceAfterBet - balanceBeforeBet).toFixed(2)}）`,
        );
      });

      test('[後台] SR12 遊戲投注記錄應查到這一局的注單', async ({ backendPage }, testInfo) => {
        skipIfNotConfigured();
        test.skip(!balanceAfterBet, '前面沒有成功下注，沒有注單可查');
        test.slow(); // 注單寫進報表可能有延遲，這步允許多輪重試

        const memberAccount = site!.gameCredentials!.username;
        // 報表寫入可能有幾秒延遲，內建輪詢重試；只接受「按下轉動鈕之後」的注單
        // 容忍本機與伺服器時鐘些許誤差；不要放太寬，否則會把「上一次執行」的注單也算進來
        const tolerance = 30 * 1000;
        const records = await test.step(
          `查 SR12：會員 ${memberAccount} × ${vendor.name} × 今天`,
          () =>
            new BetRecordPage(backendPage).waitForBetsAfter(
              memberAccount,
              vendor.id,
              new Date(spinStartedAt.getTime() - tolerance),
            ),
        );

        const [record] = records;
        expect(record.memberAccount, '注單的會員帳號應為剛剛下注的會員').toBe(memberAccount);
        expect(record.gameName, '注單應記在剛剛玩的那款遊戲').toBe(vendor.gameName);
        expect(record.bet, '注單的押注金額應大於 0').toBeGreaterThan(0);
        expect(record.validBet, '注單的有效押注應大於 0').toBeGreaterThan(0);
        expect(record.orderNumber, '注單應有訂單編號').not.toBe('');

        // 跨系統核對：前台餘額的變動額，要等於後台注單記的輸贏（派彩 − 押注）
        const winLoseInReport = records.reduce((sum, r) => sum + r.winLose, 0);
        const balanceDelta = Number((balanceAfterBet - balanceBeforeBet).toFixed(2));
        expect(balanceDelta, '前台餘額的變動額應等於後台注單記的輸贏').toBeCloseTo(
          winLoseInReport,
          2,
        );

        await testInfo.attach('SR12 查到的注單', {
          body: JSON.stringify({ records, balanceDelta, winLoseInReport }, null, 2),
          contentType: 'application/json',
        });
      });

      test('[後台] SR14 現金紀錄表應有這一注且當下點數與前台餘額一致', async (
        { backendPage },
        testInfo,
      ) => {
        skipIfNotConfigured();
        test.skip(!balanceAfterBet, '前面沒有成功下注，沒有點數變動可查');
        test.slow(); // 報表寫入可能有延遲，這步允許多輪重試

        const memberAccount = site!.gameCredentials!.username;
        const tolerance = 30 * 1000;

        // 單一錢包的押注在 SR14 不是一注一列：同一個遊戲場次合併成一列「Single wallet Game Bet」，
        // 日期記場次第一注那天（可能是好幾天前）、狀態 Gaming 直到場次結束、
        // 變更點數是場次累計淨額（可能是 0 或正數）。所以只查「今天」會查不到、也不能斷言一定是扣點；
        // 改成往前查、找這家最新的場次列，點開 Detail 確認這一注真的記在裡面。
        const { row, bets } = await test.step(
          `查 SR14：會員 ${memberAccount} 最近 30 天 × ${vendor.name}，點開明細找這一注`,
          () =>
            new CashRecordPage(backendPage).waitForVendorBetsAfter(
              memberAccount,
              vendor.id,
              new Date(spinStartedAt.getTime() - tolerance),
            ),
        );

        expect(row.memberAccount, '點數變動應記在下注的會員身上').toBe(memberAccount);
        expect(bets[0].bet, '明細裡這一注的押注金額應大於 0').toBeGreaterThan(0);
        expect(bets[0].orderNumber, '明細裡這一注應有訂單編號').not.toBe('');
        expect(row.currentPoints, '後台的當下點數應等於前台看到的餘額').toBe(balanceAfterBet);

        await testInfo.attach('SR14 查到的場次列與這一注明細', {
          body: JSON.stringify({ row, bets }, null, 2),
          contentType: 'application/json',
        });
      });
    },
  );
}
