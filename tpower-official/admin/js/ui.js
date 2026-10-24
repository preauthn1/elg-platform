/* 共用 UI 元件 —— 全部沿用原型的 class 与 DOM 结构，只把「假资料」换成「绑到真实 draft 物件」。
   绑定方式：每个输入元素带 data-bind="<model>:<path>"，使用者一打字就写进 draft，
   按「更新储存」时直接 PUT 整包 draft。这样重绘（新增/删除列、切换语系）不会弄丢编辑中的内容。 */

export const el = (id) => document.getElementById(id);
export const esc = (s) =>
  String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

/* ---------------- 语系（实际清单来自 settings entity） ---------------- */
// 必须与 server/schema/content.js 的 LANGS / DEFAULT_LANG / LANG_NAME 同步。
// 识别码是 BCP 47 标签，不是显示名称 —— 理由见 schema/content.js 开头。
export const ALL_LANGS = ['en', 'zh-CN', 'ms'];
// 主语系：必填，也是其他语系留空时前台显示的内容（规格 §2）
export const DEFAULT_LANG = 'en';
/** 识别码 → 画面上要显示的名称。站方看到的是「简体中文」，不是「zh-CN」。 */
export const LANG_NAME = { en: 'English', 'zh-CN': '简体中文', ms: 'Bahasa Melayu' };
/** 显示名（认不出的识别码就原样显示，例如手改进 settings 的新语系） */
export const langName = (l) => LANG_NAME[l] || l;

let siteLangs = ALL_LANGS.slice();
export function setSiteLangs(list) {
  if (Array.isArray(list) && list.length) siteLangs = list.slice();
}
export function langs() {
  return siteLangs;
}

/* 介面上不用「预设语系」这个词——它没有明确所指，站方看了只会问「预设是什么意思」。
   只讲两件事：主语系必填、其他语系留空就显示主语系。 */

/** 某个语系的输入框该给什么提示 */
export function langHint(lang) {
  return lang === DEFAULT_LANG ? '必填' : `留空则显示 ${langName(DEFAULT_LANG)}`;
}


/** 逐语系取值，未翻译则依回退链往下找（与 server/schema/content.js 的 langVal 同义）。
 *  该语系留空就显示主语系（主语系必填，所以一定拿得到值）。 */
export function langVal(field, lang) {
  if (field === null || field === undefined) return '';
  if (typeof field !== 'object' || Array.isArray(field)) return field;
  const v = field[lang];
  if (v !== undefined && String(v).trim() !== '') return v;
  return field[DEFAULT_LANG] ?? '';
}

/* ---------------- toast ---------------- */
export function toast(msg, kind) {
  const t = el('toast');
  t.textContent = msg;
  t.className = 'toast show' + (kind === 'info' ? ' info' : '');
  clearTimeout(t._t);
  t._t = setTimeout(() => {
    t.className = 'toast' + (kind === 'info' ? ' info' : '');
  }, 2600);
}

/* ---------------- model 注册表与路径存取 ---------------- */
const MODELS = { view: null, dlg: null };
export function setModel(name, obj) {
  MODELS[name] = obj;
}
export function getModel(name) {
  return MODELS[name];
}

export function getPath(obj, path) {
  let cur = obj;
  for (const seg of String(path).split('.')) {
    if (cur === null || cur === undefined) return undefined;
    cur = cur[seg];
  }
  return cur;
}

export function setPath(obj, path, value) {
  const segs = String(path).split('.');
  let cur = obj;
  for (let i = 0; i < segs.length - 1; i++) {
    const k = segs[i];
    if (cur[k] === null || typeof cur[k] !== 'object') {
      cur[k] = /^\d+$/.test(segs[i + 1]) ? [] : {};
    }
    cur = cur[k];
  }
  cur[segs[segs.length - 1]] = value;
}

function coerce(raw, type) {
  if (type === 'num') {
    const n = parseFloat(raw);
    return Number.isFinite(n) ? n : 0;
  }
  if (type === 'int') {
    const n = parseInt(raw, 10);
    return Number.isFinite(n) ? n : 0;
  }
  if (type === 'autonum') return raw === 'auto' ? 'auto' : parseInt(raw, 10) || 0;
  if (type === 'bool') return raw === 'true' || raw === true;
  if (type === 'nullstr') return raw === '' ? null : raw;
  return raw;
}

/** 读出某个输入元素目前的值（已依 data-type 转型） */
export function readInput(node) {
  if (node.dataset.rich !== undefined) return node.innerHTML;
  if (node.type === 'checkbox') return node.checked;
  return coerce(node.value, node.dataset.type);
}

function onEdit(e) {
  const node = e.target.closest && e.target.closest('[data-bind]');
  if (!node) return;
  const spec = node.dataset.bind;
  const idx = spec.indexOf(':');
  const model = idx < 0 ? 'view' : spec.slice(0, idx);
  const path = idx < 0 ? spec : spec.slice(idx + 1);
  const target = MODELS[model];
  /* ⚠ 认不出 model 就等于「打了字但没有存到任何地方」，而画面上完全看不出来
     （站方按了更新储存、没有报错、前台没变）。这种 bug 只能靠这行 log 抓到 ——
     少了 model 前缀的 data-bind 是最常见的原因，例如 `a.b.c` 而不是 `page_blocks:a.b.c`。 */
  if (!target) {
    console.error(`[bind] data-bind="${spec}" 的 model「${model}」不存在，这次输入没有存到任何地方`);
    return;
  }
  setPath(target, path, readInput(node));
  if (node.dataset.onedit) {
    const fn = EDIT_HOOKS[node.dataset.onedit];
    if (fn) fn(node, path, target);
  }
}

/** data-onedit="name" 的即时副作用（例如即时数据预览、SEO 标题预览） */
export const EDIT_HOOKS = {};

export function bindRoot(root) {
  root.addEventListener('input', onEdit, true);
  root.addEventListener('change', onEdit, true);
}

/* ---------------- 重绘挂勾（由 app.js 注入） ---------------- */
let renderViewFn = () => {};
let paintDlgFn = () => {};
export function setRenderHooks({ renderView, paintDlg }) {
  renderViewFn = renderView;
  paintDlgFn = paintDlg;
}
export function renderView() {
  renderViewFn();
}
export function paintDlg() {
  paintDlgFn();
}
/** 依元素所在的容器决定要重绘画面还是弹窗 */
export function refreshNear(node) {
  if (node && node.closest('#dlgBody')) paintDlg();
  else renderView();
}

/* ---------------- 错误面板（422 issues 显示在这里，编辑内容不会被清掉） ---------------- */
export function showErrors(where, err) {
  const slot = el(where === 'dlg' ? 'dlgErr' : 'errslot');
  if (!slot) return;
  const issues = (err.issues && err.issues.length ? err.issues : err.problems) || [];
  slot.innerHTML = `<div class="errbox">
    <b>⚠ 储存失败：${esc(err.message)}</b>
    ${issues.length ? `<ul>${issues.map((i) => `<li>${esc(i)}</li>`).join('')}</ul>` : ''}
    <span class="muted" style="font-size:13.5px;color:inherit">您的编辑仍保留在画面上，修正后可再次储存。</span>
  </div>`;
  slot.scrollIntoView({ block: 'nearest' });
}
export function clearErrors(where) {
  const slot = el(where === 'dlg' ? 'dlgErr' : 'errslot');
  if (slot) slot.innerHTML = '';
}

/* ---------------- 小元件（原型逐字沿用） ---------------- */
export function statusTag(on) {
  return on ? '<span class="tag on">启用</span>' : '<span class="tag off">停用</span>';
}

/** 开关。bind 给定时就直接绑到 draft */
export function sw(checked, bind, extra) {
  return `<label class="sw"><input type="checkbox" ${checked ? 'checked' : ''} ${
    bind ? `data-bind="${bind}"` : ''
  } ${extra || ''}><span class="track"></span></label>`;
}


export function selectOf(options, value, bind, style, type, onedit) {
  // 当前值不在选项清单里时补一个 option 显示它（标成「旧值」）。
  // 少了这步，缩减过选项的栏位会变成没有 selected 的 <select>，
  // 使用者一存档就把资料悄悄换成第一个选项。
  const vals = options.map((o) => String(typeof o === 'object' ? o.v : o));
  const cur = value === undefined || value === null ? '' : String(value);
  const extra = vals.includes(cur)
    ? []
    : cur === ''
      ? // 空值也算「不在清单里」。media.src 的空值是合法的，少了这个 option
        // 就没有任何 selected，存档会把它悄悄变成第一个选项。
        [{ v: '', t: '（未指定）' }]
      : [{ v: cur, t: cur + '（旧值，已不建议使用）' }];
  return `<select class="sel" ${style ? `style="${style}"` : ''} ${bind ? `data-bind="${bind}"` : ''} ${
    type ? `data-type="${type}"` : ''
  } ${onedit ? `data-onedit="${onedit}"` : ''}>
    ${[...extra, ...options]
      .map((o) => {
        const v = typeof o === 'object' ? o.v : o;
        const t = typeof o === 'object' ? o.t : o;
        return `<option value="${esc(v)}" ${String(v) === String(value) ? 'selected' : ''}>${esc(t)}</option>`;
      })
      .join('')}
  </select>`;
}

/** 素材缩图：真的把 assetRef 当 src 显示，点击放大 */
export function assetThumb(url, { meta = '', w = 96, h = 62, cls = '' } = {}) {
  if (!url) {
    // 只显示「无」，不画框 —— 这里没有绑上传事件（上传钮在编辑弹窗的 assetInput），
    // 画成「＋」会让人在列表上一直点它
    return `<span class="thumbempty">无</span>`;
  }
  return `<div class="thumbimg ${cls}" style="width:${w}px;height:${h}px"
      data-zoom="${esc(url)}" data-zoommeta="${esc(meta)}" title="点击放大预览">
    <img src="${esc(url)}" alt="${esc(meta)}" onerror="this.style.opacity=.25">
  </div>`;
}

/* 素材栏位：一颗上传钮 + 一个可直接贴路径／网址的输入框。
   两种都吃：站内 /uploads/… 或外部 https://…（schema 的 assetRef 只挡 http 与相对路径）。
   allowUpload:false 给「只能贴连结」的栏位用（上传端点只收图片）。 */
export function assetInput(bind, value, { w = 240, allowUpload = true, ph = '/uploads/xxx.webp 或 https://…', kind = '' } = {}) {
  /* kind 会原样带给上传端点（?kind=…）。目前只有 favicon 一种：
     那条走正方形 PNG 的管线，其余一律 webp，见 server/routes/uploads.js。 */
  const upload = allowUpload
    ? `<button class="btn sm" data-upload${kind ? ` data-upload-kind="${esc(kind)}"` : ''} type="button">上传</button>`
    : '';
  return `<div class="asset"><div class="rowline" style="gap:7px">
      ${upload}
      <input class="inp mono" style="min-width:${Math.max(110, upload ? w - 90 : w)}px;font-size:13.5px"
        value="${esc(value || '')}" data-bind="${bind}" placeholder="${ph}">
    </div></div>`;
}

export function pager(total) {
  return `<div class="pager"><span>共 ${total} 项</span></div>`;
}

/* ---------------- 表格即时过滤 ----------------
   取代原型的「查询」卡片（分类／名称／状态三个下拉 + 一颗查询钮）。
   那张卡是为几百笔设计的，但这个站最多 18 家游戏商、几篇文章、几个活动 ——
   一页就看得完，为了筛选先填三个栏位再按一次钮反而比用眼睛找还慢。

   做法是「隐藏不符的 <tr>」而不是重新渲染：
     - 重新渲染会把 innerHTML 换掉，打字打到一半就失焦，每输入一个字要重点一次
     - 隐藏列不动 DOM 结构，所以列上的 index（编辑／删除要用）也不会跑掉
   比对整列的文字，所以打 SLOT 筛分类、打「停用」筛状态都成立，不必各开一个下拉。 */
export function tableFilter(id, placeholder = '搜寻…') {
  return `<input class="inp sm" id="${esc(id)}" data-tablefilter="${esc(id)}"
    style="min-width:200px" type="search" placeholder="${esc(placeholder)}" autocomplete="off">`;
}

/** 把 data-tablefilter 的输入套到「同一张卡片内」的表格列上。 */
function applyTableFilter(input) {
  const card = input.closest('.card');
  const tbody = card && card.querySelector('table.tbl tbody');
  if (!tbody) return;
  const q = input.value.trim().toLowerCase();
  let shown = 0;
  for (const tr of tbody.rows) {
    const hit = !q || tr.textContent.toLowerCase().includes(q);
    tr.hidden = !hit;
    if (hit) shown++;
  }
  // 「共 N 项」跟着变，否则筛完之后那个数字会说谎
  const count = card.querySelector('.pager span');
  if (count) count.textContent = q ? `符合 ${shown} 项` : `共 ${tbody.rows.length} 项`;
}

document.addEventListener('input', (e) => {
  const f = e.target.closest && e.target.closest('[data-tablefilter]');
  if (f) applyTableFilter(f);
});


/* ---------------- 内联表格的语系切换（单选晶片） ---------------- */
const inlineLang = {};
export function getInlineLang(screen) {
  const l = inlineLang[screen] || DEFAULT_LANG;
  return langs().includes(l) ? l : DEFAULT_LANG;
}
export function setInlineLang(screen, lang) {
  inlineLang[screen] = lang;
}

/* 区块说明是选填：不给 ph 的话 langInput 会在 English 空栏显示「必填」，站方会以为非填不可。 */
export const DESC_OPTIONAL_PH = '选填 · 留空就不显示这段说明';
// 同一件事的其他栏位：眉标、副标这类前台样板留空就不输出的
export const OPTIONAL_PH = '选填 · 留空就不显示';
export const BTN_OPTIONAL_PH = '选填 · 留空就不显示按钮';
export const SECTION_OPTIONAL_PH = '选填 · 留空则不显示此区块';

/** 区块标题（眉标／主标／强调色副标）—— 放在该区块自己的卡里，跟它的内容同一张卡。 */
export function headingFields(bindBase, h = {}, lang) {
  /* ⚠ 四个栏位要跟 schema 的 sectionHeading 对齐（eyebrow / title / sub / desc）。
     少画一个就等于那段文案在后台改不到 —— 前台照样会显示它，站方却找不到在哪改。 */
  const one = (k, label, style, opts = {}) =>
    `<div class="langrow">
      <div class="lname">${label}</div>
      ${langInput(`${bindBase}.${k}`, (h || {})[k], lang, {
        style: 'width:100%' + (style || ''),
        ...opts,
      })}
    </div>`;
  return `<div class="langblk">
    ${one('eyebrow', '英文眉标', ';font-family:var(--mono);font-size:13px', { ph: OPTIONAL_PH })}
    ${one('title', '主标题')}
    ${one('sub', '副标题', '', { ph: OPTIONAL_PH })}
    ${one('desc', '说明文案', '', { area: true, ph: DESC_OPTIONAL_PH })}
  </div>`;
}

export function langChips(screen, note) {
  const cur = getInlineLang(screen);
  return `<div class="rowline" style="gap:9px;flex-wrap:wrap;margin-bottom:10px">
    <span class="muted" style="font-size:14.5px">表格编辑语系</span>
    <div class="chips">
      ${langs()
        .map(
          (l) => `<span class="chip ${l === cur ? 'on' : ''}" data-inlinelang="${esc(l)}" data-screen="${esc(screen)}">
        <span class="dot"></span>${esc(langName(l))}</span>`
        )
        .join('')}
    </div>
    <span class="muted" style="font-size:13.5px">${
      note ||
        `列表显示所选语系的值。${DEFAULT_LANG} 必填；其他语系留空时，前台显示 ${DEFAULT_LANG}。`
    }</span>
  </div>`;
}

/** 内联表格的逐语系输入框（值取自 map[lang]） */
export function langInput(bindBase, map, lang, { area = false, style = '', ph = '', attrs = '' } = {}) {
  const v = (map && map[lang]) || '';
  const placeholder = lang === DEFAULT_LANG ? ph || langHint(lang) : langHint(lang);
  const bind = `${bindBase}.${lang}`;
  if (area) {
    return `<textarea class="inp" style="min-height:52px;font-size:14px;${style}" data-bind="${bind}" ${attrs} placeholder="${esc(
      placeholder
    )}">${esc(v)}</textarea>`;
  }
  return `<input class="inp" style="min-width:0;width:100%;${style}" data-bind="${bind}" ${attrs} value="${esc(
    v
  )}" placeholder="${esc(placeholder)}">`;
}

/* ---------------- 弹窗内的语系管理（可增减，下方栏位跟着展开） ---------------- */
export let dlgLangs = [];
export function setDlgLangs(list) {
  dlgLangs = list.filter((l) => langs().includes(l));
  if (!dlgLangs.includes(DEFAULT_LANG)) dlgLangs.unshift(DEFAULT_LANG);
}
/** 依纪录里「已经有翻译」的语系自动决定要展开哪几种 */
export function langsWithContent(record, fields) {
  const found = new Set([DEFAULT_LANG]);
  for (const f of fields) {
    const map = getPath(record, f);
    if (map && typeof map === 'object') {
      for (const [l, v] of Object.entries(map)) {
        if (Array.isArray(v) ? v.some((x) => String(x).trim()) : String(v ?? '').trim()) found.add(l);
      }
    }
  }
  return langs().filter((l) => found.has(l));
}

export function langManager() {
  const avail = langs().filter((l) => !dlgLangs.includes(l));
  return `<div class="fieldset">
    <div class="fs-t"><span class="req">*</span>内容语系
      <span class="muted" style="font-weight:400;font-size:13.5px">已启用 ${dlgLangs.length} 种，下方每个栏位会依此展开输入框</span>
    </div>
    <div class="chips">
      ${dlgLangs
        .map(
          (l) => `<span class="chip on ${l === DEFAULT_LANG ? 'locked' : ''}">
        <span class="dot"></span>${esc(langName(l))}
        ${l === DEFAULT_LANG ? '' : `<span class="x" data-dlglang-del="${esc(l)}" title="收合此语系">✕</span>`}
      </span>`
        )
        .join('')}
    </div>
    ${
      avail.length
        ? `<div class="rowline">
           <span class="muted" style="font-size:14.5px">新增语言</span>
           <select class="sel" id="langPick" style="min-width:150px">${avail
             // value 一定要明写 —— 少了它，option 的 value 就是显示文字，
             // app.js 读 pick.value 会拿到「简体中文」而不是识别码 zh-CN
             .map((l) => `<option value="${esc(l)}">${esc(langName(l))}</option>`)
             .join('')}</select>
           <button class="btn sm primary" type="button" data-dlglang-add>＋ 新增</button>
           <span class="muted" style="font-size:13.5px">语系清单由全站设定维护（目前启用 ${langs().length} 种）</span>
         </div>`
        : `<div class="muted" style="font-size:14px">已启用全部 ${langs().length} 种语系。</div>`
    }
    <div class="muted" style="font-size:13.5px">
      ${DEFAULT_LANG} 必填。其他语系留空时，前台显示 ${DEFAULT_LANG} 的内容。
      移除晶片只是收合输入框，不会删掉已存的翻译。
    </div>
  </div>`;
}

/** 依 dlgLangs 展开的逐语系栏位。bindBase 例：dlg:title */
export function langFieldsDyn(label, bindBase, map, type, opts = {}) {
  const req = opts.req === false ? '' : '<span class="req">*</span>';
  return `<div class="fieldset">
    <div class="fs-t">${req}${esc(label)}</div>
    <div class="langblk">${dlgLangs
      .map((l) => {
        const v = (map && map[l]) || '';
        const ph = l === DEFAULT_LANG ? opts.ph || langHint(l) : langHint(l);
        let body;
        if (type === 'area') {
          body = `<textarea class="inp" data-bind="${bindBase}.${l}" placeholder="${esc(ph)}">${esc(v)}</textarea>`;
        } else if (type === 'img') {
          body = `<div class="rowline" style="align-items:flex-start;gap:10px">
              ${assetThumb(v, { meta: label + ' · ' + l, w: 74, h: 52 })}
              ${assetInput(`${bindBase}.${l}`, v, { w: 250 })}
            </div>`;
        } else if (type === 'tags') {
          const arr = Array.isArray(v) ? v : [];
          body = `<div class="rowline" style="gap:8px;flex-wrap:wrap">
              ${[0, 1, 2]
                .map(
                  (n) =>
                    `<input class="inp" style="min-width:150px" data-bind="${bindBase}.${l}.${n}" value="${esc(
                      arr[n] || ''
                    )}" placeholder="重点标签 ${n + 1}">`
                )
                .join('')}
            </div>`;
        } else {
          body = `<input class="inp" style="width:100%" data-bind="${bindBase}.${l}" value="${esc(
            v
          )}" placeholder="${esc(ph)}">`;
        }
        return `<div class="langrow">
        <div class="lname">${
          l === DEFAULT_LANG ? `预设: <b>${esc(langName(l))}</b>` : esc(langName(l))
        }</div>
        ${body}
      </div>`;
      })
      .join('')}
    </div>
  </div>`;
}

/* ---------------- 富文本 ----------------
   原型的工具列还有「6 色文字色」与「字级 小/正常/大/特大」，两者都拿掉了：
     颜色 —— 前台的配色是设计站定的，开放站方逐段上色只会让品牌调性走样，
             而且那六色写死在后台、跟 site.css 的变数没有关系，改版就对不上。
     字级 —— fontSize 产生的是 <font size="5">，前台 CSS 完全没有对应规则，
             站方设了看不出差别（真正的阶层是 h2/h3，那要另外做）。
   留下的是四个一定用得到、而且前台一定吃得到的：粗体、斜体、清单、清除格式。 */

/* ---------------- 图片 lightbox ---------------- */
export function zoom(url, meta) {
  el('imglbImg').src = url;
  el('imglbMeta').textContent = meta || '';
  el('imglb').classList.add('on');
}

/* ---------------- 上传 ---------------- */
let uploadResolve = null;
export function pickFile() {
  return new Promise((resolve) => {
    const input = el('hiddenFile');
    uploadResolve = resolve;
    input.value = '';
    input.click();
  });
}
export function initFilePicker() {
  el('hiddenFile').addEventListener('change', (e) => {
    const f = e.target.files && e.target.files[0];
    if (uploadResolve) uploadResolve(f || null);
    uploadResolve = null;
  });
}

/* ---------------- 委派事件：data-act="名称" data-arg="参数" ---------------- */
// 各 view 在模组载入时直接 Object.assign(ACTIONS, {...}) 注册自己的动作
export const ACTIONS = {};

/** 内联表格的新增/删除，含原型的护栏「至少要保留一列」 */
export function addRow(list, tpl, { max = 9999, msg = '已新增一列，记得按「更新储存」' } = {}) {
  if (list.length >= max) {
    toast(`已达上限 ${max} 笔`, 'info');
    return false;
  }
  list.push(structuredClone(tpl));
  toast(msg);
  return true;
}
export function delRow(list, i, { min = 1 } = {}) {
  if (list.length <= min) {
    // min 為 0 時只有空阵列会走到这里，讲「至少要保留 0 列」是句废话
    const msg = min <= 0 ? '没有可删除的列' : min === 1 ? '至少要保留一列' : `至少要保留 ${min} 列`;
    toast(msg, 'info');
    return false;
  }
  list.splice(i, 1);
  toast('已删除，记得按「更新储存」');
  return true;
}
