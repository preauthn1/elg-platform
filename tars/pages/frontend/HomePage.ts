import { expect, type Page } from '@playwright/test';

/**
 * 前台首頁（/）。目前只用來讀「威幣」錢包餘額。
 * selector 已對齊 dev 站台實際 DOM：錢包那一列是「威幣 MYR 12,345.67」。
 */
export class FrontendHomePage {
  constructor(private readonly page: Page) {}

  async goto(): Promise<void> {
    await this.page.goto('/');
  }

  /**
   * 讀取「威幣」餘額（數字，已去掉千分位）。
   *
   * 首頁剛載入時餘額會先顯示 MYR0.00，等 API 回來才填上真實金額，
   * 所以這裡等到餘額大於 0 才回傳（等不到就是還沒載完，或這個帳號真的沒錢）。
   */
  async getWalletBalance(): Promise<number> {
    // 「威幣」文字所在區塊就是錢包列，往上一層拿整列文字（威幣 MYR 12,345.67）
    const walletRow = this.page.getByText('威幣', { exact: true }).locator('..');
    await expect(walletRow, '首頁應顯示威幣錢包餘額').toBeVisible({ timeout: 20_000 });

    let balance = 0;
    await expect(async () => {
      const text = (await walletRow.innerText()).replace(/\s+/g, ' ');
      const matched = text.match(/([\d,]+\.\d{2})/);
      if (!matched) {
        throw new Error(`讀不到威幣餘額，錢包列文字為「${text}」`);
      }
      balance = Number(matched[1].replace(/,/g, ''));
      expect(balance, '威幣餘額仍為 0（可能還在載入，或這個帳號沒有餘額）').toBeGreaterThan(0);
    }).toPass({ timeout: 20_000, intervals: [500, 1_000, 2_000] });

    return balance;
  }

  /**
   * 重新開首頁讀餘額，並給結算一點時間寫回（等到餘額跟先前不同為止）。
   *
   * 等不到變動**不算失敗**就直接回傳：中獎金額剛好等於押注時（例如押 3.75 中 3.75），
   * 餘額本來就會一樣。金額對不對是拿後台注單的輸贏來核對，不是看有沒有變。
   */
  async readBalanceAfterSettlement(previousBalance: number, timeoutMs = 15_000): Promise<number> {
    const deadline = Date.now() + timeoutMs;
    let balance = previousBalance;

    do {
      await this.goto();
      balance = await this.getWalletBalance();
      if (balance !== previousBalance) return balance;
    } while (Date.now() < deadline);

    return balance;
  }
}
