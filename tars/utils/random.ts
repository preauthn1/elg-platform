/**
 * 隨機測試資料產生器：每次執行產生唯一、不衝突的帳號資料，
 * 用於「先隨機註冊一組帳號，再用它操作」的前台流程。
 *
 * 欄位規則對齊 dev 前台註冊頁（/signup）：
 *   - 玩家帳號：4~10 碼英數
 *   - 密碼：6~12 碼英數
 */

export interface RandomAccount {
  /** 玩家帳號，4~10 碼英數 */
  username: string;
  /** 密碼，6~12 碼英數 */
  password: [REDACTED];
  /** 真實姓名（需與銀行戶名相同；dev 測試用隨機中文名） */
  realName: string;
  /**
   * 本地手機號（不含國碼、不含前導 0）。
   * 站台預設國碼為馬來西亞（+60），故產生馬來西亞式行動號碼 1xxxxxxxx。
   * 後台簡訊記錄裡的完整號碼為「國碼 + 本地號碼」，例如 60 + 1xxxxxxxx。
   */
  phone: string;
}

function randomDigits(len: number): string {
  let s = '';
  for (let i = 0; i < len; i++) s += Math.floor(Math.random() * 10);
  return s;
}

function randomAlphaNum(len: number): string {
  const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
  let s = '';
  for (let i = 0; i < len; i++) s += chars[Math.floor(Math.random() * chars.length)];
  return s;
}

const SURNAMES = '陳林黃張李王吳劉蔡楊';
const GIVEN = '志明家豪俊傑淑芬美玲雅婷怡君';

function randomChineseName(): string {
  const pick = (s: string) => s[Math.floor(Math.random() * s.length)];
  return pick(SURNAMES) + pick(GIVEN) + pick(GIVEN);
}

/**
 * 產生一組唯一帳號。
 * username 控制在 4~10 碼英數：前綴 + 時間戳(base36) + 隨機碼，
 * 確保同一時間並行跑多支測試也不會撞帳號。
 *
 * @param prefix 帳號前綴（小寫英文），預設 qa。方便事後在後台辨識/清理。
 */
export function makeAccount(prefix = 'qa'): RandomAccount {
  // Date.now() 轉 base36 取末 4 碼（英數），加 2 碼隨機 → 前綴2 + 4 + 2 = 8 碼，落在 4~10 內
  const stamp = Date.now().toString(36).slice(-4);
  const username = `${prefix}${stamp}${randomAlphaNum(2)}`;
  return {
    username,
    // 純英數、含大小寫與數字，長度 10（落在 6~12 內）
    password: [REDACTED],
    realName: randomChineseName(),
    // 馬來西亞式本地行動號碼（不含前導 0）：1 + 8 碼數字
    phone: `1${randomDigits(8)}`,
  };
}
