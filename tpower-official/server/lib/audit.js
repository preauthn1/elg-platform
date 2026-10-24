/* 稽核纪录 —— 每一次内容变更、登入、清快取都留痕。
   存在 content/system.json 的 audit 键（一个阵列，旧在前）。

   用阵列而不是 ndjson，是为了整份原子写入（写 temp 再 rename）。代价是每写一笔
   重写整份 system.json，并且要设上限（store.js 的 AUDIT_KEEP＝2000 笔）。
   稽核与站方内容分成两份档，所以写这里不会让公开页快取失效。 */
import { readSystem, appendAudit } from './store.js';

/** 写一笔稽核纪录
 * @param {string} action 动作，如 'update' / 'restore' / 'purge_cache' / 'login'
 * @param {string} entity 对象，如 'contacts'
 * @param {object} detail 额外资讯（差异摘要、笔数、IP…）
 */
/* 刻意不回 Promise 也不要求呼叫端 await：稽核写入失败不该让使用者的操作跟着失败
   （存档那时已经成功了）。但一定要留 log —— 否则「纪录里没有」会被误读成「没发生过」。 */
export function audit(action, entity, detail = {}, { user = 'system', ip = '' } = {}) {
  const row = { at: new Date().toISOString(), user, ip, action, entity, ...detail };
  Promise.resolve()
    .then(() => appendAudit(row))
    .catch((err) => console.error('[audit] 写入失败，这一笔没有记录到：', JSON.stringify(row), err.message));
  return row;
}

/** 读最近 N 笔（新的在前）。三个筛选条件都是「有给才过滤」。 */
export function readAudit({ limit = 200, entity = null, action = null, user = null } = {}) {
  const list = readSystem('audit', []);
  const rows = Array.isArray(list) ? list : [];
  return rows
    .filter(
      (r) =>
        r &&
        (!entity || r.entity === entity) &&
        (!action || r.action === action) &&
        // 操作者用子字串比对：登入失败那些的 user 是使用者输入的帐号，不一定存在
        (!user || String(r.user || '').toLowerCase().includes(String(user).toLowerCase()))
    )
    .reverse()
    .slice(0, limit);
}

/** 目前纪录里出现过的动作与操作者（给后台的筛选下拉用）。 */
export function auditFacets() {
  const list = readSystem('audit', []);
  const rows = Array.isArray(list) ? list : [];
  const actions = new Set();
  const users = new Set();
  const entities = new Set();
  for (const r of rows) {
    if (!r) continue;
    if (r.action) actions.add(r.action);
    if (r.user) users.add(r.user);
    if (r.entity) entities.add(r.entity);
  }
  return {
    total: rows.length,
    actions: [...actions].sort(),
    users: [...users].sort(),
    entities: [...entities].sort(),
  };
}


/* ---------------- 差异摘要 ----------------
   目标是「站方看得懂自己改了什么」，所以记的是逐栏位的『改成什么』，不是整包内容。

   ⚠ 三道闸门，缺一不可 —— appendAudit 每写一笔就重写整份 system.json，
   而上限是 2000 笔，所以「一笔有多大」会被乘以 2000：
     VALUE_MAX   单一值截断（富文本内文动辄 10 万字）
     CHANGE_MAX  一笔最多列几项
     BYTES_MAX   前两道都过了还是太大时的硬上限（全部栏位都改的存档）
   实测没有 BYTES_MAX 的话，六篇文章一次全改就是 6KB／笔 → 2000 笔 11MB。 */
const VALUE_MAX = 100;
const CHANGE_MAX = 20;
const BYTES_MAX = 1500;

/** 栏位英文名 → 站方看得懂的中文。查不到就原样显示（不猜、不隐藏）。 */
const FIELD_LABEL = {
  title: '标题', title2: '标题二', name: '名称', navLabel: '导览标签', label: '标签',
  sub: '副标题', desc: '说明', excerpt: '摘要', body: '内文', eyebrow: '眉标',
  cta: '按钮文字', ctaVal: '按钮连结',
  bg: '背景素材', cover: '封面', img: '图片', logo: 'Logo', favicon: '网站图示',
  headCode: 'head 程式码', bodyCode: 'body 程式码',
  featuredImage: '重点预览图', tags: '重点标签', featured: '设为重点',
  url: '网址', link: '连结', linkVal: '连结内容', path: '路径',
  slug: '网址代称', cat: '分类', code: '代码', value: '内容', placeholder: '占位文字',
  sort: '排序', on: '状态', end: '结束时间', year: '年份',
  mult: '倍率', base: '基数', unit: '单位', decimals: '小数位', source: '资料来源',
  tickEverySeconds: '跳动间隔', refreshSeconds: '重新取值间隔', damping: '爬升阻尼',
  brandDomain: '品牌网域', brand: '品牌名', slogan: '标语', baseUrl: '网站网址',
  copyright: '版权文字', footerBlurb: '页尾简介', languages: '启用语系', defaultLanguage: '预设语系',
  detailTitle: '细节段标题', image: '配图', page: 'SEO 页名',
  rows: '列', items: '项目', panels: '面板', sections: '段落', categories: '分类',
  headings: '区块标题', enabled: '启用', type: '类型',
};
const LANG_LABEL = { en: 'English', 'zh-CN': '简体中文', ms: 'Bahasa Melayu' };

const labelOf = (k) => FIELD_LABEL[k] || LANG_LABEL[k] || k;

/** 一列资料在站方眼里叫什么：优先用看得懂的名字，退到 id、再退到序号。
 *
 *  ⚠ 是「取第一个拿得到字的」，不是「取第一个真值」。写成
 *      const named = row.name || row.code || ...
 *  的话，name 是逐语系物件时永远为真，后面的 code / label 一个都轮不到 ——
 *  游戏分类与文章分类的 name 正是物件，于是新增一列只会记成「第 6 列」，
 *  看纪录的人完全不知道加了（或删了）什么。 */
function rowLabel(row, i) {
  if (!row || typeof row !== 'object') return `第 ${i + 1} 列`;
  const text = [row.name, row.code, row.path, row.source, row.title, row.label].map(textOf).find(Boolean) || '';
  const id = typeof row.id === 'string' ? row.id : '';
  if (text && id) return `${text}（${id}）`;
  return text || id || `第 ${i + 1} 列`;
}

/** 取一个能显示的字：纯字串直接用，逐语系物件取预设语系（没有就取第一个有字的）。
 *
 *  ⚠ 两种都要吃。分类的 name 是逐语系物件、label 却是纯字串，
 *  只处理其中一种就会有一半的栏位取不到字。 */
function textOf(v) {
  if (typeof v === 'string') return v.trim();
  if (!v || typeof v !== 'object' || Array.isArray(v)) return '';
  const s = typeof v.en === 'string' && v.en.trim() ? v.en : Object.values(v).find((x) => typeof x === 'string' && x.trim());
  return typeof s === 'string' ? s.trim() : '';
}

/** 把任意值变成一行可读文字，并截到 VALUE_MAX */
/* ⚠ raw＝程式码栏位（headCode / bodyCode）：不要滤掉 HTML 标签。
   滤标签原本是给富文本用的，但程式码栏位整段都是标签 —— 贴一行 <meta …> 进来，
   滤完就剩空字串，纪录写成「（空）→（空）」，一笔真的变更就这样被吞掉了。 */
const RAW_KEYS = new Set(['headCode', 'bodyCode']);

function short(v, raw = false) {
  if (v === undefined || v === null || v === '') return '（空）';
  // 空阵列／空物件跟「没有这个栏位」在人眼里是同一件事
  if (typeof v === 'object' && (Array.isArray(v) ? v.length === 0 : Object.keys(v).length === 0)) return '（空）';
  if (typeof v === 'boolean') return v ? '开' : '关';
  let s = typeof v === 'object' ? JSON.stringify(v) : String(v);
  if (!raw) s = s.replace(/<[^>]+>/g, ' ');
  s = s.replace(/\s+/g, ' ').trim();
  if (!s) return '（空）';
  return s.length <= VALUE_MAX ? s : s.slice(0, VALUE_MAX) + '…';
}

const isPlainObject = (v) => v && typeof v === 'object' && !Array.isArray(v);

/** 递回比对，把「路径 → 旧值 / 新值」摊平成一维阵列 */
function walk(before, after, trail, out, raw = false) {
  if (out.length >= CHANGE_MAX) return;
  if (JSON.stringify(before) === JSON.stringify(after)) return;

  if (Array.isArray(before) && Array.isArray(after)) {
    const max = Math.min(before.length, after.length);
    for (let i = 0; i < max; i++) walk(before[i], after[i], trail.concat(rowLabel(after[i], i)), out);
    return; // 新增／删除的列由 diffSummary 另外算，这里只看「同时存在」的
  }
  if (isPlainObject(before) && isPlainObject(after)) {
    for (const k of new Set([...Object.keys(before), ...Object.keys(after)])) {
      // raw 要往下傳：pages[].headCode 是逐语系物件，真正的字串在 headCode.zh-CN 那一层
      walk(before[k], after[k], trail.concat(labelOf(k)), out, raw || RAW_KEYS.has(k));
    }
    return;
  }
  /* 显示出来一模一样的就不记。最常见的是「栏位不存在」变成「空字串」——
     schema 加了一个预设空白的新栏位，下一次存档每个空栏位都会冒出一行「（空）→（空）」。 */
  const from = short(before, raw);
  const to = short(after, raw);
  if (from === to) return;
  out.push({ path: trail.join(' › '), from, to });
}

/** 比较两份资料，产出「改了哪个栏位、改成什么」。整包内容不会进纪录。 */
export function diffSummary(before, after) {
  const changes = [];
  walk(before ?? null, after ?? null, [], changes);

  const out = { changes: changes.slice(0, CHANGE_MAX) };
  if (changes.length > CHANGE_MAX) out.more = true;

  if (Array.isArray(before) || Array.isArray(after)) {
    const b = Array.isArray(before) ? before : [];
    const a = Array.isArray(after) ? after : [];
    out.rowsAfter = a.length;
    if (a.length > b.length) out.addedRows = a.slice(b.length).map((r, i) => rowLabel(r, b.length + i));
    if (b.length > a.length) out.removedRows = b.slice(a.length).map((r, i) => rowLabel(r, a.length + i));
  }

  /* 硬上限要放在最后 —— 放中间的话 rowsAfter / addedRows / removedRows
     是在检查之后才加上去的，整个上限等于白设（删掉 300 列就有 300 个标签）。
     从尾巴砍：先砍 changes，再砍新增／删除的列名。 */
  const tooBig = () => Buffer.byteLength(JSON.stringify(out), 'utf8') > BYTES_MAX;
  for (const k of ['removedRows', 'addedRows']) {
    while (tooBig() && Array.isArray(out[k]) && out[k].length > 1) {
      out[k].pop();
      out.more = true;
    }
  }
  while (tooBig() && out.changes.length > 1) {
    out.changes.pop();
    out.more = true;
  }
  return out;
}
