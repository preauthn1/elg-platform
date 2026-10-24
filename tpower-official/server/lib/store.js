/* 资料存取层 —— 全站唯一直接碰档案的地方。
   对外的介面是逐类别的（readEntity / writeEntity），底下的物理储存是单一
   content/content.json。介面与储存刻意分开：routes / render / 后台共 59 个
   呼叫点都不知道也不需要知道档案怎么摆，之后要换 SQLite 也只改这一支。 */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { config } from './config.js';
import * as storage from './storage.js';

const SNAPSHOT_KEEP = 200;

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

export function readJson(file, fallback = null) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (err) {
    if (err.code === 'ENOENT') return fallback;
    throw err;
  }
}

/* 原子写入：先写 temp 再 rename。
   rename 要重试 —— Windows 上只要有别的 handle 开着目标档（防毒、索引器）就丢 EPERM，
   实测 2100 次连写约 35 次踩到，对站方就是随机的「存档失败」。Linux 上不会发生。 */
const RENAME_RETRIES = 5;

export function writeJsonAtomic(file, data) {
  ensureDir(path.dirname(file));
  const tmp = `${file}.${crypto.randomBytes(4).toString('hex')}.tmp`;
  fs.writeFileSync(tmp, JSON.stringify(data, null, 2) + '\n', 'utf8');
  for (let i = 0; ; i++) {
    try {
      fs.renameSync(tmp, file);
      return;
    } catch (err) {
      const retriable = err.code === 'EPERM' || err.code === 'EACCES' || err.code === 'EBUSY';
      if (!retriable || i >= RENAME_RETRIES) {
        // 失败就把 temp 清掉，否则每次失败都留一颗孤儿 .tmp 在 content/ 底下
        try {
          fs.rmSync(tmp, { force: true });
        } catch {
          /* 清不掉就留着 */
        }
        throw err;
      }
      // 同步小睡：Atomics.wait 是唯一不用 busy-loop 的同步等待方式
      Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 10 * (i + 1));
    }
  }
}

function tsId(date = new Date()) {
  return date.toISOString().replace(/[-:]/g, '').replace(/\..+/, '').replace('T', '_');
}

// 页面快取靠它判断过期。只有 writeEntity 会 +1 —— 系统资料写入不该洗掉整站快取。
let version = 1;

export function contentVersion() {
  return version;
}

/** content.json 的内容雜湊（前 16 字 sha1）。给「快取要不要失效」当依据。
 *
 *  ⚠ 刻意不用 contentVersion()：那是行程内的计数器，重启就归零 —— 同一个版号会对应到
 *  不同的内容，快取因此可能拿到过期的东西。雜湊是内容算出来的，重启也一样。 */
export function contentStamp() {
  return storage.versionOf(CONTENT_KEY) || '';
}

/* 两份 JSON，形状都是 { "<键>": <资料> }：
     content.json  站方配置的内容，14 个类别 —— 合成一份是为了跨类别的原子写入
                   （存游戏分类 + 存游戏商本来是两次，中间挂掉会留下孤儿参照）
     system.json   帐号、操作纪录、取数快照 —— 分开是因为它是机器在写的
                   （每小时取数、每次登入），混在一起会每小时重写整份内容

   ⚠ system.json 有密码 hash，等同机密：权限 600、不进版控，而且绝不能被 HTTP 取得
   —— 见 storage.js 顶端与 deploy/nginx.conf：content/ 绝对不能被外部下载。 */
const NAME_RE = /^[a-z][a-z0-9_]{0,40}$/;
const CONTENT_KEY = 'content.json';
const SYSTEM_KEY = 'system.json';

// 两份 blob 统一的序列化格式（结尾换行，diff 与 git 都比较好看）
const toJson = (obj) => JSON.stringify(obj, null, 2) + '\n';
// 旧布局（一个类别一个档）的唯读相容路径 —— 见下面 readSystemFile() 的回退
const CONTENT_FILE = () => path.join(config.dirs.content, CONTENT_KEY);

/** 启动时把两份 blob 拉进记忆体，之后读取才能是同步的。 */
export async function preloadStore() {
  await storage.preload([CONTENT_KEY, SYSTEM_KEY]);
  return storage.describe();
}

/* system.json 的键。分档之后「写系统资料不会清页面快取」是结构上必然的。
   api 是取数端点的位址与加解密秘密 —— 唯读，程式不会写它，后台也没有介面，
   要改就 ssh 上去改档案。放这里而不是 .env 是站方要求「设定集中在一个地方」。
   ⚠ admin 的 IP 白名单刻意**不**放这里 —— 它在 .env 的 ADMIN_IPS，
   理由见 middleware/auth.js 的 isAllowedAdminIp。 */
const SYSTEM_KEYS = new Set(['users', 'stats_history', 'audit', 'api']);

// 稽核是阵列，要有上限否则 system.json 無止境长大。2000 笔约好几个月。
const AUDIT_KEEP = 2000;

function assertName(name) {
  if (!NAME_RE.test(name)) throw Object.assign(new Error(`不合法的资料名称：${name}`), { status: 400 });
  return name;
}

/* 启动时清掉孤儿 .tmp（行程被 kill 在 write 与 rename 之间留下的，永远不会被读到）。
   只清 5 分钟以上没动过的 —— 另一个行程可能正握着一颗新的。 */
const TMP_ORPHAN_MS = 5 * 60 * 1000;

function sweepOrphanTmp() {
  if (!fs.existsSync(config.dirs.content)) return;
  const now = Date.now();
  for (const f of fs.readdirSync(config.dirs.content)) {
    if (!f.endsWith('.tmp')) continue;
    const p = path.join(config.dirs.content, f);
    try {
      if (now - fs.statSync(p).mtimeMs < TMP_ORPHAN_MS) continue;
      fs.rmSync(p);
      console.log(`[store] 清掉孤儿暂存档 ${f}`);
    } catch {
      /* 清不掉就留着，下次再试 */
    }
  }
}

sweepOrphanTmp();

/* --- 内容那一份 --- */

// 少了这层 memo，渲染一页要 parse 150 KB 十三次
let blobMemo = { version: 0, stamp: undefined, data: null };

/** content.json 存在就以它为唯一来源，不存在则从旧布局（一个类别一个档）读 ——
 *  唯读相容，所以升级不需要迁移脚本。 */
function readBlob() {
  /* 两个失效条件：version 是这个行程自己写的，storage 那边的版本是别人改的
     （ssh 手改、别的副本存档）。少了第二个，外部改动要重启才生效。 */
  const text = storage.readTextSync(CONTENT_KEY);
  const stamp = storage.versionOf(CONTENT_KEY);
  if (blobMemo.version === version && blobMemo.stamp === stamp && blobMemo.data) return blobMemo.data;
  let data = text === null ? null : JSON.parse(text);
  if (data && typeof data === 'object') {
    // 开发中期有一版把全部资料（含帐密）写进 content.json。那种档案还在时要把系统键剥掉。
    const stray = Object.keys(data).filter((k) => SYSTEM_KEYS.has(k));
    if (stray.length) {
      console.warn(
        `[store] content.json 里有不该存在的系统键（${stray.join('、')}），读取时已剥除。` +
          `帐密仍留在那个档案里直到下一次存档覆写它 。`
      );
      data = Object.fromEntries(Object.entries(data).filter(([k]) => !SYSTEM_KEYS.has(k)));
    }
  } else {
    data = {};
    if (fs.existsSync(config.dirs.content)) {
      for (const f of fs.readdirSync(config.dirs.content)) {
        if (!f.endsWith('.json') || f === 'content.json' || f === 'system.json') continue;
        const name = f.replace(/\.json$/, '');
        // publishes 是死资料；系统资料归 system.json
        if (!NAME_RE.test(name) || name === 'publishes' || SYSTEM_KEYS.has(name)) continue;
        const v = readJson(path.join(config.dirs.content, f), null);
        if (v !== null) data[name] = v;
      }
    }
  }
  blobMemo = { version, stamp, data };
  return data;
}

/* --- 系统那一份 --- */

// 系统资料的写入刻意不动版本号，所以这份只看 mtime
let sysMemo = { stamp: undefined, data: null };

function readSysBlob() {
  const text = storage.readTextSync(SYSTEM_KEY);
  const stamp = storage.versionOf(SYSTEM_KEY);
  if (sysMemo.data && sysMemo.stamp === stamp) return sysMemo.data;

  let data = text === null ? null : JSON.parse(text);
  if (data === null || typeof data !== 'object') data = readLegacySystem();
  sysMemo = { stamp, data };
  return data;
}

/** system.json 不存在时从旧布局捞，依新到旧：content.json 里的系统键 →
 *  各自独立的 users.json / stats_history.json / audit.ndjson。
 *  少了第一步，若 users.json 已被删掉就会变成没人登得进后台。 */
function readLegacySystem() {
  const data = {};
  if (!fs.existsSync(config.dirs.content)) return data;

  const merged = readJson(CONTENT_FILE(), null);
  if (merged && typeof merged === 'object') {
    for (const key of SYSTEM_KEYS) {
      if (merged[key] !== undefined) data[key] = merged[key];
    }
  }

  for (const key of ['users', 'stats_history']) {
    if (data[key] !== undefined) continue;
    const v = readJson(path.join(config.dirs.content, `${key}.json`), null);
    if (v !== null) data[key] = v;
  }

  // 稽核最早的格式是 ndjson（一行一笔），改成阵列
  const oldAudit = path.join(config.dirs.content, 'audit.ndjson');
  if (data.audit === undefined && fs.existsSync(oldAudit)) {
    data.audit = fs
      .readFileSync(oldAudit, 'utf8')
      .split(/\r?\n/)
      .filter(Boolean)
      .map((line) => {
        try {
          return JSON.parse(line);
        } catch {
          return null; // 写到一半断电留下的残行，丢掉
        }
      })
      .filter(Boolean)
      .slice(-AUDIT_KEEP);
  }
  return data;
}

async function writeSysBlob(next) {
  const stamp = await storage.writeText(SYSTEM_KEY, toJson(next), {
    ifVersion: storage.versionOf(SYSTEM_KEY),
  });
  sysMemo = { stamp, data: next };
}


/** 读写系统资料（users / stats_history / audit / api）。不留快照、不影响页面快取。
 *
 *  回传深拷贝 —— 这几个键的消费端都是「读出来改一改再写回去」（upsertUser 改
 *  passwordHash、pushSnapshot 改 sameAt），拿到参照就会就地改到 memo，
 *  写入失败时记忆体里还是留着那笔磁碟上没有的修改。
 *  这里不像 readEntity 在渲染热路径上（登入／存档／每小时才碰一次），复制不心疼。 */
export function readSystem(key, fallback = null) {
  if (!SYSTEM_KEYS.has(key)) throw new Error(`不是系统资料键：${key}`);
  const v = readSysBlob()[key];
  return v === undefined ? fallback : structuredClone(v);
}

export async function writeSystem(key, data) {
  if (!SYSTEM_KEYS.has(key)) throw new Error(`不是系统资料键：${key}`);
  await writeSysBlob({ ...readSysBlob(), [key]: data });
  return data;
}

/** system.json 还不存在但旧布局有资料时，先把它落地。 */
async function ensureSystemPersisted() {
  if (storage.versionOf(SYSTEM_KEY) !== null) return;
  const sys = readSysBlob();
  if (!Object.keys(sys).length) return; // 全新安装，没有系统资料可搬
  await writeSysBlob(sys);
  console.log(`[store] 已把系统资料写入 system.json（${Object.keys(sys).join('、')}）`);
}

/** 往稽核纪录尾端加一笔（取代旧的 ndjson append）。 */
/* 同一个帐号的连续登入失败，在这段时间内收成一笔带次数与来源清单的纪录。
   ⚠ 不合并的话：稽核是固定 2000 笔的环形、所有动作共用，2000 次失败登入就把
   「谁改了什么」的纪录全部推挤掉 —— 分散攻击几分钟就做到。而且每一笔都重写
   整份 system.json（目前 100 KB 出头）。

   ⚠ 刻意**不**把 IP 放进合并条件。第一版放了，结果分散攻击（每次换 IP）
   完全不会合并 —— 那正是要防的那一种。改成只看帐号，来源记在 ips 里，
   「某个帐号被 1400 个 IP 试了 2000 次」本来就比 2000 笔各自一行更好读。
   只合并 login_failed：其余动作每一笔都是一次真实操作，不能省。 */
const AUDIT_MERGE_MS = 10 * 60 * 1000;
const AUDIT_MERGE_IPS = 20; // ips 最多留几个，避免这一笔本身长到无上限

function mergeableAudit(prev, entry) {
  return (
    prev &&
    entry.action === 'login_failed' &&
    prev.action === entry.action &&
    prev.user === entry.user &&
    Date.parse(entry.at) - Date.parse(prev.at) <= AUDIT_MERGE_MS
  );
}

export async function appendAudit(entry) {
  const list = readSystem('audit', []);
  const rows = Array.isArray(list) ? list : [];
  const prev = rows[rows.length - 1];
  if (mergeableAudit(prev, entry)) {
    /* 更新最后那一笔：次数 +1、时间推到最新（firstAt 保留第一次的时间）、
       来源累积进 ips（去重、有上限）。ip 保留最新的一个，让表格那一栏照样有值。 */
    const seen = new Set(prev.ips || (prev.ip ? [prev.ip] : []));
    if (entry.ip) seen.add(entry.ip);
    const merged = {
      ...prev,
      firstAt: prev.firstAt || prev.at,
      at: entry.at,
      ip: entry.ip || prev.ip,
      ips: [...seen].slice(0, AUDIT_MERGE_IPS),
      count: (prev.count || 1) + 1,
    };
    return writeSystem('audit', rows.slice(0, -1).concat([merged]).slice(-AUDIT_KEEP));
  }
  return writeSystem('audit', rows.concat([entry]).slice(-AUDIT_KEEP));
}

/* --- 站方内容（14 个类别） --- */

export function readEntity(name, fallback = null) {
  assertName(name);
  if (SYSTEM_KEYS.has(name)) throw new Error(`${name} 是系统资料，请用 readSystem`);
  const v = readBlob()[name];
  return v === undefined ? fallback : v;
}

export function hasEntity(name) {
  assertName(name);
  return !SYSTEM_KEYS.has(name) && readBlob()[name] !== undefined;
}

/** 写入一个类别。写入前留一份该类别的快照 —— 逐类别是刻意的，
 *  整份还原会把同一天别人做的其他修改一起盖掉。 */
export async function writeEntity(name, data, { by = 'system' } = {}) {
  assertName(name);
  if (SYSTEM_KEYS.has(name)) throw new Error(`${name} 是系统资料，请用 writeSystem`);
  const blob = readBlob();
  const before = blob[name];
  if (before !== undefined) await snapshot(name, before);
  /* 覆写前先确保系统资料有自己的落脚处：储存上若还是「全部一档」那版而
     system.json 尚未产生，这次覆写会把帐密抹掉。 */
  await ensureSystemPersisted();
  const next = { ...blob, [name]: data };
  /* 条件写入：ifVersion 对不上就丢 ConflictError，不会静静盖掉别人的修改。
     单副本永远不会撞到；多副本时呼叫端收到 409 要重读后再存一次。 */
  const stamp = await storage.writeText(CONTENT_KEY, toJson(next), {
    ifVersion: storage.versionOf(CONTENT_KEY),
  });
  version += 1; // 页面快取立即失效 —— 存档就是上线
  blobMemo = { version, stamp, data: next };
  console.log(`[store] ${name} 已更新 by=${by}`);
  return data;
}

/** settings 在每个公开请求的热路径上（解析网址的语系前缀要用它）。 */
export function readSettings() {
  return readEntity('settings') || {};
}

/** 站方内容的类别清单（不含系统资料）。 */
export function listEntities() {
  return Object.keys(readBlob()).filter((n) => NAME_RE.test(n) && !SYSTEM_KEYS.has(n));
}

// --- 快照 ---
const SNAPSHOT_DIR = 'snapshots';
const SNAPSHOT_RE = /^(\d{8}_\d{6})_([0-9a-f]{4})_([a-z][a-z0-9_]*)\.json$/;

// 档名带随机码：同一秒内连续储存不会互相覆盖
export async function snapshot(name, data) {
  const rand = crypto.randomBytes(2).toString('hex');
  const file = `${tsId()}_${rand}_${name}.json`;
  await storage.putObject(`${SNAPSHOT_DIR}/${file}`, Buffer.from(toJson(data), 'utf8'), 'application/json');
  await pruneSnapshots();
  return file;
}

async function pruneSnapshots() {
  const files = (await storage.listObjects(SNAPSHOT_DIR))
    .map((o) => o.name)
    .filter((f) => SNAPSHOT_RE.test(f))
    .sort();
  for (const f of files.slice(0, Math.max(0, files.length - SNAPSHOT_KEEP))) {
    try {
      await storage.removeObject(`${SNAPSHOT_DIR}/${f}`);
    } catch {
      /* 并发下可能已被删掉 */
    }
  }
}

export async function listSnapshots() {
  const files = await storage.listObjects(SNAPSHOT_DIR);
  return files
    .map((o) => o.name)
    .filter((f) => SNAPSHOT_RE.test(f))
    .sort()
    .reverse()
    .map((f) => {
      const m = f.match(SNAPSHOT_RE);
      return { file: f, ts: m[1], name: m[3] };
    });
}

export async function readSnapshot(file) {
  // 只接受合法快照档名，不吃任何路径
  if (!SNAPSHOT_RE.test(file)) throw Object.assign(new Error('快照名称不合法'), { status: 400 });
  const buf = await storage.getObject(`${SNAPSHOT_DIR}/${file}`);
  if (!buf) throw Object.assign(new Error('找不到这份快照'), { status: 404 });
  return { name: file.match(SNAPSHOT_RE)[3], data: JSON.parse(buf.toString('utf8')) };
}

