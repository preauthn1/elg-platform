import { expect, type Page } from '@playwright/test';

/**
 * 前台「修改手機號」頁（/change-phone）。分兩步：
 *   1. 對目前手機號發驗證碼、輸入後進「下一步」
 *   2. 填新手機號、對新手機號發驗證碼、輸入後「完成」
 * 兩步的驗證碼輸入框、發送/確認按鈕重用同一組 selector（畫面同時間只會出現一組）。
 * selector 已對齊 dev 站台實際 DOM。
 */
export class FrontendChangePhonePage {
  constructor(private readonly page: Page) {}

  async goto(): Promise<void> {
    await this.page.goto('/change-phone');
  }

  /**
   * 讀取畫面顯示的「目前手機號碼」，回傳去掉「+」的完整號碼（國碼+本地號），
   * 供後台簡訊記錄查詢用（SmsRecordPage 的 mobile 參數格式）。
   */
  async readCurrentMobile(): Promise<string> {
    const row = this.page.getByText('目前手機號碼').locator('..');
    const text = (await row.innerText()).replace(/\s+/g, '');
    const matched = text.match(/\+(\d+)/);
    if (!matched) {
      throw new Error(`讀不到目前手機號碼，畫面文字為「${text}」`);
    }
    return matched[1];
  }

  /** 對目前手機號發送驗證碼（送出後會跳「確認手機號碼」彈窗，這裡一併按下確認） */
  async sendOtpToCurrentPhone(): Promise<void> {
    await this.page.getByRole('button', { name: '發送', exact: true }).click();
    await this.page.getByRole('button', { name: '確認', exact: true }).click();
  }

  /** 輸入目前手機號的驗證碼並進入下一步（填新手機號） */
  async submitCurrentPhoneOtp(otp: string): Promise<void> {
    await this.page.getByRole('textbox', { name: '請輸入驗證碼' }).fill(otp);
    await this.page.getByRole('button', { name: '下一步' }).click();
  }

  /** 填入新手機號（本地號碼，不含國碼、不含前導 0）並發送驗證碼 */
  async fillNewPhoneAndSendOtp(localNumber: string): Promise<void> {
    await this.page.getByRole('spinbutton', { name: '新手機號碼' }).fill(localNumber);
    await this.page.getByRole('button', { name: '發送', exact: true }).click();
    await this.page.getByRole('button', { name: '確認', exact: true }).click();
  }

  /** 輸入新手機號的驗證碼並完成修改 */
  async completeWithOtp(otp: string): Promise<void> {
    await this.page.getByRole('textbox', { name: '請輸入驗證碼' }).fill(otp);
    await this.page.getByRole('button', { name: /^完\s*成$/ }).click();
    await expect(this.page.getByText('成功', { exact: true }), '修改完成應顯示成功訊息').toBeVisible({
      timeout: 10_000,
    });
  }
}
