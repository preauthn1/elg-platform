#!/usr/bin/env node
// 跨平台啟動 @benborla29/mcp-server-mysql（單白標、強制唯讀）
// 啟動路徑：優先直跑 npm cache 內已解析的 dist 入口（省掉 npx 常駐中介層，wrapper 轉送的
// 終止信號才能直接送到 server 本身）；解析不到才回退 npx -y（首次執行/快取被清）。
//
// 連線資訊來源（依序）：
//   0. dbc 代理（主路徑）——registry（../registry/whitelabels.metadata.yaml）標有 dbcPort 的
//      白標一律連本機 0.0.0.0:<dbcPort>，由 SRE 的 dbc（http://example.invalid:1688/sre/dbc）
//      以個人 Google 帳號 IAM 認證代理到 Cloud SQL 從庫：無共用帳密、SELECT-only、每條查詢
//      進 Cloud Logging 稽核。MySQL 使用者名稱＝gcloud 帳號 @ 前段、無密碼（與 dbc connect 同）。
//      dbc start 沒掛起來（port 未監聽）→ fail-closed 提示先跑 dbc start，**不**退回下面兩條
//      舊路徑（否則查詢會繞過稽核、且使用者不會察覺自己走的是共用帳密）。
//   1. 容器解析（registry dbcPort: null 的白標，即 dbc 尚未涵蓋者；目前六大站皆已涵蓋）——docker exec 進
//      php-fpm8 容器，由 wl-resolve-conn.php 解析該白標 config/database.php 的 .read 座標
//      （host/user/pass/database）。一次性（session 啟動），非每筆查詢。
//   2. ~/.gff/secrets.env fallback——同樣只對 dbcPort: null 的白標，且僅當 docker 環境
//      「不可用」（docker 未安裝 / daemon 未連線 / php-fpm8 容器不存在）才啟用
//      （key 格式見 README「無 docker/APIs 環境」段）。容器存在但未啟動不 fallback、
//      維持 fail-closed 提醒啟動——避免有 APIs 環境者被靜默切去用 secrets 的舊座標。
//
// 拒寫保證：benborla 預設 READ ONLY transaction（我們強制 ALLOW_*=false、
// MYSQL_DISABLE_READ_ONLY_TRANSACTIONS=false）→ MySQL 伺服器層以 error 1792 拒絕寫入，
// 即使連的是 read_only=0 主庫亦然（已實測；dbc 路徑的 Alfawin77 只有主庫就是這種情況）。
// dbc 路徑另有 IAM 群組 db-prod-user 只授 SELECT 的第二道防線。
const { spawn, spawnSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');
const net = require('net');

// pin 版本：避免上游 latest 漂移導致全團隊同時壞掉、難重現。
// 升級＝改這一行 → MR 標 breaking、通知 #ai-skills、手動測 /mcp（見 CONTRIBUTING §10）。
const PKG = '@benborla29/mcp-server-mysql@2.0.8';
const PKG_VERSION = PKG.slice(PKG.lastIndexOf('@') + 1);

const isWindows = process.platform === 'win32';
const npxBin = isWindows ? 'npx.cmd' : 'npx';
const dockerBin = isWindows ? 'docker.exe' : 'docker';

// APIs 容器與專案路徑（可由 env 覆蓋，預設沿用專案慣例）。
const CONTAINER = process.env.GFF_APIS_CONTAINER || 'php-fpm8';
const APIS_PATH = process.env.GFF_APIS_PATH || '/var/www/APIs';

/** WHITELABEL（= config connection 名）驗證：非空英數（防 shell / 連線名注入）。 */
function validateWhitelabel(whitelabel) {
  if (typeof whitelabel !== 'string' || !/^[A-Za-z0-9]+$/.test(whitelabel)) {
    throw new Error(`WHITELABEL 必須為非空英數字（由 .mcp.json 注入 connection 名）：${JSON.stringify(whitelabel)}`);
  }
  return whitelabel;
}

/**
 * 解析 wl-resolve-conn.php 的 KEY=VALUE 輸出。
 * @returns {{host: string, port: string, user: string, password: [REDACTED], database: string}}
 */
function parseResolverOutput(stdout) {
  const kv = {};
  for (const line of String(stdout).split(/\r?\n/)) {
    const eq = line.indexOf('=');
    if (eq === -1) continue; // 略過雜訊/空行
    const key = line.slice(0, eq).trim();
    if (!/^[A-Z]+$/.test(key)) continue;
    kv[key] = line.slice(eq + 1); // 值含 = 不截斷、不 trim（密碼可能有前後空白）
  }
  for (const req of ['HOST', 'USER', 'DATABASE']) {
    if (kv[req] === undefined || kv[req] === '') {
      throw new Error(`連線解析缺 ${req}（容器 config 解析失敗？）`);
    }
  }
  return {
    host: kv.HOST,
    port: kv.PORT || '3306',
    user: kv.USER,
    password: [REDACTED] ?? '',
    database: kv.DATABASE,
  };
}

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
 * 版本必須與 pkgSpec 釘死的版本完全相符，否則視為未命中（不接受漂移版本）。
 * 任何 IO 失敗都回 null 而非拋出，讓呼叫端安全回退 npx。
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

/**
 * 必須從 child env 刪除（而非設 false）的 key。
 *
 * SCHEMA_*_PERMISSIONS：benborla 的判斷是「schema 有列就用該值，否則才看 ALLOW_*」
 * （dist/src/db/permissions.js）。命中後直接走 executeWriteQuery，連
 * SET SESSION TRANSACTION READ ONLY 都不會執行，故可同時繞過 ALLOW_*=false 與伺服器層
 * error 1792 兩道防線。設成 'false' 字串仍會被 parse 成 permission map，只有刪除才確定不參與判斷。
 *
 * IS_REMOTE_MCP / REMOTE_SECRET_KEY / PORT：前兩者同時具值時 server 改開 HTTP 監聽埠
 * （dist/index.js:305 起），使 stdio 之外多出輸入通道。本 wrapper 只支援 stdio，一律刪除。
 *
 * MYSQL_CONNECTION_STRING / MYSQL_SOCKET_PATH：benborla 的優先序高於 MYSQL_HOST/PORT/USER/PASS/DB
 * （dist/src/config/index.js:5、:32），留著會讓整組座標被外部 env 換掉、還印著「連線來源=dbc」。
 *
 * 刪除只擋「繼承自父程序的 env」。benborla 啟動時另會 dotenv.config() 讀**子程序 cwd** 的 .env
 * （dotenv 只填尚不存在的 key），故 spawn 一律指定 cwd=SERVER_CWD（plugin bin 目錄，無 .env），
 * 否則 Claude Code 的專案目錄（如 APIs，有 Laravel .env）就能把這些被刪的 key 補回來。
 */
const SCRUBBED_ENV_KEYS = [
  'SCHEMA_INSERT_PERMISSIONS',
  'SCHEMA_UPDATE_PERMISSIONS',
  'SCHEMA_DELETE_PERMISSIONS',
  'SCHEMA_DDL_PERMISSIONS',
  'IS_REMOTE_MCP',
  'REMOTE_SECRET_KEY',
  'PORT',
  'MYSQL_CONNECTION_STRING',
  'MYSQL_SOCKET_PATH',
];

/** benborla 子程序的 cwd：固定在 plugin bin 目錄（此處不得有 .env），封掉 dotenv 從專案目錄補 env 的路徑。 */
const SERVER_CWD = __dirname;

/**
 * 組 child env：注入連線座標 + **強制唯讀**。
 * 唯讀旗標一律覆蓋為 false（即使 baseEnv/.mcp.json 注入 true 也擋），與 benborla 的
 * READ ONLY transaction（伺服器層 error 1792）構成雙重拒寫。回新物件、不變動 baseEnv。
 * 雙重拒寫成立的前提是 SCRUBBED_ENV_KEYS 已被清除：那些 key 能單獨繞過兩道防線。
 */
function buildChildEnv(baseEnv, conn) {
  const env = {
    ...baseEnv,
    MYSQL_HOST: conn.host,
    MYSQL_PORT: conn.port,
    MYSQL_USER: conn.user,
    MYSQL_PASS: conn.password,
    MYSQL_DB: conn.database,
    MYSQL_SSL: baseEnv.MYSQL_SSL || 'false',
    ALLOW_INSERT_OPERATION: 'false',
    ALLOW_UPDATE_OPERATION: 'false',
    ALLOW_DELETE_OPERATION: 'false',
    ALLOW_DDL_OPERATION: 'false',
    MULTI_DB_WRITE_MODE: 'false',
    MYSQL_DISABLE_READ_ONLY_TRANSACTIONS: 'false',
    // npx 會自動帶入此值；直跑 dist 入口時沒有，缺了工具描述會顯示 vundefined。
    npm_package_version: PKG_VERSION,
  };
  for (const key of SCRUBBED_ENV_KEYS) delete env[key];
  return env;
}

const FORWARDED_SIGNALS = ['SIGTERM', 'SIGINT', 'SIGHUP'];
const KILL_GRACE_MS = 1500;

/**
 * wrapper 收到終止信號時把子程序一起帶走，避免 MCP server 變成孤兒（PPID=1）繼續佔著 MySQL 連線。
 * benborla 在 stdin 收到 EOF 後不會自行退出（實測），wrapper 不主動結束它就一定留下孤兒。
 *
 * 做法：把信號轉送給子程序，wrapper 自己不立刻退出，等 child 的 exit 事件統一收尾；
 * 子程序 graceMs 內沒結束就改送 SIGKILL（server 不一定理會 SIGTERM）。
 * 只處理第一個信號：重複收到時不重送，也不讓 wrapper 回到預設的「立刻死亡」而丟下子程序。
 *
 * getTarget 用函式而非直接傳值，讓會重啟子程序的 wrapper（gcp）也能沿用同一段邏輯。
 * Windows 不註冊：沒有 POSIX 信號語意，且 npx 回退路徑經 cmd.exe，kill 只到得了外層 shell。
 * SIGKILL 無法攔截，不在本函式能處理的範圍。
 *
 * 邏輯與 gitlab-mcp/bin/gitlab-mcp.js、gcp-mcp/bin/observability-mcp.js 同款、inline 於本 wrapper。
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
 * 而 benborla 實測未在期限內回應 SIGTERM，會留成孤兒；該路徑以 detached 啟動，子程序自成一個群組，整組送才收得乾淨。
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
        console.error(`[apis-toolkit mysql-mcp] 對行程群組 ${child.pid} 送 ${sig} 失敗（${err && err.code ? err.code : err}），改為只送子程序`);
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
      console.error(`[apis-toolkit mysql-mcp] 清理行程群組 ${child.pid} 失敗（${err && err.code ? err.code : err}）`);
    }
  }
}

// ─────────────────────────────────────────────────────────────
// secrets.env fallback（無 docker/APIs 環境用）
// 讀取邏輯與 gitlab-mcp.js 同款、inline 於本 wrapper（不抽共用模組，YAGNI）；純函式匯出供 node:test。
// ─────────────────────────────────────────────────────────────

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
  for (const rawLine of String(content).split(/\r?\n/)) {
    const line = rawLine.trim();
    if (line === '' || line.startsWith('#')) continue;
    const eq = line.indexOf('=');
    if (eq === -1) throw new Error('secrets 檔格式錯誤：有非註解行缺少 "="');
    const key = line.slice(0, eq).trim();
    if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(key)) {
      throw new Error('secrets 檔格式錯誤：非法鍵名');
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

/**
 * 從已解析的 secrets 物件取指定 connection 的座標。
 * key：GFF_MYSQL_<CONN 大寫>_HOST / _DB 必填；_PORT 選填（預設 3306）；
 * USER / PASS 先取 per-connection（GFF_MYSQL_<CONN>_USER / _PASS），無則退全域
 * （GFF_MYSQL_USER / GFF_MYSQL_PASS）。缺任一必要 key → 拋錯指明缺哪條（fail-closed）。
 */
function connFromSecrets(parsed, whitelabel) {
  const prefix = `GFF_MYSQL_${whitelabel.toUpperCase()}_`;
  const pick = (suffix, globalKey) => {
    if (parsed[prefix + suffix] !== undefined) return parsed[prefix + suffix];
    if (globalKey && parsed[globalKey] !== undefined) return parsed[globalKey];
    return undefined;
  };
  const host = pick('HOST');
  const database = pick('DB');
  const user = pick('USER', 'GFF_MYSQL_USER');
  const password = [REDACTED]'PASS', 'GFF_MYSQL_PASS');
  const missing = [];
  if (host === undefined || host === '') missing.push(`${prefix}HOST`);
  if (database === undefined || database === '') missing.push(`${prefix}DB`);
  if (user === undefined || user === '') missing.push(`${prefix}USER 或 GFF_MYSQL_USER`);
  if (password === undefined) missing.push(`${prefix}PASS 或 GFF_MYSQL_PASS`); // 空值允許（DB 空密碼帳號），缺鍵不允許
  if (missing.length > 0) {
    throw new Error(`secrets 檔缺必要 key：${missing.join('、')}`);
  }
  return { host, port: pick('PORT') || '3306', user, password, database };
}

/** 讀取 secrets 檔並解析指定 connection 座標（含存在/權限/平台閘，任一不過即拋、fail-closed）。 */
function resolveConnViaSecrets(whitelabel, { env, platform, homedir, uid }) {
  const file = secretsFilePath(env, homedir);

  let st;
  try { st = fs.lstatSync(file); }
  catch { st = null; }
  if (!st) {
    throw new Error(`docker 環境不可用且 secrets 檔不存在：${file}（無 docker/APIs 環境請依 README 建立；有 APIs 環境請確認 docker 已啟動）`);
  }

  // POSIX 跑權限閘；win32 無法可靠檢 ACL，改以顯式 opt-in 閘（與 gitlab-mcp 同款）。
  if (platform !== 'win32') {
    const audit = auditStat(st, uid);
    if (!audit.ok) throw new Error(`${audit.reason}：${file}`);
  } else if (env.GFF_SECRETS_INSECURE_WIN !== '1') {
    throw new Error('Windows 預設不支援以檔案存放 DB 憑證（Node 無法可靠驗證 ACL）；如確認風險請設 GFF_SECRETS_INSECURE_WIN=1');
  }

  let parsed;
  try { parsed = parseEnvFile(fs.readFileSync(file, 'utf8')); }
  catch (e) {
    throw new Error(e && /secrets 檔格式/.test(e.message) ? e.message : `secrets 檔無法讀取：${file}`);
  }
  return connFromSecrets(parsed, whitelabel);
}

/**
 * 判斷 docker 主路徑可用性：
 *   'usable'      docker 可連線且 php-fpm8 容器存在並運行中 → 走容器解析
 *   'stopped'     容器存在但未啟動 → fail-closed 提醒啟動（不 fallback，避免靜默用 secrets 舊座標）
 *   'unavailable' docker 未安裝 / daemon 未連線 / 容器不存在 → 走 secrets fallback
 */
function dockerAvailability(spawnFn = spawnSync) {
  const res = spawnFn(
    dockerBin,
    ['inspect', '--format', '{{.State.Running}}', CONTAINER],
    { encoding: 'utf8' }, // 不用 shell：docker(.exe) 是真 exe，且 CONTAINER 來自 env、不進 shell 命令列
  );
  if (res.error) return 'unavailable'; // docker binary 不存在（ENOENT 等）
  if (res.status !== 0) return 'unavailable'; // daemon 未連線或容器不存在（inspect 皆非零，無法細分）
  return String(res.stdout).trim() === 'true' ? 'usable' : 'stopped';
}

/**
 * 啟動時 docker exec 進容器解析連線（同步，spawn benborla 前須先拿到座標）。
 *
 * **不可加 shell: true**：Windows 上經 cmd.exe 轉一手會讓 `input`（resolver 腳本）
 * 進不到容器內的 php——php 讀到空 stdin 便什麼都不執行、exit 0、stdout 全空，
 * 於是被誤判成「連線解析缺 HOST」，Windows 使用者六個 mysql-* 全連不上。
 * docker(.exe) 本身是可直接 spawn 的 exe，不需要 shell。
 */
function resolveConnViaContainer(whitelabel, spawnFn = spawnSync) {
  const resolverPhp = fs.readFileSync(path.join(__dirname, 'wl-resolve-conn.php'));
  const res = spawnFn(
    dockerBin,
    ['exec', '-i', CONTAINER, 'bash', '-c', `cd ${APIS_PATH} && php -- ${whitelabel}`],
    { input: resolverPhp, encoding: 'utf8' },
  );
  if (res.error) throw new Error(`docker exec 失敗（容器 ${CONTAINER} 未啟動？）：${res.error.message}`);
  if (res.status !== 0) {
    throw new Error(`容器內連線解析失敗（exit ${res.status}）：${String(res.stderr).trim()}`);
  }
  return parseResolverOutput(res.stdout);
}

// ─────────────────────────────────────────────────────────────
// dbc 主路徑：registry 標 dbcPort 的白標連本機 dbc 代理（IAM 認證、SELECT-only、查詢稽核）
// 純函式匯出供 node:test；registry 解析子集與 scripts/check-registry.php 對齊（block entry only）。
// ─────────────────────────────────────────────────────────────

const REGISTRY_FILE = path.join(__dirname, '..', 'registry', 'whitelabels.metadata.yaml');
const DBC_HOST = '0.0.0.0';
const DBC_REPO_URL = 'http://example.invalid:1688/sre/dbc';
const gcloudBin = isWindows ? 'gcloud.cmd' : 'gcloud';

/**
 * registry scalar：null/~ → null；true/false；整數；"引號" 去引號；其餘原字串（含 [flow seq]，本 wrapper 不消費）。
 * 空值刻意回 ''（與 check-registry.php 的 parse_scalar 一致）而非 null：`dbcPort:` 留空必須是錯誤，
 * 不能被當成「明示 null＝走舊路徑」的降級。
 */
function parseRegistryScalar(v) {
  v = String(v).trim();
  if (v === '') return '';
  if (v === 'null' || v === '~') return null;
  if (v === 'true') return true;
  if (v === 'false') return false;
  if (/^-?\d+$/.test(v)) return Number(v);
  if (v.length >= 2 && v[0] === '"' && v[v.length - 1] === '"') return v.slice(1, -1);
  return v;
}

/**
 * 受限 YAML parser：只認 registry 用到的子集——`whitelabels:` 根鍵 + 恰 2-space 的 block entry
 * + 恰 4-space 的 `field: scalar`。不支援 flow entry（`Key: { ... }`）與多行值；遇到即拋（fail-closed）。
 * @returns {Record<string, Record<string, unknown>>} key → 欄位物件
 */
function parseRegistry(text) {
  const out = Object.create(null); // 無原型：registry 出現 __proto__ 之類的 key 不會污染原型
  let inRoot = false;
  let cur = null;
  const lines = String(text).split(/\r?\n/);
  for (let i = 0; i < lines.length; i++) {
    const raw = lines[i];
    if (raw.trim() === '' || /^\s*#/.test(raw)) continue;
    if (/^whitelabels:\s*$/.test(raw)) { inRoot = true; continue; }
    if (!inRoot) continue;
    let m = raw.match(/^  ([A-Za-z0-9_]+):\s*(.*)$/);
    if (m) {
      if (m[2].trim() !== '') throw new Error(`registry 第 ${i + 1} 行：頂層 entry 只支援 block 形式：${raw.trim()}`);
      cur = m[1];
      out[cur] = Object.create(null);
      continue;
    }
    m = raw.match(/^    ([A-Za-z0-9_]+):\s*(.*)$/);
    if (cur !== null && m) {
      out[cur][m[1]] = parseRegistryScalar(m[2]);
      continue;
    }
    throw new Error(`registry 第 ${i + 1} 行無法解析：${raw.trim()}`);
  }
  if (!inRoot) throw new Error('registry 缺 whitelabels 根鍵');
  return out;
}

/** 讀取並解析 registry；讀不到 / 解析失敗即拋（fail-closed，不猜 connection、不 fallback）。 */
function loadRegistry(file = REGISTRY_FILE, fsImpl = fs) {
  let text;
  try { text = fsImpl.readFileSync(file, 'utf8'); }
  catch (e) { throw new Error(`registry 讀取失敗：${file}（${e && e.message ? e.message : e}）`); }
  return parseRegistry(text);
}

/**
 * 取白標的 dbc 座標。
 *   回 { port, database }   → 走 dbc 路徑
 *   回 null                 → registry 明示 `dbcPort: null`（dbc 未涵蓋），走容器 / secrets 舊路徑
 *   拋                      → 白標不在 registry、缺 dbcPort 欄位、或 dbcPort / database 型別不合（fail-closed）
 * WHITELABEL（= .mcp.json 注入的 connection 名）以 registry key 查找，不做別名解析（.mcp.json 只用 canonical）。
 */
function dbcEntryFor(registry, whitelabel) {
  const e = Object.prototype.hasOwnProperty.call(registry, whitelabel) ? registry[whitelabel] : undefined;
  if (!e || typeof e !== 'object') throw new Error(`registry 無此白標：${whitelabel}（registry/whitelabels.metadata.yaml）`);
  if (!Object.prototype.hasOwnProperty.call(e, 'dbcPort')) {
    throw new Error(`registry ${whitelabel} 缺 dbcPort 欄位（dbc 未涵蓋的白標請明示 dbcPort: null）`);
  }
  if (e.dbcPort === null) return null;
  if (!Number.isInteger(e.dbcPort) || e.dbcPort < 1 || e.dbcPort > 65535) {
    throw new Error(`registry ${whitelabel} dbcPort 非合法 port：${JSON.stringify(e.dbcPort)}`);
  }
  if (typeof e.database !== 'string' || !/^[A-Za-z0-9_]+$/.test(e.database)) {
    throw new Error(`registry ${whitelabel} database 須為 safe-token：${JSON.stringify(e.database)}`);
  }
  return { port: e.dbcPort, database: e.database };
}

/** gcloud 設定目錄：CLOUDSDK_CONFIG（gcloud 官方 env）優先；否則 POSIX ~/.config/gcloud、Windows %APPDATA%\gcloud。 */
function gcloudConfigDir(env, homedir, isWin = isWindows) {
  if (env.CLOUDSDK_CONFIG) return env.CLOUDSDK_CONFIG;
  if (isWin) return path.join(env.APPDATA || path.join(homedir, 'AppData', 'Roaming'), 'gcloud');
  return path.join(homedir, '.config', 'gcloud');
}

/** 從 gcloud configurations/config_<name>（INI）取 [core] account；沒有回空字串。 */
function accountFromGcloudIni(ini) {
  let section = '';
  for (const raw of String(ini).split(/\r?\n/)) {
    const line = raw.trim();
    if (line === '' || line.startsWith('#') || line.startsWith(';')) continue;
    const sec = line.match(/^\[(.+)\]$/);
    if (sec) { section = sec[1].trim(); continue; }
    const kv = line.match(/^([A-Za-z_]+)\s*=\s*(.*)$/);
    if (kv && section === 'core' && kv[1] === 'account') return kv[2].trim();
  }
  return '';
}

/**
 * 取 gcloud 目前登入帳號（與 dbc 的 auth.Account 同語意＝`gcloud config get-value account`）。
 * 順序：CLOUDSDK_CORE_ACCOUNT env（gcloud 官方覆寫）→ gcloud 設定檔（active_config →
 * configurations/config_<name> 的 [core] account）→ spawn gcloud。
 * 先讀檔再 spawn 的原因：MCP 子程序的 PATH 未必含 gcloud（macOS 常裝在 ~/google-cloud-sdk/bin），
 * 且讀檔零延遲；spawn 只當設定檔缺時的最後手段。三者皆取不到 → 拋（fail-closed）。
 */
function gcloudAccount({ env, homedir, spawnFn = spawnSync, fsImpl = fs }) {
  const override = env.CLOUDSDK_CORE_ACCOUNT;
  if (typeof override === 'string' && override.trim() !== '') return override.trim();
  const dir = gcloudConfigDir(env, homedir);
  try {
    const name = fsImpl.readFileSync(path.join(dir, 'active_config'), 'utf8').trim() || 'default';
    const acct = accountFromGcloudIni(fsImpl.readFileSync(path.join(dir, 'configurations', `config_${name}`), 'utf8'));
    if (acct) return acct;
  } catch {
    // 設定檔缺 / 讀不到 → 退 spawn
  }
  // 引數全為常數、不含使用者輸入；Windows 的 gcloud.cmd 需 shell 才能解析（CVE-2024-27980 修補後直接 spawn .cmd 會 EINVAL）。
  const res = spawnFn(gcloudBin, ['config', 'get-value', 'account'], { encoding: 'utf8', shell: isWindows });
  const acct = res && !res.error && res.status === 0 ? String(res.stdout).trim() : '';
  if (acct === '' || acct === '(unset)') {
    throw new Error('取不到 gcloud 登入帳號：請先執行 dbc setup（或 gcloud auth login）');
  }
  return acct;
}

/**
 * dbc 的 MySQL 使用者名稱＝帳號 @ 前段（與 dbc auth.MySQLUser 同規則）。
 * 產出限 email local-part 字元集（防控制字元／空白進 MYSQL_USER）。冒名無效：dbc 代理以 ADC 的 IAM token
 * 當密碼送給 Cloud SQL，伺服器驗 token 身分與使用者名稱是否相符；名稱與 token 不符只會 Access denied。
 */
function mysqlUserFromAccount(account) {
  const s = String(account);
  const at = s.indexOf('@');
  if (at <= 0) throw new Error(`無法從 gcloud 帳號解析 MySQL 使用者名稱：${JSON.stringify(account)}`);
  const user = s.slice(0, at);
  if (!/^[A-Za-z0-9._+-]+$/.test(user)) throw new Error(`gcloud 帳號的使用者名稱段含非法字元：${JSON.stringify(user)}`);
  return user;
}

/**
 * 本機 port 是否已有程序在監聽（dbc start 有沒有掛起來）。
 * 做法是「試綁同一個 loopback port」：EADDRINUSE＝有人在聽；綁成功＝沒人、立刻關掉。
 * 刻意不用 connect 探測：連上 dbc 就算立刻斷開，dbc 端也會為這條連線撥一次 Cloud SQL、留下連線層雜訊。
 */
function portListening(port, host = DBC_HOST) {
  return new Promise(resolve => {
    const srv = net.createServer();
    srv.once('error', err => resolve(err && err.code === 'EADDRINUSE'));
    srv.once('listening', () => srv.close(() => resolve(false)));
    srv.listen({ port, host, exclusive: true });
  });
}

/** dbc 座標：host 固定 loopback、密碼欄留空（身分由 dbc 代理以 IAM token 綁定，不靠 MySQL 密碼）。 */
function connViaDbc(entry, user) {
  return { host: DBC_HOST, port: String(entry.port), user, password: '', database: entry.database };
}

/** dbc 路徑完整解析：port 探測（未掛 → 拋，不退舊路徑）→ gcloud 帳號 → 座標。 */
async function resolveConnViaDbc(entry, deps) {
  if (!(await portListening(entry.port))) {
    throw new Error(
      `dbc 代理未啟動（${DBC_HOST}:${entry.port} 未監聽）。請另開終端機執行 dbc start 並保持開啟` +
      `（未安裝見 ${DBC_REPO_URL}），再 /reload-plugins`,
    );
  }
  return connViaDbc(entry, mysqlUserFromAccount(gcloudAccount(deps)));
}

async function main() {
  let conn;
  try {
    const whitelabel = validateWhitelabel(process.env.WHITELABEL);
    const dbc = dbcEntryFor(loadRegistry(), whitelabel);
    if (dbc) {
      conn = await resolveConnViaDbc(dbc, { env: process.env, homedir: os.homedir() });
      console.error(`[apis-toolkit mysql-mcp] ${whitelabel} 連線來源=dbc（${DBC_HOST}:${dbc.port}，IAM 認證、查詢進 Cloud Logging 稽核）`);
    } else if (dockerAvailability() === 'unavailable') {
      // 無 docker/APIs 環境 → secrets fallback；stderr 標示來源，避免與容器解析混淆。
      conn = resolveConnViaSecrets(whitelabel, {
        env: process.env,
        platform: process.platform,
        homedir: os.homedir(),
        uid: typeof process.getuid === 'function' ? process.getuid() : -1,
      });
      console.error(`[apis-toolkit mysql-mcp] ${whitelabel} 連線來源=secrets.env（registry dbcPort: null 且 docker 環境不可用，走 fallback）`);
    } else {
      // 'usable' 走容器解析；'stopped' 也進此路徑，由 docker exec 失敗帶出「容器未啟動」提醒。
      conn = resolveConnViaContainer(whitelabel);
      console.error(`[apis-toolkit mysql-mcp] ${whitelabel} 連線來源=容器 config（registry dbcPort: null，dbc 尚未涵蓋）`);
    }
  } catch (e) {
    // fail-closed：印 sanitized 錯誤（不含憑證值）、非零 exit、不啟動 MCP server。
    // 不重試、不監看 .env；修正後（dbc start / 啟動容器 / 改 config / 補 secrets）請 /reload-plugins 重啟即重新解析。
    console.error('[apis-toolkit mysql-mcp] 連線解析失敗（fail-closed，未啟動）：' + (e && e.message ? e.message : String(e)) + '｜修正後請 /reload-plugins');
    process.exit(1);
  }

  const env = buildChildEnv(process.env, conn);

  // 直跑優先：少掉 npx 常駐層與 shell shim，wrapper 轉送的終止信號能直接送到 server 本身。
  const cachedBin = resolveCachedBin(PKG);
  const group = !cachedBin && !isWindows; // npx 回退路徑才自成行程群組（見 signalChild）；Windows 的 detached 會另開 console
  // 信號處理在 spawn 之前就註冊；處理函式執行時依當下的子程序轉送，還沒有子程序才直接退出。
  let child = null;
  forwardSignals(() => (child ? { child, group } : null));
  child = cachedBin
    ? spawn(process.execPath, [cachedBin], { stdio: 'inherit', env, cwd: SERVER_CWD })
    : spawn(npxBin, ['-y', PKG], {
        stdio: 'inherit',
        env,
        cwd: SERVER_CWD,
        detached: group,
        shell: isWindows, // Windows 上要 shell:true 才能解析 .cmd
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

if (require.main === module) {
  main().catch(err => {
    console.error('[apis-toolkit mysql-mcp] 啟動失敗：' + (err && err.message ? err.message : String(err)));
    process.exit(1);
  });
}

module.exports = {
  validateWhitelabel,
  parseResolverOutput,
  buildChildEnv,
  npmCacheRoot,
  resolveCachedBin,
  forwardSignals,
  signalChild,
  reapGroup,
  FORWARDED_SIGNALS,
  KILL_GRACE_MS,
  secretsFilePath,
  auditStat,
  parseEnvFile,
  connFromSecrets,
  resolveConnViaSecrets,
  dockerAvailability,
  resolveConnViaContainer,
  // dbc 主路徑
  REGISTRY_FILE,
  DBC_HOST,
  SERVER_CWD,
  SCRUBBED_ENV_KEYS,
  parseRegistryScalar,
  parseRegistry,
  loadRegistry,
  dbcEntryFor,
  gcloudConfigDir,
  accountFromGcloudIni,
  gcloudAccount,
  mysqlUserFromAccount,
  portListening,
  connViaDbc,
  resolveConnViaDbc,
};
