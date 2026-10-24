import { expect, type Page } from '@playwright/test';
import type { GameVendor } from '../../../config/gameVendors';

/**
 * 老虎機遊戲頁（/game/<遊戲編號>?token=[REDACTED]
 *
 * 這頁是「遊戲商自己的畫面」：我方頁面只放一個 iframe（#game-iframe），
 * 裡面整張是遊戲商用 canvas 畫出來的，**沒有任何可以定位的元素**——
 * 所以按鈕只能用「畫面比例位置」去點，這是唯一可行的做法（不是偷懶）。
 *
 * 各家的轉動鈕位置寫在 `config/gameVendors.ts`（1280×720 校準）；
 * 如果哪一家的玩法跟「按一下轉動鈕就是一注」不同，再為那家單獨寫一個 game 類別。
 */
export class SlotGame {
  constructor(
    private readonly page: Page,
    private readonly vendor: GameVendor,
  ) {}

  /** 確認遊戲已由遊戲商載入（iframe 指向遊戲商網域、畫面渲染完成） */
  async waitForLoaded(): Promise<void> {
    const gameFrame = this.page.locator('#game-iframe');
    await expect(gameFrame, '遊戲頁應載入遊戲商的遊戲畫面').toBeVisible({ timeout: 30_000 });
    await expect(gameFrame, '遊戲 iframe 應指向遊戲商網址').toHaveAttribute('src', /^https?:\/\//);
    // canvas 遊戲沒有可等的元素，給遊戲商畫面一點時間完成初始化
    await this.page.waitForTimeout(8_000);
  }

  /**
   * 按一次轉動鈕（= 下一注），並等這一局跑完結算。
   *
   * 畫面是 canvas、沒有元素可等，所以改「等遊戲商的下注請求」來確認這一注真的送出去了
   * （老虎機下注是一支帶 doSpin 的 POST）。等不到就代表按空了——通常是轉動鈕位置要重新校準。
   */
  async spinOnce(): Promise<void> {
    const viewport = this.page.viewportSize();
    if (!viewport) {
      throw new Error('取不到視窗大小，無法定位轉動鈕');
    }

    const spinRequestSent = this.page
      .waitForResponse(
        (response) =>
          response.request().method() === 'POST' &&
          (response.request().postData() ?? '').includes('doSpin'),
        { timeout: 30_000 },
      )
      .catch(() => {
        throw new Error(
          `按下轉動鈕後沒有送出下注請求：${this.vendor.name} 的轉動鈕位置可能要重新校準` +
            '（config/gameVendors.ts 的 spinButton），或遊戲尚未載入完成',
        );
      });

    const { xRatio, yRatio } = this.vendor.spinButton;
    await this.page.mouse.click(
      Math.round(viewport.width * xRatio),
      Math.round(viewport.height * yRatio),
    );
    await spinRequestSent;

    // 下注已送出，再等停輪與派彩結算寫回
    await this.page.waitForTimeout(6_000);
  }
}
