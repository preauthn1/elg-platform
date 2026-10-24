import { expect, type Page } from '@playwright/test';

/**
 * 前台某遊戲商的遊戲列表頁（/game/list/<遊戲商編號>）。
 * 點一款遊戲會「另開新視窗」載入遊戲，openGame 會把新視窗的 page 回傳。
 */
export class FrontendGameLobbyPage {
  constructor(private readonly page: Page) {}

  /** 開啟指定遊戲商的遊戲列表 */
  async gotoVendor(vendorId: number, vendorName: string): Promise<void> {
    await this.page.goto(`/game/list/${vendorId}`);
    await expect(
      this.page.getByText(vendorName, { exact: true }).first(),
      `遊戲列表頁應顯示遊戲商名稱「${vendorName}」`,
    ).toBeVisible({ timeout: 20_000 });
  }

  /**
   * 點指定名稱的遊戲，回傳遊戲另開的新視窗 page。
   * @param gameName 遊戲卡片上的遊戲名稱（例如 Jokers Jewels）
   */
  async openGame(gameName: string): Promise<Page> {
    const tile = this.page.getByAltText(gameName, { exact: true }).first();
    await expect(tile, `遊戲列表應找得到「${gameName}」`).toBeVisible({ timeout: 20_000 });

    // 偶爾第一下點不開新視窗：卡片還在載圖、或滾動後被固定的底部導覽列蓋住
    // （Playwright 會等「點得到」而卡住）。第二次改用 force 點，跳過遮蔽檢查。
    let lastError: unknown;
    for (let attempt = 1; attempt <= 2; attempt++) {
      try {
        await tile.scrollIntoViewIfNeeded();
        await this.page.waitForTimeout(500); // 等版面因為延遲載圖而移動的部分穩定
        const [gamePage] = await Promise.all([
          this.page.context().waitForEvent('page', { timeout: 30_000 }),
          tile.click({ force: attempt > 1, timeout: 10_000 }),
        ]);
        await gamePage.waitForLoadState('domcontentloaded');
        return gamePage;
      } catch (error) {
        lastError = error;
      }
    }

    throw new Error(
      `點了「${gameName}」兩次都沒有開出遊戲視窗（原始錯誤：${(lastError as Error)?.message}）`,
    );
  }
}
