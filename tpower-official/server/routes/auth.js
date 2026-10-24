import { Router } from 'express';
import rateLimit from 'express-rate-limit';
import { verifyUser, roleOf } from '../lib/users.js';
import { audit } from '../lib/audit.js';
import { requireAuth, isAllowedAdminIp, normalizeIp } from '../middleware/auth.js';
import { retryAfterMs, noteFailure, clearFailures } from '../lib/login-throttle.js';

export const authRouter = Router();

/* per-IP 限流。刻意放得宽 —— 真正挡「猜某个帐号的密码」的是下面的 per-account
   节流（lib/login-throttle.js），那一道跟来源无关，换 IP 也没用。
   这一道只负责「别让单一主机一直打」，所以额度给得够站方摸索密码：
   50 次/15 分钟。原本是 10 次，站方连打 10 次就被锁 15 分钟且没有别的办法，太紧。
   ⚠ key 是 req.ip，正确性依赖 trust proxy 的层数设对（见 index.js）。
   ⚠ 状态在记忆体，重启归零；要跑多副本的话这一道会失效，per-account 那道也一样。 */
const loginLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  limit: 50,
  standardHeaders: true,
  legacyHeaders: false,
  message: { ok: false, error: '尝试次数过多，请稍后再试' },
});

/* bcrypt 是纯 JS（bcryptjs 没有原生 binding），一次比对烧约 185ms 的 CPU，
   而且不走 libuv threadpool。并发的登入请求会把主执行绪吃掉 ——
   实测 30 个假帐号同时登入，公开的 /api/stats 尾延迟从 1.5ms 变成 5.4 秒。
   限流是「每 IP 15 分钟 10 次」，挡不住多 IP 同时来，所以另外压一个同时处理上限：
   超过就直接回 429，不排队、不占 CPU。 */
const MAX_INFLIGHT_LOGINS = 4;
let inflightLogins = 0;

/* 这道闸刻意排在 loginLimiter **前面**。排后面的话，被上限挡掉的请求也会吃掉
   loginLimiter 的每 IP 额度 —— 由别人的并发流量造成的 429，会把某个管理员的
   10 次/15 分钟烧完并锁他 15 分钟，而他一次错密码都没送过。 */
function inflightGuard(req, res, next) {
  if (inflightLogins >= MAX_INFLIGHT_LOGINS) {
    res.set('Retry-After', '2');
    return res.status(429).json({ ok: false, error: '目前登入请求过多，请稍候再试' });
  }
  return next();
}

authRouter.post('/login', inflightGuard, loginLimiter, async (req, res) => {
  const { username, password } = req.body || {};
  if (typeof username !== 'string' || typeof password !== 'string' || !username || !password) {
    return res.status(400).json({ ok: false, error: '请输入帐号密码' });
  }
  /* per-account 节流：跟来源 IP 无关，所以换 IP 绕不过。
     排在 bcrypt 之前 —— 被挡下来的请求不该烧 185ms 的 CPU。 */
  const waitMs = retryAfterMs(username);
  if (waitMs > 0) {
    const secs = Math.ceil(waitMs / 1000);
    res.set('Retry-After', String(secs));
    console.warn(`[auth] 帐号 ${username} 连续失败过多，还要等 ${secs}s ip=${req.ip}`);
    return res
      .status(429)
      .json({ ok: false, error: `尝试次数过多，请 ${secs} 秒后再试` });
  }

  let user;
  inflightLogins++;
  try {
    user = await verifyUser(username, password);
  } catch (err) {
    // verifyUser 已经把已知的坏资料挡在前面了，这层是保险：
    // 这个 handler 的 rejection 没人接（Express 4 不接 async rejection），
    // 漏出去会变成 unhandledRejection 直接终止行程。
    console.error('[auth] 验证过程发生例外：', err);
    return res.status(500).json({ ok: false, error: '验证失败，请稍后再试' });
  } finally {
    inflightLogins--;
  }
  if (!user) {
    const nextWait = noteFailure(username);
    console.warn(
      `[auth] 登入失败 user=${username} ip=${req.ip}` +
        (nextWait ? ` 下次要等 ${Math.ceil(nextWait / 1000)}s` : '')
    );
    audit('login_failed', 'auth', { username }, { user: username, ip: req.ip });
    return res.status(401).json({ ok: false, error: '帐号或密码错误' });
  }
  // 密码对了就把这个帐号的失败纪录归零（就算之后被 IP 白名单挡下来也一样，
  // 因为「密码是对的」已经证明这不是在猜密码）
  clearFailures(username);
  /* admin 角色限公司 IP。

     ⚠ 排在密码验证**之后**：排前面的话，任何人打这支都能从讯息差异问出
     「这个帐号是不是 admin」。

     ⚠ 回的东西跟密码错误**一模一样**（401 + 同一句话），不讲真正的原因。
     能走到这里的人已经知道正确密码了 —— 密码要是外流，至少别再附送
     「这组是 admin，而且有 IP 白名单」这条情报，那等于告诉对方换个来源再试。
     状态码也要一样：只改字串不改码，403 跟 401 照样分得出来。

     ⚠ 这不会把自己人锁在外面：上面的 clearFailures 在这之前就跑了，
     密码是对的就不算一次失败，所以从外面重试几次都不会触发锁定。
     代价是自己人走错网路时看到的是「帐号或密码错误」——
     真正的原因在 log 与操作纪录里（reason: ip_not_allowed），照那个查。

     站方的帐号是 editor，不受这条影响。 */
  if (roleOf(user) === 'admin' && !isAllowedAdminIp(req)) {
    // ⚠ 印比对时真正用的那个 IP（已去掉 ::ffff:）—— 看 log 的人会直接把它抄进 ADMIN_IPS
    console.warn(`[auth] admin 帐号从名单外的 IP 登入被挡 user=${user.username} ip=${normalizeIp(req.ip)}`);
    audit('login_failed', 'auth', { username, reason: 'ip_not_allowed' }, { user: username, ip: req.ip });
    return res.status(401).json({ ok: false, error: '帐号或密码错误' });
  }
  // cookie-session：换一颗新 session 内容（等效 regenerate）
  req.session = { user: user.username, role: roleOf(user), loginAt: Date.now() };
  console.log(`[auth] 登入成功 user=${user.username} role=${req.session.role} ip=${req.ip}`);
  audit('login', 'auth', {}, { user: user.username, ip: req.ip });
  return res.json({ ok: true, user: user.username, role: req.session.role });
});

authRouter.post('/logout', (req, res) => {
  req.session = null;
  res.json({ ok: true });
});

// requireAuth 会回头核对帐号档并把最新角色写回 session ——
// 少了它，帐号被删或降权之后 /me 还会照 cookie 回报旧身分与旧角色。
authRouter.get('/me', requireAuth, (req, res) => {
  res.json({ ok: true, user: req.session.user, role: req.session.role });
});
