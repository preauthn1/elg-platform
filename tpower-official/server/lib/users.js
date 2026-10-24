/* 帐号 —— content/system.json 里的 users 阵列，密码存 bcrypt hash。
   角色只管一件事：admin 看得到「操作纪录」那一页，editor 看不到。 */
import bcrypt from 'bcryptjs';
import { readSystem, writeSystem } from './store.js';

export const ROLES = ['admin', 'editor'];

// 没有 role 栏位一律当 editor —— 预设成 admin 等于任何手改进去的帐号都是管理员
export const roleOf = (user) => (user && ROLES.includes(user.role) ? user.role : 'editor');

export function isAdmin(session) {
  return !!session && session.role === 'admin';
}

export function listUsers() {
  const list = readSystem('users', []); // readSystem 回的是深拷贝，改它不影响 store
  return Array.isArray(list) ? list : [];
}

export function findUser(username) {
  return listUsers().find((u) => u.username === username) || null;
}

/* 帐号不存在时拿来比对的假 hash，用来吃掉时间差 ——
   格式不合法的话 bcryptjs 在解析阶段就回 false（0.2ms vs 真比对 185ms），
   等于把「这个帐号存不存在」写在回应时间上。 */
const DUMMY_HASH = '$2b$12$ncC0Qzf.z6f9q.6z5NOry.aukvTB4pa2t7ARxUb3gCjunBHyWql8G';

/* users 不走 schema 验证，而手改 system.json 正是官方的救援程序，所以不能假设栏位是好的。
   成本只收 04–14：cost 15 以上一次比对 1.5 秒起跳（cost 31 约 27 小时），
   而 bcryptjs 是纯 JS 跑在主执行绪 —— 一个手改进去的高成本 hash
   就能让一个未登入的请求卡住整个 event loop。 */
const BCRYPT_RE = /^\$2[aby]\$(0[4-9]|1[0-4])\$[./A-Za-z0-9]{53}$/;

export async function verifyUser(username, password) {
  const user = findUser(username);
  // 格式不对就走假 hash：bcrypt.compare 对 undefined 会 reject，而 Express 4 不接
  // async rejection —— 一个未登入的请求就能终止整个行程。
  const usable = user && typeof user.passwordHash === 'string' && BCRYPT_RE.test(user.passwordHash);
  if (user && !usable) {
    console.error(`[auth] 帐号 ${user.username} 的 passwordHash 格式不正确，已当成無法登入`);
  }
  const ok = await bcrypt.compare(String(password ?? ''), usable ? user.passwordHash : DUMMY_HASH);
  return ok && usable ? user : null;
}

/** 目前有几个 admin。
 *  ⚠ 移除或降权最后一个 admin 之后，「帐号管理」这一页就再也打不开了
 *  （那一页需要 admin 角色），救援只剩 ssh 手改 system.json。所以要挡。 */
function adminCount(users) {
  return users.filter((u) => roleOf(u) === 'admin').length;
}

/** 建立或更新帐号。role 不传＝不动现有角色（否则「只改密码」会顺手把人降权）。 */
export async function upsertUser({ username, password, role }) {
  if (!/^[\w.-]{3,40}$/.test(username)) throw new Error('帐号限 3-40 字元的英数 / . _ -');
  if (password && password.length < 6) throw new Error('密码至少 6 码');
  if (role !== undefined && !ROLES.includes(role)) throw new Error(`role 只能是 ${ROLES.join(' / ')}`);

  const users = listUsers();
  const existing = users.find((u) => u.username === username);
  if (existing) {
    if (role === 'editor' && roleOf(existing) === 'admin' && adminCount(users) <= 1) {
      throw new Error('这是最后一个管理员，不能改成 editor —— 改完就没人能管理帐号了');
    }
    if (password) existing.passwordHash = bcrypt.hashSync(password, 12);
    if (role) existing.role = role;
    existing.updatedAt = new Date().toISOString();
  } else {
    if (!password) throw new Error('新帐号必须设定密码');
    users.push({
      username,
      passwordHash: bcrypt.hashSync(password, 12),
      role: role || 'editor',
      createdAt: new Date().toISOString(),
    });
  }
  await writeSystem('users', users);
  // 回实际存下来的角色，不是传进来的参数（role 没传时两者不同）
  return { username, role: roleOf(users.find((u) => u.username === username)), created: !existing };
}

/** 删除帐号。删掉最后一个（或最后一个 admin）就没人管得了后台了，所以两者都挡。 */
export async function removeUser(username) {
  const users = listUsers();
  const target = users.find((u) => u.username === username);
  if (!target) return false;
  if (users.length <= 1) throw new Error('至少要保留一个帐号');
  if (roleOf(target) === 'admin' && adminCount(users) <= 1) {
    throw new Error('这是最后一个管理员，不能移除 —— 移除后就没人能管理帐号了');
  }
  await writeSystem(
    'users',
    users.filter((u) => u.username !== username)
  );
  return true;
}
