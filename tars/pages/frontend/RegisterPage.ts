import { expect, test, type Page } from '@playwright/test';
import type { RandomAccount } from '../../utils/random';

export interface RegisterOptions {
  /** 推廣碼（通常來自 .env，可自行設定） */
  referralCode: string;
  /**
   * 國碼（不含 +），用來組出完整國際號碼交給 getOtp 查詢。
   * 預設 '60'（馬來西亞，站台第二步的預設國碼）。
   */
  countryCode?: string;
  /**
   * 取得簡訊驗證碼的方式（可插拔）。收到完整國際號碼，回傳 6 碼驗證碼。
   * dev 沒有固定測試碼，一律從後台簡訊記錄查詢。
   */
  getOtp: (fullMobile: string) => Promise<string>;
}

/**
 * 前台會員註冊頁（/signup）。selector 已對齊 dev 站台實際 DOM
 * （欄位無 name 屬性，改用 placeholder / role 定位）。
 *
 * 兩步驟表單：
 *   第一步 fillStep1：玩家帳號、真實姓名、密碼、確認密碼、推廣碼、同意條款 → 下一步
 *   第二步 requestSms + completeWithOtp：手機號碼、發送 → 填驗證碼 → 註冊
 */
export class FrontendRegisterPage {
  constructor(private readonly page: Page) {}

  private readonly registerPath = '/signup';

  async goto(): Promise<void> {
    await this.page.goto(this.registerPath);
  }

  /**
   * 完整註冊流程：填表 → 發送簡訊 → 取碼 → 送出。
   * 驗證碼由 options.getOtp（動態查詢，例如查後台簡訊記錄）取得。
   * 各階段以 test.step 標中文軌跡——本流程在 fixture 內執行，
   * 報告/UI 模式會收在「Before Hooks」，沒有 step 標籤 QA 看不出後台操作。
   */
  async register(account: RandomAccount, options: RegisterOptions): Promise<void> {
    const countryCode = options.countryCode ?? '60';

    await test.step('[前台] 開啟註冊頁並填寫基本資料（帳號/姓名/密碼/推廣碼）', async () => {
      await this.goto();
      await this.fillStep1(account, options.referralCode);
    });

    await test.step('[前台] 填入手機號碼並發送簡訊', () => this.requestSms(account));

    const fullMobile = `${countryCode}${account.phone}`;
    const code = await test.step('[後台] 登入後台，從簡訊記錄查詢驗證碼', () =>
      options.getOtp(fullMobile));

    await test.step('[前台] 填入驗證碼並送出註冊', () => this.completeWithOtp(code));
  }

  /** 第一步：基本資料 + 推廣碼 + 同意條款 → 下一步 */
  async fillStep1(account: RandomAccount, referralCode: string): Promise<void> {
    await this.page.getByPlaceholder('4~10碼英文數字').fill(account.username);
    await this.page.getByPlaceholder('真實姓名').fill(account.realName);
    await this.page.getByPlaceholder('請輸入推廣碼').fill(referralCode);

    // 密碼與確認密碼 placeholder 相同（皆「6~12碼英文數字」），靠出現順序區分
    const passwordFields = this.page.getByPlaceholder('6~12碼英文數字');
    await passwordFields.nth(0).fill(account.password);
    await passwordFields.nth(1).fill(account.password);

    // 勾選「我已閱讀並同意」後，「下一步」才由 disabled 變為可按
    await this.page.getByRole('checkbox', { name: '我已閱讀並同意' }).check();

    const next = this.page.getByRole('button', { name: '下一步' });
    await expect(next).toBeEnabled();
    await next.click();
  }

  /**
   * 第二步（前半）：填手機號並觸發發送簡訊。
   * ⚠️ 國碼預設沿用站台預設（馬來西亞 +60）。若要測其他國碼，需先點國碼按鈕切換，
   *    並注意提示：台灣/馬來/泰國手機開頭「不需填 0」。account.phone 已是不含前導 0 的本地號。
   */
  async requestSms(account: RandomAccount): Promise<void> {
    await this.page.getByPlaceholder('手機號碼').fill(account.phone);
    await this.page.getByRole('button', { name: '發送' }).click();
    // 按發送後會跳出「確認手機號碼」對話框，需點「確認」才真的送出簡訊
    await this.page.getByRole('button', { name: '確認', exact: true }).click();
  }

  /** 第二步（後半）：填入驗證碼並送出註冊 */
  async completeWithOtp(code: string): Promise<void> {
    await this.page.getByPlaceholder('請輸入驗證碼').fill(code);

    const submit = this.page.getByRole('button', { name: '註冊' });
    await expect(submit).toBeEnabled();
    await submit.click();

    await this.expectRegistered();
  }

  /** 註冊成功斷言：離開註冊頁即視為成功（可依實際登入後標記再強化） */
  async expectRegistered(): Promise<void> {
    await expect(this.page).not.toHaveURL(/signup/i, { timeout: 15_000 });
  }
}
