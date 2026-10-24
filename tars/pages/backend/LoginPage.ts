import { expect, type Page } from '@playwright/test';
import type { Credentials } from '../../config/operators';

/**
 * 後台管理登入頁（login.php）。selector 已對齊 dev 代理後台實際 DOM。
 */
export class BackendLoginPage {
  constructor(private readonly page: Page) {}

  private readonly loginPath = '/login.php';

  async goto(): Promise<void> {
    await this.page.goto(this.loginPath);
  }

  async login(creds: Credentials): Promise<void> {
    await this.goto();
    await this.page.getByRole('textbox', { name: 'Username' }).fill(creds.username);
    await this.page.getByRole('textbox', { name: 'Password' }).fill(creds.password);
    await this.page.getByRole('button', { name: 'Login' }).click();
    // 登入成功會導向 index.php（離開 login 頁）
    await expect(this.page).toHaveURL(/index\.php/, { timeout: 15_000 });
  }

  /**
   * 嘗試登入但預期失敗（帳密錯誤、帳號被停用…等）。
   * 後台以瀏覽器原生 alert 顯示錯誤訊息（例如「Incorrect username or password」，
   * 帳密錯誤與帳號被停用顯示同一句，不會洩漏帳號狀態），需監聽 dialog 事件才能取得內容並讓它不擋住後續操作。
   * 回傳 alert 的訊息內容，登入失敗後頁面會留在 login.php。
   */
  async loginExpectFailure(creds: Credentials): Promise<string> {
    await this.goto();
    await this.page.getByRole('textbox', { name: 'Username' }).fill(creds.username);
    await this.page.getByRole('textbox', { name: 'Password' }).fill(creds.password);

    const dialogMessage = new Promise<string>((resolve) => {
      this.page.once('dialog', async (dialog) => {
        resolve(dialog.message());
        await dialog.accept();
      });
    });

    await this.page.getByRole('button', { name: 'Login' }).click();
    return dialogMessage;
  }
}
