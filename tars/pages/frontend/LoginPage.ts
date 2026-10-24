import { expect, type Page } from '@playwright/test';
import type { Credentials } from '../../config/operators';

/**
 * 前台會員登入頁（/login）。selector 已對齊 dev 站台實際 DOM。
 */
export class FrontendLoginPage {
  constructor(private readonly page: Page) {}

  private readonly loginPath = '/login';

  async goto(): Promise<void> {
    await this.page.goto(this.loginPath);
  }

  async login(creds: Credentials): Promise<void> {
    await this.goto();
    // 帳號欄提示文字曾由「請輸入玩家帳號」改成「玩家帳號 / 手機號碼」，用關鍵字比對避免再被改版打壞
    await this.page.getByRole('textbox', { name: /玩家帳號/ }).fill(creds.username);
    await this.page.getByRole('textbox', { name: /密碼/ }).fill(creds.password);
    await this.page.getByRole('button', { name: '登入', exact: true }).click();
    // 登入成功會離開登入頁
    await expect(this.page).not.toHaveURL(/login/i, { timeout: 15_000 });
  }
}
