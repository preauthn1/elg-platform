import { expect, type Page } from '@playwright/test';
import { dayRange } from '../../utils/dateRange';

/** SR14 現金紀錄表的一列（會員點數變動） */
export interface CashRecord {
  date: string;
  agentAccount: string;
  memberAccount: string;
  /** 原始點數 */
  originalPoints: number;
  /** 變更點數（負數＝扣點） */
  changePoints: number;
  /** 當下點數 */
  currentPoints: number;
  /** 類型，例如 Single wallet Game Bet／Platform to vendor */
  type: string;
  /** 狀態，例如 Gaming／Success */
  status: string;
  /** 備註，單一錢包押注會帶遊戲商，例如 (236) Pxplay SW */
  memo: string;
}

/** SR14「Single wallet Game Bet」列點開 Detail 的一筆注單 */
export interface CashRecordBetDetail {
  /** 押注時間（報表顯示的字串，例如 2026-08-26 11:31:13） */
  betTime: string;
  orderNumber: string;
  bet: number;
  validBet: number;
  payout: number;
  winLose: number;
}

/**
 * 後台「統計報表查詢 > SR14 現金紀錄表」（report/sr14.php）。
 * 查詢條件用網址帶入即會直接出結果（不必再按查詢）。
 *
 * 單一錢包的押注在這張表**不是一注一列**：同一個遊戲場次合併成一列「Single wallet Game Bet」，
 * 日期記場次第一注那天、狀態 Gaming 直到場次結束、變更點數是場次累計淨額（可能是 0 或正數）。
 * 所以要看某一注有沒有記進去，要往前多查幾天、找該遊戲商最新的那列，再點 Detail 看明細。
 */
export class CashRecordPage {
  constructor(private readonly page: Page) {}

  /** 查某會員在某段日期的現金（點數）變動紀錄 */
  async search(memberAccount: string, from: Date, to: Date): Promise<void> {
    const params = new URLSearchParams({
      member_username: memberAccount,
      start_time: dayRange(from).start,
      end_time: dayRange(to).end,
    });

    await this.page.goto(`/report/sr14.php?${params.toString()}`);
    await expect(this.page.locator('table').first(), '應出現現金紀錄查詢結果表格').toBeVisible({
      timeout: 20_000,
    });
  }

  /** 查某會員「今天」的現金（點數）變動紀錄 */
  async searchToday(memberAccount: string): Promise<void> {
    const today = new Date();
    await this.search(memberAccount, today, today);
  }

  /** 查某會員「最近 N 天（含今天）」的現金（點數）變動紀錄 */
  async searchRecentDays(memberAccount: string, days = 30): Promise<void> {
    const today = new Date();
    const from = new Date(today.getTime() - (days - 1) * 24 * 60 * 60 * 1000);
    await this.search(memberAccount, from, today);
  }

  /** 讀出目前結果表格的每一列點數變動（新的在前） */
  async records(): Promise<CashRecord[]> {
    const rows = await this.page.locator('table tbody tr').all();
    const records: CashRecord[] = [];

    for (const row of rows) {
      const cells = await row.locator('td').allInnerTexts();
      if (cells.length < 9 || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/.test(cells[0].trim())) {
        continue;
      }
      const num = (v: string) => Number(v.replace(/,/g, ''));
      records.push({
        date: cells[0].trim(),
        agentAccount: cells[1].trim(),
        memberAccount: cells[2].trim(),
        originalPoints: num(cells[3]),
        changePoints: num(cells[4]),
        currentPoints: num(cells[5]),
        type: cells[6].trim(),
        status: cells[7].trim(),
        memo: cells[8].trim(),
      });
    }
    return records;
  }

  /** 點開某一列「Single wallet Game Bet」的 Detail，讀出這個場次的每一筆注單（新的在前） */
  async betDetails(record: CashRecord): Promise<CashRecordBetDetail[]> {
    const row = this.page
      .locator('table tbody tr', { hasText: record.date })
      .filter({ hasText: record.memo })
      .first();
    await row.getByRole('link', { name: 'Detail' }).click();

    const modal = this.page.locator('#bet_detail');
    await expect(modal, '應彈出注單明細視窗').toBeVisible({ timeout: 15_000 });
    // 明細是點開後才向後端抓，等第一筆注單出現
    await expect(modal.locator('tbody tr').first(), '明細視窗應載入注單').toBeVisible({
      timeout: 15_000,
    });

    const details: CashRecordBetDetail[] = [];
    for (const tr of await modal.locator('tbody tr').all()) {
      const cells = await tr.locator('td').allInnerTexts();
      // 小計／合計列的第一格不是時間，直接略過
      if (cells.length < 9 || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/.test(cells[0].trim())) {
        continue;
      }
      const num = (v: string) => Number(v.replace(/,/g, ''));
      details.push({
        betTime: cells[0].trim(),
        orderNumber: cells[1].trim(),
        bet: num(cells[2]),
        validBet: num(cells[4]),
        payout: num(cells[5]),
        winLose: num(cells[6]),
      });
    }

    await this.page.keyboard.press('Escape');
    return details;
  }

  /**
   * 等到某遊戲商的單一錢包場次列裡查得到「指定時間之後」的注單為止（報表寫入可能有幾秒延遲）。
   * 回傳那一列與這段時間內的注單（新的在前）。
   *
   * @param memberAccount 會員帳號
   * @param vendorId 遊戲商編號（備註會帶 (編號) 名稱，例如 (236) Pxplay SW）
   * @param notBefore 只接受這個時間之後的注單（通常傳「按下轉動鈕之前」的時間）
   * @param attempts 重試次數，每次間隔 5 秒
   */
  async waitForVendorBetsAfter(
    memberAccount: string,
    vendorId: number,
    notBefore: Date,
    attempts = 6,
  ): Promise<{ row: CashRecord; bets: CashRecordBetDetail[] }> {
    let lastRow: CashRecord | undefined;

    for (let i = 0; i < attempts; i++) {
      await this.searchRecentDays(memberAccount);
      const rows = await this.records();
      // 列表新的在前，第一筆符合的就是這家最新的場次
      lastRow = rows.find(
        (r) => /single wallet game bet/i.test(r.type) && r.memo.includes(`(${vendorId})`),
      );

      if (lastRow) {
        const bets = (await this.betDetails(lastRow)).filter(
          (b) => new Date(b.betTime.replace(' ', 'T')).getTime() >= notBefore.getTime(),
        );
        if (bets.length > 0) return { row: lastRow, bets };
      }

      await this.page.waitForTimeout(5_000);
    }

    throw new Error(
      `SR14 查不到 ${memberAccount} 在遊戲商 (${vendorId}) ${notBefore.toLocaleString('zh-TW')} 之後的注單` +
        `（已重試 ${attempts} 次，${lastRow ? `最新場次列是 ${lastRow.date}` : '找不到這家的單一錢包押注列'}）`,
    );
  }
}
