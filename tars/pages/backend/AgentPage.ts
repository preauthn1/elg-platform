import { expect, type Page } from '@playwright/test';

/**
 * 後台「營運商管理」的代理頁面：
 *   - 建立營運商（新增代理）：/agent/edit.php
 *   - 代理帳號管理（列表查詢）：/agent/index.php
 *
 * selector 已對齊 dev 代理後台實際 DOM。新增代理的「上層代理（Previous Agent）」
 * 預設即為目前登入的管理帳號，不需額外選擇。
 */
export class BackendAgentPage {
  constructor(private readonly page: Page) {}

  /** 前往「建立營運商（新增代理）」頁 */
  async gotoCreate(): Promise<void> {
    await this.page.goto('/agent/edit.php');
    await expect(this.page.getByRole('button', { name: 'Save' })).toBeVisible();
  }

  /**
   * 建立代理帳號（上層代理＝目前登入者，頁面預設）。
   * 只填必填欄位（帳號、密碼、確認密碼）與分成百分比，其餘沿用頁面預設值。
   * 送出後等待儲存請求成功回應才返回，避免太早離開頁面。
   */
  async createAgent(username: string, password: [REDACTED] Promise<void> {
    await this.page.getByRole('textbox', { name: 'Account', exact: true }).fill(username);
    await this.page.getByRole('textbox', { name: 'Password', exact: true }).fill(password);
    await this.page.getByRole('textbox', { name: 'Confirm Password' }).fill(password);
    // 分成百分比：placeholder 顯示 18 但實際值為空，明確填入與預設相同的 18
    await this.page.getByPlaceholder('18').fill('18');

    const saved = this.page.waitForResponse(
      (resp) =>
        resp.request().method() === 'POST' &&
        (resp.request().postData() ?? '').includes(username),
      { timeout: 15_000 },
    );
    await this.page.getByRole('button', { name: 'Save' }).click();
    expect((await saved).ok(), '建立代理的儲存請求應成功').toBe(true);
    // 儲存成功後頁面會自行跳轉回代理列表，等跳轉完成再繼續
    await this.page.waitForURL(/agent\/index\.php/, { timeout: 15_000 });
  }

  /** 前往「代理帳號管理」列表，以帳號模糊查詢 */
  async searchAgent(username: string): Promise<void> {
    await this.page.goto('/agent/index.php');
    await this.page.locator('input[name="username"]').fill(username);
    await this.page.getByRole('button', { name: 'Search' }).click();
  }

  /** 列表中包含該帳號的資料列 */
  agentRow(username: string) {
    return this.page.getByRole('row').filter({ hasText: username });
  }

  /** 展開該列的操作下拉選單 */
  async openRowActions(username: string): Promise<void> {
    await this.agentRow(username).locator('button.dropdown-toggle').click();
  }

  /**
   * 下拉選單中的「限制登入 / 解除限制登入」動作項。
   * 依該代理目前的 sys_lock 狀態，同一時間只會出現其中一種；
   * 需登入帳號持有 agent_sys_lock 權限才會顯示。
   */
  sysLockAction(username: string, kind: 'restrict' | 'remove') {
    const label = kind === 'restrict' ? 'Restrict login' : 'Remove login restriction';

    return this.agentRow(username).locator('a.agent_toggle_sys_lock', { hasText: label });
  }

  /** 點擊限制/解除限制，於彈窗填入備註並送出，等待成功提示後頁面重載 */
  async toggleSysLock(username: string, kind: 'restrict' | 'remove', remark: string): Promise<void> {
    await this.openRowActions(username);
    await this.sysLockAction(username, kind).click();

    const dialog = this.page.locator('.swal2-popup');
    await dialog.locator('textarea').fill(remark);
    await dialog.getByRole('button', { name: 'Send out' }).click();

    // 成功提示後按確認會觸發頁面重載（查詢條件在網址上，重載後列表仍是同一筆）
    await expect(this.page.locator('.swal2-popup')).toContainText('Success');
    await this.page.locator('.swal2-confirm').click();
    await this.page.waitForLoadState('domcontentloaded');
  }

  /**
   * 下拉選單中的「停用 / 啟用」帳號狀態動作項。
   * 與上面的 sysLockAction（限制登入）是不同機制：停用後帳號完全無法登入，
   * 限制登入是另一種鎖定；同一時間只會出現其中一種（依目前 status）。
   */
  statusAction(username: string, kind: 'disable' | 'enable') {
    const label = kind === 'disable' ? 'Disable' : 'Enable';

    return this.agentRow(username).locator('a.agent_toggle_status', { hasText: label });
  }

  /** 點擊停用/啟用，於彈窗填入備註並送出，等待成功提示後頁面重載 */
  async toggleStatus(username: string, kind: 'disable' | 'enable', remark: string): Promise<void> {
    await this.openRowActions(username);
    await this.statusAction(username, kind).click();

    const dialog = this.page.locator('.swal2-popup');
    await dialog.locator('textarea').fill(remark);
    await dialog.getByRole('button', { name: 'Send out' }).click();

    // 成功提示後按確認會觸發頁面重載（查詢條件在網址上，重載後列表仍是同一筆）
    await expect(this.page.locator('.swal2-popup')).toContainText('Success');
    await this.page.locator('.swal2-confirm').click();
    await this.page.waitForLoadState('domcontentloaded');
  }

  /** 開啟該代理的「操作紀錄」（狀態變更紀錄）彈窗 */
  async openStatusLog(username: string): Promise<void> {
    await this.openRowActions(username);
    await this.agentRow(username).locator('a.open_status_log').click();
    await expect(this.page.locator('#status_log_list')).toBeVisible();
  }
}
