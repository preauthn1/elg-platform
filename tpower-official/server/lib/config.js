import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

// 极简 .env 载入：只补上未定义的变数，不覆盖既有环境变数
const envFile = path.join(ROOT, '.env');
if (fs.existsSync(envFile)) {
  for (const line of fs.readFileSync(envFile, 'utf8').split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
    if (m && process.env[m[1]] === undefined) process.env[m[1]] = m[2];
  }
}

const isProd = process.env.NODE_ENV === 'production';

/* 這一套程式要跑哪一站：決定樣板、前台資產、seed 從哪個子目錄拿，以及 server/sites/<站>.js。
   沒設就是 tpower（既有正式機的 .env 沒有這一行）。 */
const site = String(example.invalid || 'tpower').trim() || 'tpower';
if (!/^[a-z0-9-]+$/.test(site) || site === 'index' || !fs.existsSync(path.join(ROOT, 'server', 'sites', site + '.js'))) {
  throw new Error(`SITE「${site}」不存在：要有 server/sites/${site}.js`);
}

/* Node 自己那层记忆体页面快取的秒数：正式 60、开发 0（改样板重整就看得到）。
   存档会立刻让它失效（内容版号一变，见 lib/pagecache.js），
   所以这 60 秒只影响「时间驱动」的内容 —— 活动 14:00 到期，最晚 14:01 从列表消失。 */
const pageCacheSeconds = isProd ? 60 : 0;

/* 送给 nginx／CDN 的 s-maxage。

   ⚠ 刻意跟上面那个分开，不要合成一个值。差别在「看不看得到存档」：
   Node 那层看得到（内容版号一变当场失效），nginx 看不到 ——
   所以它握几秒，就是站方存档后要等几秒才看得到。两者性质不同。

   ⚠ 不设 0。s-maxage=0 会让 nginx 判定不可快取、根本不存，那就失去
   proxy_cache_use_stale —— Node 重启或挂掉的那几秒访客会吃到 502 而不是稍旧的页面。
   留 5 秒两个好处都在：过期的项目仍然留在快取里给 use_stale 用，
   直到 inactive=1h 才淘汰（见 deploy/nginx.conf）。 */
const sharedCacheSeconds = isProd ? 5 : 0;

let sessionSecret = [REDACTED];
if (!sessionSecret) {
  if (isProd) throw new Error('SESSION_SECRET 未设定：production 必须提供固定的 SESSION_SECRET');
  // 开发模式：每次启动随机（重启会登出，开发可接受）
  sessionSecret = [REDACTED]'hex');
}

/* admin 角色的 IP 白名单。

   ⚠ 绑的是「admin 角色」，不是整个 /admin 路径 —— 后台是给站方自己用的，
   登入页与内容编辑对所有人开放。admin 角色（操作纪录、系统档案）才是我们自己的。
   站方的帐号给 editor，不受这份名单影响。

   ⚠ 刻意放在 .env 而不是 system.json：名单设错就等于把自己关在门外，
   而放在 system.json 的话「改名单」这件事本身也要先进得了后台才做得到。
   放 .env 的救援程序是「改档 + 重启」，不需要经过后台。

   ⚠ 依赖 trust proxy 的层数设对（见 index.js）——
   层数设多了，访客自己塞的 X-Forwarded-For 会被当成来源 IP，白名单就能绕过。

   开发模式额外放行 localhost，否则本机测不了那几页。正式环境只认名单。

   ⚠ 没有内建预设值，空的名单＝正式环境没有任何 IP 能当 admin。这是刻意的：
   反过来（没设就全部放行）会让这道防线在没人发现的情况下整个消失，而「设错被挡在
   门外」至少当场看得见。名单填在 .env（见 .env.example）。 */
const ADMIN_IPS = String(process.env.ADMIN_IPS || '')
  .split(',')
  /* ⚠ 名单也要拿掉 ::ffff:，跟 middleware/auth.js 的 normalizeIp 同一个规则。
     Node 在双栈 socket 上给的 IPv4 长成 ::ffff:0.0.0.0，比对前会先去掉前缀；
     名单要是不跟着去掉，照着 log 把 ::ffff:… 抄进来的人就永远对不上 ——
     密码是对的，却一直被挡，而且画面只会显示「帐号或密码错误」。 */
  .map((s) => s.trim().replace(/^::ffff:/i, ''))
  .filter(Boolean);

// 正式环境没设名单就等於没人能当 admin —— 不挡启动（公开页不需要後台），但要讲出来
if (isProd && !ADMIN_IPS.length) {
  console.warn('[config] ADMIN_IPS 未设定：正式环境没有任何 IP 能以 admin 角色登入。');
}

export const config = {
  root: ROOT,
  site,
  isProd,
  port: Number(process.env.PORT || 3000),
  sessionSecret,
  pageCacheSeconds,
  sharedCacheSeconds,
  adminIps: ADMIN_IPS,
  dirs: {
    // 選填，沒設就是 content/、uploads/
    content: path.resolve(ROOT, process.env.CONTENT_DIR || 'content'),
    snapshots: path.join(path.resolve(ROOT, process.env.CONTENT_DIR || 'content'), 'snapshots'),
    uploads: path.resolve(ROOT, process.env.UPLOADS_DIR || 'uploads'),
    templates: path.join(ROOT, 'templates', site),
    // 對外仍是 /assets/…，樣板裡的網址不分站
    assets: path.join(ROOT, 'assets', site),
    admin: path.join(ROOT, 'admin'),
    seed: path.join(ROOT, 'seed', site),
  },
};

export default config;
