/* 官网内容管理 —— 后台入口。
   外壳（侧栏／弹窗／lightbox／toast）沿用工单附的后台原型，
   差别只在资料层：原型的 const DATA 换成 /api/admin 的真实内容。 */
import { api } from './js/api.js';
import * as store from './js/store.js';
import {
  el,
  esc,
  toast,
  ACTIONS,
  bindRoot,
  setModel,
  setRenderHooks,
  setInlineLang,
  dlgLangs,
  setDlgLangs,
  langs,
  zoom,
  pickFile,
  initFilePicker,
  clearErrors,
  refreshNear,
} from './js/ui.js';
import { ROUTES, paintDlg, closeDlg, visibleRoutes } from './js/shell.js';
import { VIEWS } from './js/views/index.js';

let current = 'home';

/* ============================================================
   Shell render
   ============================================================ */
function renderMenu() {
  // 依角色过滤：非管理员看不到「操作纪录」。这只是介面 ——
  // 那支端点自己也挡（server/middleware/auth.js 的 requireAdmin）。
  el('menu').innerHTML = visibleRoutes(store.isAdmin())
    .map(
      (r) => `<a href="#" data-key="${r.key}" title="${esc(r.path)}"
        class="mitem ${r.key === current ? 'active' : ''}${r.sep ? ' sep' : ''}"
      ><span class="ico">${r.ico}</span>${esc(r.name)}</a>`
    )
    .join('');
  el('menu')
    .querySelectorAll('a')
    .forEach((a) => {
      a.onclick = (e) => {
        e.preventDefault();
        go(a.dataset.key);
      };
    });
}

export function go(key) {
  // 看不到的页面也不能导进去（选单虽然藏了，但 go() 是 export 的）。
  // 真正的权限还是在伺服器端，这里只是不要让画面进到一个注定 403 的地方。
  if (!visibleRoutes(store.isAdmin()).some((r) => r.key === key)) return;
  current = key;
  renderAll();
}

/* 导览序号：连点两个选单时，第一次的 store.ensure 还在等，
   旧的那次醒来就不该再把自己的画面盖上去 —— 只有最新一次算数。
   （用旗标挡住后来者会更糟：侧栏已经指向 B，内容却停在 A。） */
let renderSeq = 0;
export async function renderAll() {
  const seq = ++renderSeq;
  const view = VIEWS[current];
  renderMenu();
  document.title = ROUTES.find((r) => r.key === current).name + ' - 官网内容管理';
  try {
    await store.ensure(['settings', ...(view.needs || [])]);
    if (seq !== renderSeq) return; // 已被更新的一次导览取代
    setModel('settings', store.get('settings'));
    for (const k of view.needs || []) setModel(k, store.get(k));
    el('content').innerHTML = `<div id="errslot"></div>` + view.render();
    el('content').scrollTop = 0;
    if (view.mount) view.mount();
  } catch (err) {
    if (seq !== renderSeq) return;
    el('content').innerHTML = `<div id="errslot"></div><div class="errbox"><b>⚠ 载入失败：${esc(err.message)}</b></div>`;
    console.error(err);
  }
}

/* 重画目前画面但不卷回顶端（views 透过 ui.renderView 呼叫） */
function renderViewOnly() {
  const view = VIEWS[current];
  const scroll = el('content').scrollTop;
  setModel('settings', store.get('settings'));
  for (const k of view.needs || []) setModel(k, store.get(k));
  el('content').innerHTML = `<div id="errslot"></div>` + view.render();
  el('content').scrollTop = scroll;
  if (view.mount) view.mount();
}

/* ============================================================
   全域委派事件
   ============================================================ */
function initEvents() {
  bindRoot(el('content'));
  bindRoot(el('dlgBody'));

  document.addEventListener('click', async (e) => {
    const t = e.target;
    if (!t.closest) return;

    // 图片 lightbox
    const z = t.closest('[data-zoom]');
    if (z) {
      zoom(z.dataset.zoom, z.dataset.zoommeta);
      return;
    }

    // 上传素材
    const up = t.closest('[data-upload]');
    if (up) {
      e.preventDefault();
      const box = up.closest('.asset');
      const input = box && box.querySelector('input[data-bind]');
      if (!input) return;
      const file = await pickFile();
      if (!file) return;
      box.classList.add('busy');
      try {
        const r = await api.upload(file, up.dataset.uploadKind || '');
        input.value = r.url;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        toast(`已上传 ${r.url}（${r.width}×${r.height}）`);
        refreshNear(up);
      } catch (err) {
        toast('上传失败：' + err.message, 'info');
        box.classList.remove('busy');
      }
      return;
    }

    // 内联表格语系切换
    const il = t.closest('[data-inlinelang]');
    if (il) {
      setInlineLang(il.dataset.screen, il.dataset.inlinelang);
      renderViewOnly();
      return;
    }

    // 弹窗语系增减
    if (t.closest('[data-dlglang-add]')) {
      const pick = el('langPick');
      if (pick && pick.value && !dlgLangs.includes(pick.value)) {
        setDlgLangs([...dlgLangs, pick.value]);
        paintDlg();
        toast(`已新增语系：${pick.value}`, 'info');
      }
      return;
    }
    const dl = t.closest('[data-dlglang-del]');
    if (dl) {
      setDlgLangs(dlgLangs.filter((x) => x !== dl.dataset.dlglangDel));
      paintDlg();
      return;
    }

    // 一般动作
    const act = t.closest('[data-act]');
    if (act) {
      e.preventDefault();
      const fn = ACTIONS[act.dataset.act];
      if (!fn) {
        console.warn('未注册的动作', act.dataset.act);
        return;
      }
      await fn(act.dataset.arg, act, e);
    }
  });

  el('mask').onclick = (e) => {
    if (e.target.id === 'mask') closeDlg();
  };
  el('dlgClose').onclick = closeDlg;
  el('imglb').onclick = function () {
    this.classList.remove('on');
  };

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    const lb = el('imglb');
    if (lb.classList.contains('on')) {
      lb.classList.remove('on');
      return;
    }
    closeDlg();
  });

  // 收合侧栏：SEO 设定、游戏商、品牌影像那几张表要横向卷动，收掉侧栏实际多出一整栏
  el('collapseBtn').onclick = () => {
    const root = document.documentElement;
    // 展开时移除 inline property 让它回到 admin.css 的 --sidebar-w 预设值，
    // 不要在这里硬写宽度（会跟 CSS 的值不同步）
    if (root.style.getPropertyValue('--sidebar-w') === '0px') root.style.removeProperty('--sidebar-w');
    else root.style.setProperty('--sidebar-w', '0px');
  };
  el('reloadBtn').onclick = async () => {
    // 重新载入会把草稿整包换掉，未储存的编辑全丢。没有二次确认太容易误按。
    if (store.isDirty && store.isDirty() && !confirm('尚有未储存的修改，重新载入会全部舍弃。确定吗？')) return;
    const view = VIEWS[current];
    clearErrors();
    await store.reload(['settings', ...(view.needs || [])]);
    toast('已重新载入资料', 'info');
    renderViewOnly();
  };
  el('logoutBtn').onclick = async () => {
    if (!confirm('确定要登出吗？')) return;
    await api.logout().catch(() => {});
    location.href = '/admin/login.html';
  };
}

/* ============================================================
   Boot
   ============================================================ */
async function boot() {
  setRenderHooks({ renderView: renderViewOnly, paintDlg });
  initFilePicker();
  initEvents();
  try {
    const s = await store.loadSession();
    el('whoName').textContent = s.user;
    el('avatar').textContent = (s.user || '?').slice(0, 1).toUpperCase();
    el('avatar').title = s.user;
  } catch {
    location.href = '/admin/login.html';
    return;
  }
  await renderAll();
}

/* 除错用。不算新增攻击面 —— 任何能在这个页面执行 JS 的东西，本来就能直接带着
   session cookie 打同一组 /api/admin 端点，挂不挂这个物件都一样。 */
window.__admin = { go, renderAll, store, api, langs, VIEWS };
boot();
