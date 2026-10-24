import { expect, type Page } from '@playwright/test';

/**
 * 前台「設定」頁（/setting）。目前只用來導覽到「修改手機號」等子功能。
 * selector 已對齊 dev 站台實際 DOM。
 */
export class FrontendSettingsPage {
  constructor(private readonly page: Page) {}

  async goto(): Promise<void> {
    await this.page.goto('/setting');
  }

  async openChangePhone(): Promise<void> {
    await this.page.getByText('修改手機號', { exact: true }).click();
    await expect(this.page).toHaveURL(/change-phone/i, { timeout: 10_000 });
  }
}
