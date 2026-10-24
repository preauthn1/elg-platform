/* 外壳：路由表、面包屑、两步骤弹窗。
   独立成一支是为了让 views/* 可以引用弹窗，而不用回头 import app.js（避免循环相依）。 */
import { el, esc, ACTIONS } from './ui.js';

/* 侧栏选单 —— 前台一页对后台一页，顺序照前台导览列。
   ⚠ 顺序必须跟 server/lib/render.js 的 NAV_PRIMARY + NAV_MORE 一致
   （前台是「主项 6 个 + 更多 5 个」，品牌资讯排在代理招募之后，不是官方联系之后）。
   content.json 的 pages 阵列也照同一个顺序排 —— 那是「全站共用 → 导览列与页尾」
   那张表的排法。三处对不上的话，站方在后台找页面的顺序跟前台看到的不一样。
   站方要改哪一页的东西，在选单上就是那一页，不必先想「那要去哪个设定」。
   两个例外：
     site   每页都会出现的东西（品牌、导览列文字、页首按钮、页尾版权）
     audit  操作纪录，仅管理员
   刻意不分组：16 项一眼看完，分组只会多一层点击。 */
export const ROUTES = [
  { key: 'site', path: '/site/index', name: '全站共用', ico: '⚙' },
  { key: 'home', path: '/home/index', name: '首页', ico: '⌂' },
  { key: 'advantages', path: '/advantages/index', name: '核心优势', ico: '◈' },
  { key: 'promotions', path: '/promotions/index', name: '热门活动', ico: '◷' },
  { key: 'games', path: '/games/index', name: '游戏内容', ico: '◱' },
  { key: 'brand', path: '/brand/index', name: '品牌影像', ico: '▶' },
  { key: 'contact', path: '/contact/index', name: '官方联系', ico: '◉' },
  { key: 'guide', path: '/guide/index', name: '使用指南', ico: '▧' },
  { key: 'faq', path: '/faq/index', name: '常见问题', ico: '◇' },
  { key: 'partners', path: '/partners/index', name: '代理招募', ico: '⚑' },
  { key: 'blog', path: '/blog/index', name: '品牌资讯', ico: '✎' },
  { key: 'compliance', path: '/compliance/index', name: '信任与安全', ico: '⛊' },
  /* adminOnly 只影响选单要不要画出来 —— 真正的权限在伺服器端
     （GET /api/admin/audit 前面的 requireAdmin）。sep 是上面那条分隔线。 */
  { key: 'audit', path: '/audit/index', name: '操作纪录', ico: '⎔', adminOnly: true, sep: true },
  { key: 'accounts', path: '/accounts/index', name: '帐号管理', ico: '⛭', adminOnly: true },
  /* 系统设置：取数用的 API 网址与三个密钥。密钥等同 apis 的 .env，
     所以跟帐号管理同一层权限。品牌网域不在这里 —— 那不是秘密，
     在「首页」那一页（见 views/system.js）。 */
  { key: 'system', path: '/system/index', name: '系统设置', ico: '⇄', adminOnly: true },
  /* 内容快照：每次存档前自动留的那一份，可以挑一份还原。还原是整个类别覆写，
     等於把站方之後的修改推翻，所以跟操作纪录同一层权限（见 views/snapshots.js）。 */
  { key: 'snapshots', path: '/snapshots/index', name: '内容快照', ico: '⟲', adminOnly: true },
];

/** 目前这个帐号看得到的选单项目。 */
export function visibleRoutes(isAdmin) {
  return ROUTES.filter((r) => !r.adminOnly || isAdmin);
}

export function crumb(key) {
  const r = ROUTES.find((x) => x.key === key);
  return `<div class="crumb">${esc(r.name)}</div>`;
}

/* ---------------- 编辑弹窗 ----------------
   原型是两步骤（Step1 基本资料 → 下一步 → Step2 内容与图片），规格 §3 照抄了。
   实作改成单页：要换一张图得先按「编辑」再按「下一步」，四个步骤只为了看到上传钮，
   而两段内容加起来也就一个对话框的高度，分页没有换到任何东西。
   呼叫端仍然分成 step1 / step2 两个函式（那是内容的自然分段），
   这里把它们串起来、中间放一条分隔线。 */
let dlgCtx = null;

export function openDlg(title, ctx) {
  dlgCtx = ctx;
  el('dlgTitle').textContent = title;
  el('mask').classList.add('open');
  paintDlg();
}

export function closeDlg() {
  const ctx = dlgCtx;
  /* ⚠ 先让焦点离开弹窗里的输入框，再还原：Esc 关窗时浏览器会在弹窗藏起来那一刻才补发 change，
     ui.js 的 onEdit 会把刚改的字写回 draft、盖掉还原的备份。blur() 当下同步送出 change，排在还原之前就无害。 */
  const focused = document.activeElement;
  if (focused && el('mask').contains(focused) && focused.blur) focused.blur();
  el('mask').classList.remove('open');
  dlgCtx = null;
  // 取消时让呼叫端有机会还原（page_blocks 的子弹窗直接编辑 draft，取消要复原）
  if (ctx && ctx.onClose) ctx.onClose(!!ctx.saved);
}

export function markDlgSaved() {
  if (dlgCtx) dlgCtx.saved = true;
}

export function paintDlg() {
  if (!dlgCtx) return;
  const second = dlgCtx.step2 ? `<hr class="dlgsep">${dlgCtx.step2()}` : '';
  el('dlgBody').innerHTML = `<div id="dlgErr"></div>${dlgCtx.step1()}${second}`;
  el('dlgFoot').innerHTML =
    `<button class="btn" data-act="dlg.close">取消</button>` +
    (dlgCtx.onSave ? '<button class="btn primary" data-act="dlg.save">更新储存</button>' : '');
}

Object.assign(ACTIONS, {
  'dlg.close': () => closeDlg(),
  'dlg.save': async () => {
    if (dlgCtx && dlgCtx.onSave) await dlgCtx.onSave();
  },
});
