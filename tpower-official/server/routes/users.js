/* 帐号管理 —— 新增／移除帐号、改密码、改角色。仅管理员。

   三道锁叠着（都在 requireAdmin 里）：登入 → 管理员角色 → IP 白名单。
   站方拿的是 editor，看不到这一页也打不到这些端点。

   ⚠ 回应一律不带 passwordHash。它对前端毫无用处，而这一页会被浏览器快取、
   会进开发者工具的 network 面板、也可能被截图 —— 没有理由让杂凑离开伺服器。

   ⚠ 会造成「没人管得了后台」的操作由 lib/users.js 挡（最后一个帐号、
   最后一个 admin）；「不能砍自己」在这一层挡，因为只有这里知道现在是谁。 */
import { Router } from 'express';
import { listUsers, upsertUser, removeUser, roleOf, ROLES } from '../lib/users.js';
import { audit } from '../lib/audit.js';
import { requireAdmin } from '../middleware/auth.js';

export const usersRouter = Router();

/** 对外的帐号形状：没有 passwordHash。 */
const publicUser = (u) => ({
  username: u.username,
  role: roleOf(u),
  createdAt: u.createdAt || '',
  updatedAt: u.updatedAt || '',
});

const fail = (res, msg, status = 422) => res.status(status).json({ ok: false, error: msg });

usersRouter.get('/users', requireAdmin, (req, res) => {
  res.json({ ok: true, data: { users: listUsers().map(publicUser), roles: ROLES, me: req.session.user } });
});

usersRouter.post('/users', requireAdmin, async (req, res, next) => {
  try {
    const { username, password, role } = req.body || {};
    if (typeof username !== 'string' || typeof password !== 'string') {
      return fail(res, '请输入帐号与密码');
    }
    if (listUsers().some((u) => u.username === username)) return fail(res, `帐号已存在：${username}`);
    const r = await upsertUser({ username, password, role });
    audit('user_add', 'auth', { username: r.username, role: r.role }, { user: req.session.user, ip: req.ip });
    res.json({ ok: true, data: { username: r.username, role: r.role } });
  } catch (err) {
    // upsertUser 的验证错误（帐号格式、密码长度、角色）都是使用者输入问题
    if (err instanceof Error && !err.status) return fail(res, err.message);
    next(err);
  }
});

usersRouter.put('/users/:username', requireAdmin, async (req, res, next) => {
  try {
    const { username } = req.params;
    const { password, role } = req.body || {};
    const target = listUsers().find((u) => u.username === username);
    if (!target) return fail(res, `找不到帐号：${username}`, 404);
    if (password === undefined && role === undefined) return fail(res, '没有要更新的栏位');
    if (password !== undefined && typeof password !== 'string') return fail(res, '密码格式不正确');

    /* 不准把自己降权。lib/users.js 只挡「最后一个 admin」——
       还有别的 admin 时降自己是合法的，但那几乎都是误触，而且当下就看不到这一页了。
       真要降自己就请另一个管理员来做。 */
    if (username === req.session.user && role === 'editor') {
      return fail(res, '不能把自己改成 editor（请让另一个管理员操作）');
    }
    const r = await upsertUser({ username, password: [REDACTED] || undefined, role });
    audit(
      'user_update',
      'auth',
      { username, role: r.role, passwordChanged: !!password },
      { user: req.session.user, ip: req.ip }
    );
    res.json({ ok: true, data: { username: r.username, role: r.role } });
  } catch (err) {
    if (err instanceof Error && !err.status) return fail(res, err.message);
    next(err);
  }
});

usersRouter.delete('/users/:username', requireAdmin, async (req, res, next) => {
  try {
    const { username } = req.params;
    // 砍自己会当场把自己踢出这一页，而且几乎都是误触
    if (username === req.session.user) return fail(res, '不能移除自己的帐号');
    const removed = await removeUser(username);
    if (!removed) return fail(res, `找不到帐号：${username}`, 404);
    audit('user_remove', 'auth', { username }, { user: req.session.user, ip: req.ip });
    res.json({ ok: true });
  } catch (err) {
    if (err instanceof Error && !err.status) return fail(res, err.message);
    next(err);
  }
});
