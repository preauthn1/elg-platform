#!/usr/bin/env node
// 跨平台呼叫 npx 啟動 @zereight/mcp-gitlab
// macOS/Linux 用 npx；Windows 用 npx.cmd
const { spawn } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');

// pin 版本：避免上游 latest 漂移導致全團隊同時壞掉、難重現。
// 升級＝改這一行 → MR 標 breaking、通知 #ai-skills、手動測 /mcp（見 CONTRIBUTING §10）。
const PKG = '@zereight/mcp-gitlab@2.1.18';

const isWindows = process.platform === 'win32';
const npxBin = isWindows ? 'npx.cmd' : 'npx';

// 預設內網 GitLab API URL；可由環境變數 GITLAB_API_URL 覆蓋。
// 放這裡（而非 .mcp.json manifest）讓部署專屬位址可覆蓋、manifest 不綁死單一環境。
const DEFAULT_GITLAB_API_URL = 'http://example.invalid:1688/api/v4';

// ─────────────────────────────────────────────────────────────
// 本地 secrets 檔讀取（per-key 採用、fail-closed；設計概覽見市集 docs/internal/design-history/marketplace-initial-design.md）
// 讀取邏輯 inline 於此 wrapper（不抽共用模組，YAGNI）；以純函式匯出供 node:test。
// ─────────────────────────────────────────────────────────────

/** fail-closed 例外：解析/權限/壞值時拋出，由 main() 轉非零 exit、不啟動、不退回繼承 env。 */
class SecretsError extends Error {}

/** 跨平台解析 secrets 檔路徑：$GFF_SECRETS_FILE 優先，否則 homedir/.gff/secrets.env。 */
function secretsFilePath(env, homedir) {
  return env.GFF_SECRETS_FILE || path.join(homedir, '.gff', 'secrets.env');
}

/**
 * POSIX 權限/擁有權純斷言。違反任一即不安全（呼叫端 fail-closed）。
 * 順序：拒 symlink → 拒非 regular file → owner 必須==當前 uid → mode 不得有 group/other 任何位。
 */
function auditStat(st, currentUid) {
  if (typeof st.isSymbolicLink === 'function' && st.isSymbolicLink()) {
    return { ok: false, reason: 'secrets 檔不可為 symlink' };
  }
  if (!st.isFile()) {
    return { ok: false, reason: 'secrets 檔必須為一般檔案' };
  }
  if (typeof st.uid === 'number' && currentUid >= 0 && st.uid !== currentUid) {
    return { ok: false, reason: 'secrets 檔擁有者必須為當前使用者' };
  }
  if ((st.mode & 0o077) !== 0) {
    return { ok: false, reason: 'secrets 檔權限過寬，請 chmod 600' };
  }
  return { ok: true };
}

/** 解析 .env 式內容為物件。# 開頭與空行略過；值可選去單/雙引號包覆；值含 = 不截斷。 */
function parseEnvFile(content) {
  const out = {};
  for (const rawLine of content.split(/\r?\n/)) {
    const line = rawLine.trim();
    if (line === '' || line.startsWith('#')) continue;
    const eq = line.indexOf('=');
    if (eq === -1) throw new SecretsError('secrets 檔格式錯誤：有非註解行缺少 "="');
    const key = line.slice(0, eq).trim();
    if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(key)) {
      throw new SecretsError('secrets 檔格式錯誤：非法鍵名');
    }
    let value = line.slice(eq + 1).trim();
    if (value.length >= 2) {
      const q = value[0];
      if ((q === '"' || q === "'") && value[value.length - 1] === q) {
        value = value.slice(1, -1);
      }
    }
    out[key] = value;
  }
  return out;
}

const PAT_KEY = 'GITLAB_PERSONAL_ACCESS_TOKEN';

/**
 * 決策流程（spec §3.2）。回 { source: 'legacy' } 或 { source: 'file', token }；
 * 任何不安全/壞值/壞檔一律拋 SecretsError（呼叫端 fail-closed，不退回繼承 env）。
 * 依賴注入（env/platform/homedir/uid）使其可單元測試。
 */
function resolveGitlabPat({ env, platform, homedir, uid }) {
  const explicit = !!env.GFF_SECRETS_FILE;
  const file = secretsFilePath(env, homedir);

  let st;
  try { st = fs.lstatSync(file); }
  catch { st = null; }

  if (!st) {
    if (explicit) throw new SecretsError(`GFF_SECRETS_FILE 指向的檔案不存在：${file}`);
    return { source: 'legacy' }; // 無 override 且預設檔不存在 → 行為零變動
  }

  // 檔存在：POSIX 跑權限閘；win32 無法可靠檢 ACL，留待下方「會取 PAT 才 fail-closed」。
  if (platform !== 'win32') {
    const audit = auditStat(st, uid);
    if (!audit.ok) throw new SecretsError(`${audit.reason}：${file}`);
  }

  let parsed;
  try { parsed = parseEnvFile(fs.readFileSync(file, 'utf8')); }
  catch (e) {
    if (e instanceof SecretsError) throw e;
    throw new SecretsError(`secrets 檔無法讀取：${file}`);
  }

  if (!(PAT_KEY in parsed)) return { source: 'legacy' }; // per-key：無 PAT 鍵 → GitLab 不受影響

  const token = [REDACTED];
  if (token.trim() === '') {
    throw new SecretsError(`secrets 檔的 ${PAT_KEY} 為空；請填入有效 PAT 或移除該鍵（不退回 keychain）`);
  }

  if (platform === 'win32' && env.GFF_SECRETS_INSECURE_WIN !== '1') {
    throw new SecretsError('Windows 預設不支援以檔案存放 PAT（Node 無法可靠驗證 ACL）；如確認風險請設 GFF_SECRETS_INSECURE_WIN=1');
  }

  return { source: 'file', token };
}

/** legacy → 原樣繼承 env；file → 覆蓋 PAT 鍵。回新物件、不變動 baseEnv。 */
/** npm cache 根目錄：npm_config_cache 優先，否則依平台取預設值。 */
function npmCacheRoot(env = process.env, homedir = os.homedir(), isWin = isWindows) {
  if (env.npm_config_cache) return env.npm_config_cache;
  if (isWin) {
    return path.join(env.LOCALAPPDATA || path.join(homedir, 'AppData', 'Local'), 'npm-cache');
  }
  return path.join(homedir, '.npm');
}

/**
 * 在 npm cache 的 _npx/<hash>/node_modules 底下找 pkgSpec 的實體入口檔。
 *
 * 入口相對路徑一律取自該套件 package.json 的 bin 欄位（此套件是 build/index.js，
 * 與 mysql wrapper 的 dist/index.js 不同），版本必須與 pkgSpec 釘死的版本完全相符。
 * 任何 IO 失敗都回 null 而非拋出，讓呼叫端安全回退 npx。
 *
 * 邏輯與 apis-toolkit/bin/mysql-mcp.js 同款、inline 於本 wrapper（不抽共用模組，YAGNI）。
 */
function resolveCachedBin(pkgSpec, cacheRoot = npmCacheRoot(), fsImpl = fs) {
  const at = pkgSpec.lastIndexOf('@');
  if (at <= 0) return null;
  const name = pkgSpec.slice(0, at);
  const wantVersion = pkgSpec.slice(at + 1);
  const npxDir = path.join(cacheRoot, '_npx');

  let hashes;
  try {
    hashes = fsImpl.readdirSync(npxDir);
  } catch {
    return null;
  }
  for (const hash of hashes) {
    const pkgDir = path.join(npxDir, hash, 'node_modules', name);
    let meta;
    try {
      meta = JSON.parse(fsImpl.readFileSync(path.join(pkgDir, 'package.json'), 'utf8'));
    } catch {
      continue;
    }
    if (meta.version !== wantVersion) continue;
    const rel = typeof meta.bin === 'string' ? meta.bin : meta.bin && meta.bin[path.basename(name)];
    if (!rel) continue;
    return path.join(pkgDir, rel);
  }
  return null;
}

function buildChildEnv(baseEnv, resolution) {
  const env = { ...baseEnv };
  if (resolution.source === 'file') env[PAT_KEY] = resolution.token;
  return env;
}

const FORWARDED_SIGNALS = ['SIGTERM', 'SIGINT', 'SIGHUP'];
const KILL_GRACE_MS = 1500;

/**
 * wrapper 收到終止信號時把子程序一起帶走，避免 MCP server 變成孤兒（PPID=1）繼續佔資源。
 *
 * 做法：把信號轉送給子程序，wrapper 自己不立刻退出，等 child 的 exit 事件統一收尾；
 * 子程序 graceMs 內沒結束就改送 SIGKILL（server 不一定理會 SIGTERM）。
 * 只處理第一個信號：重複收到時不重送，也不讓 wrapper 回到預設的「立刻死亡」而丟下子程序。
 *
 * getTarget 用函式而非直接傳值，讓會重啟子程序的 wrapper（gcp）也能沿用同一段邏輯。
 * Windows 不註冊：沒有 POSIX 信號語意，且 npx 回退路徑經 cmd.exe，kill 只到得了外層 shell。
 * SIGKILL 無法攔截，不在本函式能處理的範圍。
 *
 * 邏輯與 apis-toolkit/bin/mysql-mcp.js、gcp-mcp/bin/observability-mcp.js 同款、inline 於本 wrapper。
 * @param {() => ({child: object, group: boolean}|null)} getTarget 當前子程序與是否整組送信號（見 signalChild）
 * @returns {{stopping: () => boolean}} stopping() 為 true 表示已進入收尾，呼叫端不應再重啟子程序
 */
function forwardSignals(getTarget, proc = process, { graceMs = KILL_GRACE_MS, platform = process.platform, setTimer = setTimeout, killFn } = {}) {
  let stopping = false;
  if (platform === 'win32') return { stopping: () => stopping };
  for (const sig of FORWARDED_SIGNALS) {
    proc.on(sig, () => {
      if (stopping) return;
      stopping = true;
      const target = getTarget();
      if (!target || !target.child) {
        proc.exit(1);
        return;
      }
      signalChild(target, sig, killFn);
      const timer = setTimer(() => signalChild(target, 'SIGKILL', killFn), graceMs);
      if (timer && typeof timer.unref === 'function') timer.unref();
    });
  }
  return { stopping: () => stopping };
}

/**
 * 對子程序送信號。group 為 true 時送給子程序所屬的整個行程群組（負的 pid）。
 *
 * npx 回退路徑的結構是 wrapper → npm exec → server，只對直接子程序送 SIGKILL 到不了 server，
 * 不理會 SIGTERM 的 server 會留成孤兒；該路徑以 detached 啟動，子程序自成一個群組，整組送才收得乾淨。
 * 直跑路徑只有 wrapper → server 兩層，不需要群組（group 為 false）。
 * 群組已不存在（ESRCH）時退回只送給子程序本身。
 */
function signalChild({ child, group }, sig, killFn = process.kill.bind(process)) {
  if (group && child.pid) {
    try {
      killFn(-child.pid, sig);
      return;
    } catch (err) {
      // ESRCH＝群組已不存在，屬正常；其他錯誤（如 EPERM）代表整組沒送到，印出來再退回只送子程序
      if (!err || err.code !== 'ESRCH') {
        console.error(`[gitlab-mcp] 對行程群組 ${child.pid} 送 ${sig} 失敗（${err && err.code ? err.code : err}），改為只送子程序`);
      }
    }
  }
  child.kill(sig);
}

/**
 * 直接子程序結束後，清掉它的行程群組裡還活著的成員（只在 group 為 true 時有作用）。
 *
 * npx 回退路徑上，外層的 npm exec 可能收到信號就先結束，而 server 不理會 SIGTERM 還活著。
 * wrapper 是跟著直接子程序的 exit 事件退出的，逾時 SIGKILL 的計時器會隨 wrapper 一起消失，
 * 所以必須在退出前當場對整組補送 SIGKILL。群組已清空時 kill 會回 ESRCH，忽略即可。
 */
function reapGroup({ child, group }, killFn = process.kill.bind(process)) {
  if (!group || !child.pid) return;
  try {
    killFn(-child.pid, 'SIGKILL');
  } catch (err) {
    // ESRCH＝群組已清空，屬正常；其他錯誤（如 EPERM）代表可能還有成員沒清掉，印出來供排查
    if (!err || err.code !== 'ESRCH') {
      console.error(`[gitlab-mcp] 清理行程群組 ${child.pid} 失敗（${err && err.code ? err.code : err}）`);
    }
  }
}

function main() {
  let resolution;
  try {
    resolution = resolveGitlabPat({
      env: process.env,
      platform: process.platform,
      homedir: os.homedir(),
      uid: typeof process.getuid === 'function' ? process.getuid() : -1,
    });
  } catch (e) {
    // fail-closed：印 sanitized 錯誤（不含 token 值）、非零 exit、不啟動 MCP server。
    console.error('[gitlab-mcp] secrets 解析失敗（fail-closed，未啟動）：' + (e && e.message ? e.message : String(e)));
    process.exit(1);
  }

  const env = buildChildEnv(process.env, resolution);
  if (!env.GITLAB_API_URL) env.GITLAB_API_URL = DEFAULT_GITLAB_API_URL;

  // 引導：legacy 來源（未建 secrets 檔 / 檔內無 PAT 鍵）且無 PAT 時提示建立 secrets 檔。
  // 仍啟動（行為零變動），僅多一行 stderr，避免新手卡在「server 起來但 GitLab 全 401」。
  if (resolution.source === 'legacy' && (!env[PAT_KEY] || env[PAT_KEY].trim() === '')) {
    console.error(`[gitlab-mcp] 未偵測到 GitLab PAT：建議建立 ~/.gff/secrets.env（${PAT_KEY}=glpat-…，chmod 600），wrapper 會優先讀取；詳見 plugin README。`);
  }

  // 直跑優先：少掉 npx 常駐層與 shell shim，wrapper 轉送的終止信號能直接送到 server 本身。
  // env 說明：GITLAB_TOOLSETS 由 .mcp.json env 注入；PAT 由上方 secrets 解析（file 覆蓋 /
  // legacy 沿用 .mcp.json 注入的 user_config）；GITLAB_API_URL 預設於上、可由 env 覆蓋。
  const cachedBin = resolveCachedBin(PKG);
  const group = !cachedBin && !isWindows; // npx 回退路徑才自成行程群組（見 signalChild）；Windows 的 detached 會另開 console
  // 信號處理在 spawn 之前就註冊；處理函式執行時依當下的子程序轉送，還沒有子程序才直接退出。
  let child = null;
  forwardSignals(() => (child ? { child, group } : null));
  child = cachedBin
    ? spawn(process.execPath, [cachedBin], { stdio: 'inherit', env })
    : spawn(npxBin, ['-y', PKG], {
        stdio: 'inherit',
        env,
        detached: group,
        shell: isWindows  // Windows 上要 shell:true 才能解析 .cmd
      });

  child.on('exit', code => {
    reapGroup({ child, group });
    process.exit(code ?? 1);
  });
  child.on('error', err => {
    console.error('failed to spawn npx:', err);
    process.exit(1);
  });
}

if (require.main === module) main();

module.exports = { parseEnvFile, secretsFilePath, auditStat, resolveGitlabPat, buildChildEnv, npmCacheRoot, resolveCachedBin, forwardSignals, signalChild, reapGroup, FORWARDED_SIGNALS, KILL_GRACE_MS, PAT_KEY, DEFAULT_GITLAB_API_URL };
