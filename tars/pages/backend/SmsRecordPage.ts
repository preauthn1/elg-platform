import { expect, type Page } from '@playwright/test';

/**
 * 後台簡訊記錄頁（sms/record.php），用來取得剛發送的註冊驗證碼。
 * selector 已對齊 dev 後台實際 DOM。
 *
 * 注意：
 *  - 查詢以「完整國際手機號」（國碼+本地號碼，例如 60xxxxxxxxx）過濾最準，
 *    因為一頁式註冊的 member 會顯示為假帳號（Registrants_xxx）。
 *  - frank 帳號的 Content 欄是「View info」按鈕，需點開 modal 才看得到驗證碼。
 */
export class SmsRecordPage {
  constructor(private readonly page: Page) {}

  private recordUrl(mobile: string): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    const d = new Date();
    const day = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    const params = new URLSearchParams({
      mobile,
      start_time: `${day} 00:00`,
      end_time: `${day} 23:59`,
      status: '1', // 只看發送成功
    });
    return `/sms/record.php?${params.toString()}`;
  }

  /**
   * 取得指定手機號最新一筆的 6 碼驗證碼。
   * 簡訊寫入可能略有延遲，內建數次輪詢重試。
   *
   * @param mobile 完整國際手機號（國碼+本地號碼，例如 601xxxxxxxx）
   * @param attempts 重試次數
   */
  async getLatestOtp(mobile: string, attempts = 6): Promise<string> {
    const firstRowViewInfo = this.page
      .locator('table.table tbody tr')
      .first()
      .getByRole('button', { name: 'View info' });

    for (let i = 0; i < attempts; i++) {
      await this.page.goto(this.recordUrl(mobile));

      if (await firstRowViewInfo.count()) {
        await firstRowViewInfo.click();
        const content = this.page.locator('#smsContentText');
        await expect(content).toBeVisible({ timeout: 10_000 });
        const text = (await content.textContent()) ?? '';
        const match = text.match(/(\d{6})/);
        if (match) return match[1];
      }

      // 尚未出現記錄或內容，稍候重試
      await this.page.waitForTimeout(2_000);
    }

    throw new Error(`後台簡訊記錄查無手機 ${mobile} 的驗證碼（已重試 ${attempts} 次）`);
  }
}
