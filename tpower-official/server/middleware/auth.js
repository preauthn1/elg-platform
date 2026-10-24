import { findUser, roleOf, isAdmin } from '../lib/users.js';
import { config } from '../lib/config.js';

/** ::ffff:0.0.0.0（IPv4-mapped IPv6）拉平成 0.0.0.0，不然名单永远比不中。 */
export const normalizeIp = (ip) => String(ip || '').replace(/^::ffff:/i, '');

const LOOPBACK = new Set(['0.0.0.0', '::1']);

/** 这个来源 IP 可不可以用 admin 角色。名单只有一份，在 .env 的 ADMIN_IPS。
 *
 *  ⚠ 名单刻意不放 system.json（後台可编辑的那份）：设错一次就没有人能当 admin，
 *  而「改名单」这件事本身需要 admin —— 那是个救不回来的死锁。
 *  放 .env 的救援程序是「改档 + 重启」，不必经过后台。
 *
 *  ⚠ 白名单绑「admin 角色」，不是绑整个 /admin 路径 ——
 *  后台是给站方自己用的，登入页与内容编辑对所有人开放；
 *  admin 角色（操作纪录、系统档案）才是我们自己的。
 *
 *  ⚠ 依赖 trust proxy 的层数设对（见 index.js）——
 *  层数设多了，访客自己塞的 X-Forwarded-For 会被当成来源 IP，白名单就能绕过。 */
/** IPv4 点分十进位 → 32 位整数；不是合法 IPv4 就回 null。 */
function ipv4ToInt(ip) {
  const parts = String(ip).split('.');
  if (parts.length !== 4) return null;
  let n = 0;
  for (const part of parts) {
    if (!part.length || part.length > 3) return null;
    for (const ch of part) if (ch < '0' || ch > '9') return null;
    const v = Number(part);
    if (v > 255) return null;
    n = n * 256 + v;
  }
  return n;
}

/** 名单里的一条规则符不符合这个 IP。三种写法：
 *
 *    0.0.0.0       单一 IP（完全相同）
 *    0.0.0.0/24     CIDR 网段
 *    192.168.50.*        通配（等同 /24；192.168.* 等同 /16）
 *
 *  ⚠ 为什么要支援网段：区网的机器是 DHCP 配的，一台一台列上去，租约一换就登不进来。
 *  而「改名单」需要重启，不是站方能自己处理的事。
 *
 *  ⚠ 只处理 IPv4。区网都是 IPv4，而 IPv6 的前缀比对要另一套；写半套比没有更危险 ——
 *  所以 IPv6 一律只接受完全相同的写法（走最上面那个 includes）。 */
function ruleMatches(rule, ip) {
  if (rule === ip) return true;

  const slash = rule.indexOf('/');
  if (slash > 0) {
    const bits = Number(rule.slice(slash + 1));
    const netInt = ipv4ToInt(rule.slice(0, slash));
    const ipInt = ipv4ToInt(ip);
    if (netInt === null || ipInt === null) return false;
    if (!Number.isInteger(bits) || bits < 0 || bits > 32) return false;
    if (bits === 0) return true;
    // 无号右移：bits=32 时 >>> 32 会变成不位移，所以先挡掉 0 再算
    const mask = (0xffffffff << (32 - bits)) >>> 0;
    return (netInt & mask) === (ipInt & mask);
  }

  if (rule.endsWith('.*')) {
    const prefix = rule.slice(0, -1); // 留着结尾那个点，避免 192.168.5.* 命中 0.0.0.0
    return ip.startsWith(prefix);
  }

  return false;
}

export function isAllowedAdminIp(req) {
  const ip = normalizeIp(req.ip);
  for (const rule of config.adminIps) if (ruleMatches(rule, ip)) return true;
  // 开发模式放行 localhost，否则本机测不了 admin 那几页
  return !config.isProd && LOOPBACK.has(ip);
}

function denyAuth(req, res) {
  // 注意：router 挂载后 req.path 是相对路径，要用 baseUrl 判断完整位置
  if ((req.baseUrl + req.path).startsWith('/api/')) {
    return res.status(401).json({ ok: false, error: '未登入或连线已过期' });
  }
  return res.redirect('/admin/login.html');
}

export function requireAuth(req, res, next) {
  if (!req.session || !req.session.user) return denyAuth(req, res);

  // 每次请求都回头核对帐号档：cookie 只是「登入过」的证明，不是权限的来源。
  // 少了这步，帐号被删或被降权之后，旧 cookie 还能用到 8 小时后过期。
  const user = findUser(req.session.user);
  if (!user) {
    req.session = null;
    return denyAuth(req, res);
  }
  // 角色以档案为准，顺手把 session 里的旧角色更新掉
  req.session.role = roleOf(user);
  return next();
}

/** 只有管理员能过。挂在操作纪录端点前面 ——
 *  后台把选单项目藏起来只是介面，不是权限：少了这道，任何登入的帐号
 *  直接打 GET /api/admin/audit 就拿到全部纪录（含其他人的 IP）。
 *  顺序必须排在 requireAuth 后面（它才会把最新角色写进 session）。 */
export function requireAdmin(req, res, next) {
  if (!isAdmin(req.session)) {
    return res.status(403).json({ ok: false, error: '仅管理员可使用这个功能' });
  }
  /* 角色对了还要看 IP。这层不能只做在登入 —— 少了它，从公司登入拿到的 cookie
     带到别处照样打得开这些端点。 */
  if (!isAllowedAdminIp(req)) {
    console.warn(`[auth] admin 端点被名单外的 IP 存取：${normalizeIp(req.ip)} ${req.originalUrl}`);
    return res.status(403).json({ ok: false, error: '这个功能限公司 IP 使用' });
  }
  return next();
}

// CSRF 防护：cookie 是 SameSite=Lax，另外要求变更类请求
// 必须带自訂 header（跨站表单/请求带不了自訂 header），并比对 Origin。
export function sameOriginGuard(req, res, next) {
  if (['GET', 'HEAD', 'OPTIONS'].includes(req.method)) return next();
  if (req.get('x-requested-with') !== 'fetch') {
    return res.status(403).json({ ok: false, error: '缺少必要的请求标头' });
  }
  const origin = req.get('origin');
  if (origin) {
    let host;
    try {
      host = new URL(origin).host;
    } catch {
      return res.status(403).json({ ok: false, error: 'Origin 不合法' });
    }
    if (host !== req.get('host')) {
      return res.status(403).json({ ok: false, error: 'Origin 不符' });
    }
  }
  return next();
}
