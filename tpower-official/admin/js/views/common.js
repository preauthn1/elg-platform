/* views 共用的小工具 */
import * as store from '../store.js';
import { closeDlg } from '../shell.js';
import { toast, showErrors, esc, renderView, DEFAULT_LANG, langVal } from '../ui.js';

/** 清单型 entity 的新增/编辑：把 record 塞回阵列后 PUT 整包；失败就把阵列还原，弹窗保持开启。 */
export async function saveRecord(key, record, index, { pre = null } = {}) {
  const list = store.get(key);
  const backup = JSON.parse(JSON.stringify(list));
  if (pre) pre(list);
  if (index < 0) list.push(record);
  else list[index] = record;
  const ok = await store.saveFromDialog(key, { closeFn: closeDlg });
  if (!ok) {
    list.length = 0;
    list.push(...backup);
  }
  return ok;
}

/** 列上的即时操作（启用/停用、删除）：直接改 draft 再存档 */
export async function savePatch(key, mutate, { confirmMsg = '', okMsg = '已更新' } = {}) {
  if (confirmMsg && !window.confirm(confirmMsg)) return false;
  const list = store.get(key);
  const backup = JSON.parse(JSON.stringify(list));
  mutate(list);
  const ok = await store.save(key, { okMsg });
  if (!ok) {
    store.replace(key, backup);
    renderView();
  }
  return ok;
}

/** 依既有 id 产生下一个编号，例如 provider-019 */
export function nextId(list, prefix) {
  let max = 0;
  for (const r of list) {
    const m = String(r.id || '').match(/(\d+)$/);
    if (m) max = Math.max(max, parseInt(m[1], 10));
  }
  return `${prefix}-${String(max + 1).padStart(3, '0')}`;
}

/** 依既有 sort 给新列一个排序值 */
export function nextSort(list) {
  return list.reduce((m, r) => Math.max(m, Number(r.sort) || 0), 0) + 1;
}

export function shortText(v, n = 30) {
  const s = String(v ?? '');
  return s.length > n ? s.slice(0, n) + '…' : s;
}

/** 列表一律显示预设语系的值（规格 §2：「预设标题」「预设描述」就是这个意思） */
export function defText(map) {
  return langVal(map, DEFAULT_LANG);
}

/** 某个分类 entity 里「已经存档」的 code 集合。
 *
 *  用途是挡住一个跨卡片的坑：分类与它底下的资料（游戏商／文章）是两张各自
 *  存档的卡，而弹窗的分类选单是从草稿组出来的。于是「新增分类 → 不存档 →
 *  直接去新增游戏商」选得到那个分类，按下储存却被伺服器的外键挡下来。
 *  伺服器验外键读的是磁碟（server/routes/content.js 的 refsFor），看不到草稿。 */
export function savedCodes(key) {
  return new Set((store.saved(key) || []).map((c) => c.code));
}

/** 分类选单的选项文字：还没存档的标出来，别让人以为选了就有。 */
export function catOption(code, text, saved) {
  return saved.has(code) ? text : `${text}（尚未储存）`;
}

/** 存档前确认选到的分类已经存过。没存过就挡下来，并讲清楚要去按哪一颗。 */
export function checkCatSaved(code, saved, cardName) {
  return checkClient(
    !code || saved.has(code),
    `分类「${code}」还没储存，请先按上方「${cardName}」那张卡的「更新储存」，再存这一笔。`
  );
}

export function checkClient(cond, msg) {
  if (cond) return true;
  showErrors('dlg', { message: msg, issues: [] });
  toast(msg, 'info');
  return false;
}

export function slugify(s) {
  return String(s || '')
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 80);
}

export function fmtTime(v) {
  return v ? esc(String(v)) : '<span class="muted">—</span>';
}
