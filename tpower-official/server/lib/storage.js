/* 储存驱动 —— 资料一律放在专案底下的 content/。
   store.js 与 routes/uploads.js 透过这一支存取，不再直接碰 fs。

   ⚠ content/ 绝对不能被外部下载：system.json 里有后台帐号的密码杂凑。
   目前是三层挡着：Node 只 static 挂了 /admin、/uploads、/assets；nginx 只有
   /assets/ 与 /uploads/ 从磁碟供档，其余全 proxy 给 Node；再加上 nginx 与 Node
   各有一条明确挡掉 /content/ 的规则（见 deploy/nginx.conf 与 server/index.js）。
   ⚠ 换成「整个专案目录丢给静态主机」那种部署方式的话，上面三层全都不存在，
   system.json 会直接变成可下载的档案 —— 那种部署必须把 content/ 排除在外。

   ⚠ 为什么「读」是同步的：readEntity 有 23 个呼叫点，全部在渲染热路径上
   （每个公开页请求都会读好几次）。改成 async 会扩散到整个 render.js 与 Nunjucks
   样板，那是大改。所以两份 JSON（共约 190 KB）在启动时一次载进记忆体，
   之后读记忆体、写才落磁碟。 */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { config } from './config.js';

export class ConflictError extends Error {
  constructor(key) {
    super(`${key} 已被其他人改过，请重读后再写一次`);
    this.name = 'ConflictError';
    this.status = 409;
  }
}

/* key 的前缀决定它落在哪个目录：
     uploads/…            → ROOT/uploads   （前台直接供档的地方）
     其余（content.json / system.json / snapshots/…）→ ROOT/content

   ⚠ 曾经全部都拼在 content/ 底下，于是站方从后台上传的图进了 content/uploads/，
   而前台是从 ROOT/uploads/ 供档 —— 上传显示成功，图片却永远 404。 */
function localPath(key) {
  const rel = String(key).replace(/^[\\/]+/, '');
  if (rel.split(/[\\/]/).includes('..')) throw new Error(`储存键不可含 ..：${key}`);
  const m = rel.match(/^uploads(?:[\\/](.*))?$/);
  if (m) return m[1] ? path.join(config.dirs.uploads, m[1]) : config.dirs.uploads;
  return path.join(config.dirs.content, rel);
}

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

/* 原子写入：先写 temp 再 rename。
   rename 要重试 —— Windows 上只要有别的 handle 开着目标档（防毒、索引器）就丢 EPERM，
   实测 2100 次连写约 35 次踩到，对站方就是随机的「存档失败」。Linux 上不会发生。 */
const RENAME_RETRIES = 5;

function localWrite(file, buf) {
  ensureDir(path.dirname(file));
  const tmp = `${file}.${crypto.randomBytes(4).toString('hex')}.tmp`;
  fs.writeFileSync(tmp, buf);
  for (let i = 0; ; i++) {
    try {
      fs.renameSync(tmp, file);
      return;
    } catch (err) {
      const retriable = err.code === 'EPERM' || err.code === 'EACCES' || err.code === 'EBUSY';
      if (!retriable || i >= RENAME_RETRIES) {
        try {
          fs.rmSync(tmp, { force: true });
        } catch {
          /* 清不掉就留着，sweepOrphanTmp 之后会处理 */
        }
        throw err;
      }
      // 同步小睡：Atomics.wait 是唯一不用 busy-loop 的同步等待方式
      Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 10 * (i + 1));
    }
  }
}

// ---------- blob（content.json / system.json）----------

/* key → { text, version, stat }
   version 是「内容的 sha1」，stat 是「mtime+大小」。两者分工不同：

   - version 给 compare-and-swap 用。⚠ 不能拿 mtime 当 version —— mtime 的精度有限，
     连续两次写入常常落在同一个 tick（实测连写 200 次有一半以上撞号）。撞号等于
     CAS 静静失效：握着旧版本的人会通过检查、把别人刚存的盖掉，两边都显示「储存成功」。
     内容雜湊没有这个问题，而且「内容一样＝版本一样」本来就是对的语意。
   - stat 只是便宜的「档案动过没」侦测器，让热路径上的同步读不必每次都雜湊 120KB。 */
const memo = new Map();

/** 便宜的变动侦测：mtime + 大小。档案不存在回 null。 */
function statOf(file) {
  try {
    const st = fs.statSync(file);
    return st.mtimeMs + ':' + st.size;
  } catch (err) {
    if (err.code === 'ENOENT') return null;
    throw err;
  }
}

const hashOf = (text) => crypto.createHash('sha1').update(text, 'utf8').digest('hex').slice(0, 16);

/** 启动时把 blob 拉进记忆体。 */
export async function preload(keys) {
  for (const key of keys) readTextSync(key);
}

/** 同步读。档案动过就重读 —— 手改 content.json 不必重启。 */
export function readTextSync(key) {
  const file = localPath(key);
  const stat = statOf(file);
  const hit = memo.get(key);
  if (hit && hit.stat === stat) return hit.text;
  const text = stat === null ? null : fs.readFileSync(file, 'utf8');
  memo.set(key, { text, stat, version: text === null ? null : hashOf(text) });
  return text;
}

/** 目前记忆体里这份 blob 的版本，写入时当作 compare-and-swap 的基准。 */
export function versionOf(key) {
  const hit = memo.get(key);
  return hit ? hit.version : null;
}

/** 写入。ifVersion 给了就做条件写入，版本对不上丢 ConflictError
 *  —— 两个人同时按存档时，后到的那个会被挡下来，而不是静静盖掉前一个。
 *  比对的是磁碟上的真实内容，不是记忆体里的快取，所以别的行程改过也挡得住。 */
export async function writeText(key, text, { ifVersion } = {}) {
  const file = localPath(key);
  if (ifVersion !== undefined) {
    const onDisk = statOf(file) === null ? null : hashOf(fs.readFileSync(file, 'utf8'));
    if (onDisk !== ifVersion) throw new ConflictError(key);
  }
  localWrite(file, Buffer.from(text, 'utf8'));
  memo.set(key, { text, stat: statOf(file), version: hashOf(text) });
  return hashOf(text);
}

// ---------- 物件（快照、上传的图片）----------

export async function putObject(key, buf) {
  localWrite(localPath(key), buf);
}

export async function getObject(key) {
  try {
    return fs.readFileSync(localPath(key));
  } catch (err) {
    if (err.code === 'ENOENT') return null;
    throw err;
  }
}

/** 列出某个前缀底下的物件（回相对于该前缀的名称 + 大小 + 时间）。 */
export async function listObjects(dirKey) {
  const dir = localPath(dirKey);
  if (!fs.existsSync(dir)) return [];
  return fs.readdirSync(dir).map((name) => {
    const st = fs.statSync(path.join(dir, name));
    return { name, size: st.size, at: st.mtime.toISOString() };
  });
}

export async function removeObject(key) {
  fs.rmSync(localPath(key), { force: true });
}

export function describe() {
  return config.dirs.content;
}
