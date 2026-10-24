import type { ProxySession } from './proxy-session';

/** 解碼輪數上限：足以攤平多重編碼，又不讓惡意長字串拖住請求 */
const MAX_DECODE_ROUNDS = 5;

/** 已停用的 apis 登入路徑（可跳過二次驗證直接發 token），target/tickets#4548 */
const DISABLED_AGENT_LOGIN_PATH = 'proxy/agent/api/login';

/** apis（agent/api）路徑前綴，經 `[...].ts` 帶 session token 轉發 */
const AGENT_API_PREFIX = 'proxy/agent/api/';

/** 乾淨字面路徑的單一路徑段：只允許英數、底線、連字號 */
const CANONICAL_SEGMENT = /^[\w-]+$/;

/** 守門決策：放行、要查 session 的 apis 請求、或直接擋下 */
export type ProxyGuardDecision =
  | { kind: 'agent-api' }
  | { kind: 'block'; reason: 'ambiguous-path' | 'disabled-login-path' }
  | { kind: 'pass' };

/**
 * 把請求路徑攤平成下游實際會命中的樣子，再拿來比對。
 *
 * h3 的 router param 不解碼，原始路徑會原封不動送到 apis；但 apis 前的 nginx
 * 會解碼 `%XX`、合併多斜線、處理 `.`／`..`，Node fetch 也會把 `\` 轉成 `/`。
 * 不先攤平的話，編碼、多斜線、大小寫、反斜線、點段都能繞過字串比對。
 * 大小寫一律轉小寫：寧可多擋，也不賭下游路由大小寫不敏感與否。
 *
 * 解碼逐段進行：整串 decodeURIComponent 只要遇到一個壞段（如 `%zz`）就整串
 * 放棄，攻擊者可用 `/%6Cogin/%zz/..` 讓 login 那段漏解碼，而下游 fetch 會吃掉
 * `..`、nginx 會解開 `%6C`，最後仍命中登入路由。逐段解碼讓壞段只影響自己。
 */
export function normalizeProxyPath(rawPath: string): string {
  let path = stripQueryAndFragment(rawPath).replaceAll('\\', '/');

  for (let round = 0; round < MAX_DECODE_ROUNDS; round++) {
    // 每輪重新切段：%2F 解開會多出斜線
    const decoded = path
      .split('/')
      .map((segment) => decodeSegment(segment))
      .join('/')
      .replaceAll('\\', '/');
    if (decoded === path) break;
    path = decoded;
  }

  const segments: string[] = [];
  for (const segment of path.split('/')) {
    if (segment === '' || segment === '.') continue;
    if (segment === '..') {
      segments.pop();
      continue;
    }
    segments.push(segment);
  }

  return segments.join('/').toLowerCase();
}

/** 去掉第一個 `?` 或 `#` 之後的內容，只留路徑；守門判斷與擋下紀錄共用同一個切法 */
export function stripQueryAndFragment(rawPath: string): string {
  const cut = rawPath.search(/[?#]/);
  return cut === -1 ? rawPath : rawPath.slice(0, cut);
}

/** 解碼單一路徑段；非法編碼（如 %zz）保留原樣，不讓例外變成 500 */
function decodeSegment(segment: string): string {
  try {
    return decodeURIComponent(segment);
  } catch {
    return segment;
  }
}

/**
 * apis 的 `agent/api/login` 不經二次驗證就發 token，前端從不呼叫它
 * （登入走 API_Agent 的 `api/login`），所以沒有正當呼叫者，一律擋。
 */
export function isDisabledAgentLoginPath(normalized: string): boolean {
  return normalized === DISABLED_AGENT_LOGIN_PATH;
}

/** 是否轉往 apis；這類請求會帶 session token，必須先確認已登入 */
export function isAgentApiPath(normalized: string): boolean {
  return normalized.startsWith(AGENT_API_PREFIX);
}

/**
 * 是否為不需要任何正規化的乾淨字面路徑：單一 `/` 開頭，每段非空且只含
 * `[A-Za-z0-9_-]`。排除了 `%` 編碼、`\`、`.`／`..` 點段、`//`、尾斜線與空白，
 * 這些正是 gateway 與下游（fetch、nginx、Laravel）解讀可能不一致的來源。
 * 前端所有 agent/api 路徑都符合，拒絕其他寫法不會誤擋正常請求。
 */
export function isCanonicalProxyPath(raw: string): boolean {
  if (!raw.startsWith('/')) return false;
  const segments = raw.slice(1).split('/');
  return segments.every((segment) => CANONICAL_SEGMENT.test(segment));
}

/**
 * 決定 `/proxy/*` 請求怎麼處理，middleware 只照結果回應。
 *
 * 打 apis 的請求只接受乾淨字面路徑，有歧義就擋：gateway 模擬下游正規化永遠
 * 對不齊解碼與點段的順序（例如 `login/%2F/../../login` 在這裡算成
 * `proxy/agent/login`，fetch 卻把未解碼的 `%2F` 當一段消掉、實際送出
 * `agent/api/login`），所以改成不猜。normalizeProxyPath 仍保留，作為已停用
 * 登入路徑的第二道網。
 *
 * 是否屬於 apis 同時看原始前綴：gateway.ts 依未解碼的 router param 是否以
 * `agent/api/` 開頭分流，多層編碼的 `..` 可以讓正規化結果跳出前綴，但原始
 * 路徑仍會被轉給 apis，兩邊都要算進來。
 */
export function decideProxyGuard(rawPath: string): ProxyGuardDecision {
  const raw = stripQueryAndFragment(rawPath);
  const rawNoLead = raw.replace(/^\/+/, '');
  const normalized = normalizeProxyPath(raw);

  const isAgentApi =
    rawNoLead.startsWith(AGENT_API_PREFIX) || isAgentApiPath(normalized);
  if (!isAgentApi) return { kind: 'pass' };

  if (isDisabledAgentLoginPath(normalized)) {
    return { kind: 'block', reason: 'disabled-login-path' };
  }

  if (!isCanonicalProxyPath(raw)) {
    return { kind: 'block', reason: 'ambiguous-path' };
  }

  return { kind: 'agent-api' };
}

/**
 * 登入流程完成（action=index，2FA 等多步驟暫存狀態不算）且 token 未過期。
 * gateway 各處共用同一判準，避免守門與 `/proxy/me` 對「已登入」認知不一致。
 */
export function isLoggedIn(data: ProxySession): boolean {
  return (
    data.action === 'index' &&
    Boolean(data.token) &&
    (data.expire ?? 0) > Date.now() / 1000
  );
}
