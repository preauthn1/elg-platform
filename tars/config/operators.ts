/**
 * 營運商設定：從環境變數（.env）讀取每個營運商的前台/後台網址與帳密。
 *
 * 命名慣例（以 TPOWER 為例）：
 *   TPOWER_FRONTEND_URL        前台網址
 *   TPOWER_BACKEND_URL         後台網址
 *   TPOWER_BACKEND_URL_NEW     新後台網址（選填，過渡期並存；沿用同一組管理帳密。
 *                              測試預設仍用 BACKEND_URL（舊後台），特別指定才查新後台）
 *   TPOWER_ADMIN_USERNAME      後台管理帳號（admin 權限，後台測試的預設帳號）
 *   TPOWER_ADMIN_PASSWORD      後台管理密碼
 *   TPOWER_ADMIN_AGENT_USERNAME 後台總代帳號（選填，只給要驗總代視角/權限差異的測試用）
 *   TPOWER_ADMIN_AGENT_PASSWORD 同上密碼
 *   TPOWER_MEMBER_ACCOUNT      前台會員帳號（frontend-logged 登入用）
 *   TPOWER_MEMBER_PASSWORD     前台會員密碼
 *   TPOWER_GAME_MEMBER_ACCOUNT  前台「有餘額」的會員帳號（玩遊戲下注測試用）
 *   TPOWER_GAME_MEMBER_PASSWORD 同上密碼
 *   TPOWER_REFERRAL_CODE       前台註冊推廣碼
 *   TPOWER_PHONE_COUNTRY_CODE  前台註冊手機國碼
 *
 * 要新增營運商：在下方 OPERATOR_KEYS 加一個 key，並於 .env 補上對應變數即可。
 * 沒有設定任何 URL 的營運商會自動被略過，不會產生 Playwright project。
 */

/**
 * 🚫 本專案僅允許 dev 環境：QA 禁止對任何白標站（tpower / vw / wintoto…）
 * 或正式站執行測試。resolveOperatorSites() 會在 .env 出現非 DEV 的站台網址時
 * 直接報錯拒絕啟動——這是刻意的防護，不要繞過或加回其他 key。
 */
export const OPERATOR_KEYS = ['dev'] as const;

export type OperatorKey = (typeof OPERATOR_KEYS)[number];
export type Site = 'frontend' | 'backend';

export interface Credentials {
  username: string;
  password: [REDACTED];
}

export interface OperatorSite {
  operator: OperatorKey;
  site: Site;
  /** Playwright project 名稱，例如 tpower-frontend */
  projectName: string;
  baseURL: string;
  credentials?: Credentials;
  /**
   * 前台「有餘額」的會員帳密（僅前台適用）。
   * 給需要真的下注的測試用（玩遊戲會扣點），與 credentials 的固定會員分開，
   * 免得下注把 logged 分類共用的帳號餘額吃掉。未設定時相關測試自動 skip。
   */
  gameCredentials?: Credentials;
  /** 前台註冊用的推廣碼（可於 .env 自行設定），僅前台適用 */
  referralCode?: string;
  /** 前台註冊手機國碼（不含 +），預設 60（馬來西亞），僅前台適用 */
  phoneCountryCode?: string;
}

function env(key: string): string | undefined {
  const v = process.env[key];
  return v && v.trim() !== '' ? v.trim() : undefined;
}

function credentials(user?: string, pass?: string): Credentials | undefined {
  if (user && pass) return { username: user, password: [REDACTED] };
  return undefined;
}

/**
 * 🚫 允許的測試主機：dev 環境的 example.invalid、example.invalid（皆含子網域）與本機 .local。
 * 其他任何網域（白標站、正式站）一律拒絕啟動——防止 dev 名義下填入真站網址。
 */
const ALLOWED_HOST_PATTERNS = [/(^|\.)tm99s\.com$/, /(^|\.)gff88\.com$/, /\.local$/];
function assertAllowedUrl(key: string, url: string | undefined): void {
  if (!url) return;

  let hostname: string;
  try {
    hostname = new URL(url).hostname;
  } catch {
    throw new Error(`.env 的 ${key} 不是有效網址：${url}`);
  }

  if (!ALLOWED_HOST_PATTERNS.some((pattern) => pattern.test(hostname))) {
    throw new Error(
      `本專案僅允許 example.invalid、example.invalid 與 .local 的測試站，.env 的 ${key} 指向不被允許的主機：${hostname}。`,
    );
  }
}

/**
 * 展開所有「有設定 URL」的營運商 × 站台組合。
 * config 會用這份清單動態產生 Playwright projects。
 */
export function resolveOperatorSites(): OperatorSite[] {
  // 防護：.env 設定了 dev 以外的站台網址 → 直接拒絕啟動（本專案禁止測白標/正式站）
  const forbidden = Object.keys(process.env).filter(
    (key) => /^[A-Z0-9]+_(FRONTEND|BACKEND)_URL$/.test(key) && !key.startsWith('DEV_') && env(key),
  );
  if (forbidden.length > 0) {
    throw new Error(
      `本專案僅允許 dev 環境，禁止設定其他站台的網址：${forbidden.join('、')}。請從 .env 移除這些設定。`,
    );
  }

  const sites: OperatorSite[] = [];

  for (const operator of OPERATOR_KEYS) {
    const upper = operator.toUpperCase();

    assertAllowedUrl(`${upper}_FRONTEND_URL`, env(`${upper}_FRONTEND_URL`));
    assertAllowedUrl(`${upper}_BACKEND_URL`, env(`${upper}_BACKEND_URL`));

    const frontendUrl = env(`${upper}_FRONTEND_URL`);
    if (frontendUrl) {
      sites.push({
        operator,
        site: 'frontend',
        projectName: `${operator}-frontend`,
        baseURL: frontendUrl,
        // 前台會員帳密：frontend-logged 用它登入（frontend-setup 寫入 storageState）
        credentials: credentials(
          env(`${upper}_MEMBER_ACCOUNT`),
          env(`${upper}_MEMBER_PASSWORD`),
        ),
        // 有餘額的會員帳密：下注類測試用（沒設定就讓那些測試自己 skip）
        gameCredentials: credentials(
          env(`${upper}_GAME_MEMBER_ACCOUNT`),
          env(`${upper}_GAME_MEMBER_PASSWORD`),
        ),
        referralCode: env(`${upper}_REFERRAL_CODE`),
        phoneCountryCode: env(`${upper}_PHONE_COUNTRY_CODE`),
      });
    }

    const backendUrl = env(`${upper}_BACKEND_URL`);
    if (backendUrl) {
      sites.push({
        operator,
        site: 'backend',
        projectName: `${operator}-backend`,
        baseURL: backendUrl,
        credentials: credentials(
          env(`${upper}_ADMIN_USERNAME`),
          env(`${upper}_ADMIN_PASSWORD`),
        ),
      });
    }

    // 新後台（過渡期並存）：測試預設仍用 ${operator}-backend（舊後台）；
    // 需要指定用新後台的測試，向 getOperatorSite 查 `${operator}-backend-new`。
    // 沿用同一組管理帳密（同 dev 環境，只是後台網址不同）。
    assertAllowedUrl(`${upper}_BACKEND_URL_NEW`, env(`${upper}_BACKEND_URL_NEW`));
    const backendUrlNew = env(`${upper}_BACKEND_URL_NEW`);
    if (backendUrlNew) {
      sites.push({
        operator,
        site: 'backend',
        projectName: `${operator}-backend-new`,
        baseURL: backendUrlNew,
        credentials: credentials(
          env(`${upper}_ADMIN_USERNAME`),
          env(`${upper}_ADMIN_PASSWORD`),
        ),
      });
    }

    // 總代權限帳密（選填）：<營運商>_ADMIN_AGENT_USERNAME / _PASSWORD。
    // 用途是驗「總代視角」與「權限差異」——總代看得到的東西比 admin 少
    // （實測：新後台「專屬優惠 > 活動管理」的「建立活動」「編輯」按鈕，
    //  admin 有、總代沒有，前端直接 v-if 不渲染），而且兩者可能隸屬不同營運商、
    //  看到的資料集不同。
    // 後台測試預設一律用 admin（`${operator}-backend` / `-backend-new`）；
    // 只有要驗總代視角的測試才向 getOperatorSite 查 `${operator}-backend-agent`
    // 或 `${operator}-backend-new-agent`。沒設定就不產生，相關測試自己 skip。
    const agentCredentials = credentials(
      env(`${upper}_ADMIN_AGENT_USERNAME`),
      env(`${upper}_ADMIN_AGENT_PASSWORD`),
    );
    if (agentCredentials) {
      if (backendUrl) {
        sites.push({
          operator,
          site: 'backend',
          projectName: `${operator}-backend-agent`,
          baseURL: backendUrl,
          credentials: agentCredentials,
        });
      }
      if (backendUrlNew) {
        sites.push({
          operator,
          site: 'backend',
          projectName: `${operator}-backend-new-agent`,
          baseURL: backendUrlNew,
          credentials: agentCredentials,
        });
      }
    }
  }

  return sites;
}

/** 依 project 名稱反查該站台設定（測試內取帳密用） */
export function getOperatorSite(projectName: string): OperatorSite | undefined {
  return resolveOperatorSites().find((s) => s.projectName === projectName);
}

/**
 * .env 欄位缺漏檢查：哪些沒填、會害哪些測試被跳過。
 *
 * 為什麼要這個：帳密沒填時測試會自動 skip，但 VS Code 測試面板只顯示
 * 「未報告任何輸出」，QA 看不出是「環境沒設定」還是「測試壞了」。
 * 這裡在每次執行前把缺漏印成一份清單，讓原因一眼可見。
 */
interface EnvRequirement {
  /** .env 的欄位名（不含營運商前綴） */
  keys: string[];
  /** 沒填的話哪些測試會被跳過 */
  affects: string;
}

const ENV_REQUIREMENTS: EnvRequirement[] = [
  { keys: ['FRONTEND_URL'], affects: '所有前台測試' },
  { keys: ['BACKEND_URL'], affects: '所有後台測試' },
  {
    keys: ['ADMIN_USERNAME', 'ADMIN_PASSWORD'],
    affects: '後台登入測試、註冊測試（要登入後台查簡訊驗證碼）',
  },
  {
    keys: ['MEMBER_ACCOUNT', 'MEMBER_PASSWORD'],
    affects: 'tests/logged/ 需要會員登入態的前台測試（例：會員中心）',
  },
  {
    keys: ['GAME_MEMBER_ACCOUNT', 'GAME_MEMBER_PASSWORD'],
    affects: 'tests/dangerous/game_bet_*.spec.ts 下注測試（需要有餘額的會員）',
  },
  {
    keys: ['REFERRAL_CODE'],
    affects: 'tests/dangerous/member_register.spec.ts 註冊測試（需要有效推廣碼）',
  },
];

/** 印出 .env 缺漏清單；沒有缺漏就安靜不出聲 */
export function warnMissingEnv(): void {
  // 設定檔在主行程與每個 worker 都會被載入一次，靠環境變數確保整次執行只印一次
  if (process.env.TARS_ENV_WARNED) return;
  process.env.TARS_ENV_WARNED = '1';

  const lines: string[] = [];

  for (const operator of OPERATOR_KEYS) {
    const upper = operator.toUpperCase();

    for (const { keys, affects } of ENV_REQUIREMENTS) {
      const missing = keys.filter((k) => !env(`${upper}_${k}`));
      if (missing.length === 0) continue;
      lines.push(`   ✗ ${missing.map((k) => `${upper}_${k}`).join('、')}`);
      lines.push(`     └ 會跳過：${affects}`);
    }
  }

  if (lines.length === 0) return;

  console.warn(
    '\n⚠️  .env 有欄位沒填，以下測試這次會「自動跳過」（不是測試壞掉）：\n\n' +
      lines.join('\n') +
      '\n\n   補資料：編輯專案根目錄的 .env，欄位說明見 .env.example。\n',
  );
}
