/* 活动管理（清单 + 即时搜寻 + 编辑弹窗）。
   规格：结束时间到期后自动归入「往期回顾」 —— 所以分类是由 end 动态判定的，资料层没有 cat 栏位。 */
import * as store from '../store.js';
import { openDlg } from '../shell.js';
import {
  esc,
  ACTIONS,
  sw,
  statusTag,
  assetThumb,
  assetInput,
  langManager,
  langFieldsDyn,
  langsWithContent,
  setDlgLangs,
  dlgLangs,
  setModel,
  pager,
  tableFilter,
  DEFAULT_LANG,
  langName,
  OPTIONAL_PH,
  DESC_OPTIONAL_PH,
} from '../ui.js';
import { saveRecord, savePatch, nextId, nextSort, defText, shortText, fmtTime } from './common.js';


/** 结束时间到期 → 往期回顾 */
export function promoCat(p) {
  if (!p.end) return '热门活动';
  const end = String(p.end).replace(' ', 'T');
  return new Date(end).getTime() < Date.now() ? '往期回顾' : '热门活动';
}


export function promotionCard() {
    const list = store.get('promotions');
    // 整份画出来，由标题列的搜寻框即时隐藏不符的列（见 ui.js 的 tableFilter）
    // 照「排序」由小到大顯示（跟前台同一個比較式）—— 原本是陣列原順序，
// 站方改了排序卻看不出任何變化，只能去前台對。i 是原索引，編輯／刪除要用。
const rows = list
  .map((r, i) => ({ r, i }))
  .sort((a, b) => (Number(a.r.sort) || 0) - (Number(b.r.sort) || 0) || a.i - b.i);
    return `
<div class="card">
  <div class="card-h">活动列表 <span class="sub">结束时间到期后自动归入「往期回顾」</span>
    <div class="right">${tableFilter('promoQ', '搜寻标题 / 分类 / 状态 / 日期')}
      <button class="btn sm primary" data-act="promo.new">新增活动</button></div>
  </div>
  <div class="card-b flush"><div class="tblwrap"><table class="tbl">
    <thead><tr>
      <th style="width:96px">ID</th><th style="width:52px">排序</th><th style="width:88px">分类</th>
      <th style="width:70px">预设图片</th><th style="width:126px">英文标签</th><th>预设活动标题</th>
      <th style="width:150px">结束时间</th>
      <th style="width:72px">状态</th><th style="width:150px">操作</th>
    </tr></thead>
    <tbody>${rows
      .map(({ r: p, i }) => {
        const cat = promoCat(p);
        return `
      <tr>
        <td class="mono" style="font-size:13px">${esc(p.id)}</td>
        <td class="num">${esc(p.sort)}</td>
        <td>${
          cat === '热门活动' ? '<span class="tag info">热门活动</span>' : '<span class="tag off">往期回顾</span>'
        }</td>
        <td>${assetThumb(defText(p.img), { meta: defText(p.title), w: 62, h: 38 })}</td>
        <td class="mono" style="font-size:13px;color:var(--el-color-primary)">${esc(defText(p.label))}</td>
        <td>
          <div style="font-weight:500">${esc(defText(p.title))}</div>
          <div class="muted" style="font-size:13px;margin-top:2px">${esc(shortText(defText(p.desc), 28))}</div>
        </td>
        <td class="mono" style="font-size:13px">${fmtTime(p.end)}</td>
        <td>${statusTag(p.on)}</td>
        <td class="ops">
          <button class="btn text" data-act="promo.edit" data-arg="${i}">编辑</button>
          <button class="btn text danger-t" data-act="promo.toggle" data-arg="${i}">${p.on ? '停用' : '启用'}</button>
          <button class="btn text danger-t" data-act="promo.del" data-arg="${i}">删除</button>
        </td>
      </tr>`;
      })
      .join('')}
    </tbody>
  </table></div>${pager(rows.length)}</div>
</div>`;
}

function promoDialog(index) {
  const list = store.get('promotions');
  const isNew = index < 0;
  const rec = isNew
    ? {
        id: nextId(list, 'promo'),
        label: {},
        title: {},
        desc: {},
        img: {},
        end: null,
        linkVal: '',
        sort: nextSort(list),
        on: true,
      }
    : JSON.parse(JSON.stringify(list[index]));

  setModel('dlg', rec);
  setDlgLangs(langsWithContent(rec, ['label', 'title', 'desc', 'img']));

  openDlg(`活动管理 - ${isNew ? '新增' : '编辑'}`, {
    step1: () => `
      ${langManager()}
      <div class="form-row">
        <div class="fitem"><label>目前分类</label><span class="tag ${
          promoCat(rec) === '热门活动' ? 'info' : 'off'
        }">${promoCat(rec)}</span></div>
        <div class="fitem"><label>排序</label><input class="inp sm num" data-bind="dlg:sort" data-type="int" value="${esc(
          rec.sort
        )}"></div>
        <div class="fitem"><label>结束时间</label><input class="inp" style="min-width:190px" data-bind="dlg:end" data-type="nullstr" value="${esc(
          rec.end || ''
        )}" placeholder="YYYY-MM-DD HH:mm:ss"></div>
        <div class="fitem"><label>状态</label>
          <div class="radios">
            <label><input type="radio" name="pst" value="true" data-bind="dlg:on" data-type="bool" ${
              rec.on ? 'checked' : ''
            }><span>启用</span></label>
            <label><input type="radio" name="pst" value="false" data-bind="dlg:on" data-type="bool" ${
              !rec.on ? 'checked' : ''
            }><span>停用</span></label>
          </div>
        </div>
      </div>`,
    step2: () => `
      ${langFieldsDyn('英文标签', 'dlg:label', rec.label, 'text', { req: false, ph: OPTIONAL_PH })}
      ${langFieldsDyn('活动标题', 'dlg:title', rec.title, 'text')}
      ${langFieldsDyn('活动描述', 'dlg:desc', rec.desc, 'area', { req: false, ph: DESC_OPTIONAL_PH })}
      <div class="fieldset">
        <div class="fs-t">活动图片</div>
        <div class="langblk">${dlgLangs
          .map(
            (l) => `<div class="langrow">
          <div class="lname">${l === DEFAULT_LANG ? `预设: <b>${esc(langName(l))}</b>` : esc(langName(l))}</div>
          <div class="rowline" style="align-items:flex-start;gap:10px">
            ${assetThumb((rec.img && rec.img[l]) || '', { meta: '活动图片 · ' + l, w: 74, h: 52 })}
            ${assetInput(`dlg:img.${l}`, (rec.img && rec.img[l]) || '', { w: 250 })}
          </div>
        </div>`
          )
          .join('')}</div>
      </div>
      <div class="fieldset">
        <div class="fs-t">详情连结</div>
        <div class="form-row">
          <div class="fitem" style="flex:1"><label>连结</label><input class="inp mono" style="min-width:280px" data-bind="dlg:linkVal" value="${esc(
            rec.linkVal
          )}" placeholder="留空＝不显示按钮。/promotions#welcome 或 https://…"></div>

        </div>
      </div>`,
    onSave: async () => {
      await saveRecord('promotions', rec, index);
    },
  });
}

Object.assign(ACTIONS, {
  'promo.new': () => promoDialog(-1),
  'promo.edit': (i) => promoDialog(+i),
  'promo.toggle': (i) =>
    savePatch('promotions', (list) => {
      list[+i].on = !list[+i].on;
    }),
  'promo.del': (i) => {
    const p = store.get('promotions')[+i];
    return savePatch('promotions', (list) => list.splice(+i, 1), {
      confirmMsg: `确定要删除活动「${defText(p.title)}」吗？`,
      okMsg: '已删除',
    });
  },
});
