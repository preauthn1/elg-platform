import { expect, type Page } from '@playwright/test';
import { dayRange } from '../../utils/dateRange';

/** SR12 遊戲投注記錄的一列（注單明細） */
export interface BetRecord {
  /** 押注時間（報表顯示的字串，例如 2026-08-20 12:13:27） */
  betTime: string;
  agentAccount: string;
  memberAccount: string;
  gameName: string;
  bet: number;
  validBet: number;
  payout: number;
  winLose: number;
  orderNumber: string;
}

/**
 * 後台「統計報表查詢 > SR12 遊戲投注記錄」（report/sr12.php）。
 *
 * 查詢條件用網址帶入（遊戲商是 selectize 下拉，用網址帶最穩），
 * 帶完仍要按一次 Search 才會出結果表格。
 */
export class BetRecordPage {
  constructor(private readonly page: Page) {}

  /**
   * 查某會員在某遊戲商「今天」的注單。
   * @param memberAccount 會員帳號
   * @param vendorId 遊戲商編號（例如 Pxplay SW = 236）
   */
  async searchToday(memberAccount: string, vendorId: number): Promise<void> {
    const { start, end } = dayRange();
    const params = new URLSearchParams({
      type: '2', // 查詢類型：會員帳號
      username: memberAccount,
      vendor_id: String(vendorId),
      start_time: start,
      end_time: end,
      whitch_time: '2', // 時間依據：結算時間
      per_page: '25',
      act: '1',
    });

    await this.page.goto(`/report/sr12.php?${params.toString()}`);
    await this.page.getByRole('button', { name: 'Search' }).first().click();
    await expect(this.page.locator('table').first(), '應出現注單查詢結果表格').toBeVisible({
      timeout: 20_000,
    });
  }

  /** 讀出目前結果表格的注單（排除最後的合計列） */
  async records(): Promise<BetRecord[]> {
    const rows = await this.page.locator('table tbody tr').all();
    const records: BetRecord[] = [];

    for (const row of rows) {
      const cells = await row.locator('td').allInnerTexts();
      // 合計列的第一格不是時間，直接略過
      if (cells.length < 11 || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/.test(cells[0].trim())) {
        continue;
      }
      const num = (v: string) => Number(v.replace(/,/g, ''));
      records.push({
        betTime: cells[0].trim().split('\n')[0].trim(),
        agentAccount: cells[1].trim(),
        memberAccount: cells[2].trim(),
        gameName: cells[3].trim(),
        bet: num(cells[4]),
        validBet: num(cells[5]),
        payout: num(cells[6]),
        winLose: num(cells[7]),
        orderNumber: cells[10].trim(),
      });
    }
    return records;
  }

  /**
   * 等到查得到「指定時間之後」的注單為止（注單寫入報表可能有幾秒延遲），
   * 回傳這段時間內的所有注單（新的在前）——一局可能觸發免費遊戲而產生多筆。
   *
   * @param memberAccount 會員帳號
   * @param vendorId 遊戲商編號
   * @param notBefore 只接受這個時間之後的注單（通常傳「按下轉動鈕之前」的時間）
   * @param attempts 重試次數，每次間隔 5 秒
   */
  async waitForBetsAfter(
    memberAccount: string,
    vendorId: number,
    notBefore: Date,
    attempts = 6,
  ): Promise<BetRecord[]> {
    let all: BetRecord[] = [];

    for (let i = 0; i < attempts; i++) {
      await this.searchToday(memberAccount, vendorId);
      all = await this.records();

      const fresh = all.filter(
        (r) => new Date(r.betTime.replace(' ', 'T')).getTime() >= notBefore.getTime(),
      );
      if (fresh.length > 0) return fresh;

      await this.page.waitForTimeout(5_000);
    }

    throw new Error(
      `SR12 查不到 ${memberAccount} 在 ${notBefore.toLocaleString('zh-TW')} 之後的注單` +
        `（已重試 ${attempts} 次，目前查到 ${all.length} 筆較早的注單）`,
    );
  }
}
