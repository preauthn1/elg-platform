/* 内容 draft 管理 —— 每个 entity 读一次放记忆体，画面直接编辑这份 draft，
   「更新储存」把整包 PUT 回去；成功后重新读回（让 zod 的预设值、排序正规化反映到画面）。 */
import { api } from './api.js';
import { toast, showErrors, clearErrors, renderView, setSiteLangs } from './ui.js';

const drafts = {};
/* 每个 entity 从伺服器读回来当下的样子（序列化）。画面是直接编辑 drafts[key]，
   没有任何变更事件可以挂，所以「有没有未储存的修改」只能靠跟这份快照比。
   只在按下重新载入时比一次，序列化的成本无所谓。 */
const clean = {};
const snap = (key) => { clean[key] = JSON.stringify(drafts[key]); };

export let session = { user: '', role: 'editor' };

/** 有没有未储存的编辑。app.js 的重新载入钮用它决定要不要二次确认 ——
 *  少了这个 export，那里的 `store.isDirty && store.isDirty()` 会短路成 false，
 *  确认框永远不跳，按下去就把使用者的编辑整包丢掉。 */
export function isDirty() {
  return Object.keys(drafts).some((k) => JSON.stringify(drafts[k]) !== clean[k]);
}

/** 目前登入的帐号是不是管理员。只用来决定「操作纪录」选单要不要显示 ——
 *  真正的权限在伺服器端（middleware/auth.js 的 requireAdmin）。 */
export function isAdmin() {
  return session.role === 'admin';
}

export async function loadSession() {
  const r = await example.invalid();
  session = { user: r.user, role: r.role || 'editor' };
  return session;
}

export function get(key) {
  return drafts[key];
}

/** 上一次从伺服器读回来的样子，也就是「已经存档」的内容。
 *
 *  ⚠ 跟 get() 的差别是整件事的重点：画面编辑的是 drafts[key]，里面可能有
 *  还没按「更新储存」的新列。要判断「这一列存过了没」只能拿这份比 ——
 *  少了它，弹窗会让人选到只存在于草稿里的分类，存档时被伺服器的外键挡下来
 *  （「第 54 笔的分类『Horse Racing』不存在于 game_categories」），
 *  而看讯息的人完全不知道要回去按哪一颗按钮。 */
export function saved(key) {
  return clean[key] === undefined ? undefined : JSON.parse(clean[key]);
}

export function has(key) {
  return Object.prototype.hasOwnProperty.call(drafts, key);
}

export async function ensure(keys) {
  const missing = keys.filter((k) => !has(k));
  await Promise.all(missing.map(async (k) => {
    drafts[k] = await api.getContent(k);
    snap(k);
  }));
  if (drafts.settings) setSiteLangs(drafts.settings.languages);
  return keys.map((k) => drafts[k]);
}

export async function reload(keys) {
  await Promise.all(keys.map(async (k) => {
    drafts[k] = await api.getContent(k);
    snap(k);
  }));
  if (drafts.settings) setSiteLangs(drafts.settings.languages);
}

/** 储存一个 entity。回传 true/false；失败时 draft 原封不动（使用者的编辑不会消失）。 */
export async function save(key, { confirmMsg = '', okMsg = '已更新' } = {}) {
  if (confirmMsg && !window.confirm(confirmMsg)) return false;
  clearErrors();
  try {
    await api.putContent(key, drafts[key]);
    drafts[key] = await api.getContent(key);
    snap(key);
    if (key === 'settings') setSiteLangs(drafts[key].languages);
    toast(okMsg);
    renderView();
    return true;
  } catch (err) {
    showErrors('view', err);
    toast('储存失败：' + err.message, 'info');
    return false;
  }
}

/** 弹窗里的储存：错误显示在弹窗顶部，成功才关闭。 */
export async function saveFromDialog(key, { closeFn, okMsg = '已更新' } = {}) {
  clearErrors('dlg');
  try {
    await api.putContent(key, drafts[key]);
    drafts[key] = await api.getContent(key);
    snap(key);
    toast(okMsg);
    if (closeFn) closeFn();
    renderView();
    return true;
  } catch (err) {
    showErrors('dlg', err);
    toast('储存失败：' + err.message, 'info');
    return false;
  }
}

/** 画面整包换掉 draft（例如排序、批次编辑）。刻意不呼叫 snap() ——
 *  这是使用者的修改，就是要让 isDirty() 回 true。 */
export function replace(key, data) {
  drafts[key] = data;
}
