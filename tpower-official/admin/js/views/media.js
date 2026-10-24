/* 品牌影像管理（清单 + 即时搜寻 + 编辑弹窗）＋ 版权声明（page_blocks.brand.rights） */
import * as store from '../store.js';
import { openDlg } from '../shell.js';
import {
  esc,
  ACTIONS,
  sw,
  statusTag,
  selectOf,
  assetThumb,
  assetInput,
  langManager,
  langFieldsDyn,
  langsWithContent,
  setDlgLangs,
  setModel,
  pager,
  tableFilter,
  langs,
  DEFAULT_LANG,
  langName,
  langHint,
  DESC_OPTIONAL_PH,
} from '../ui.js';
import { saveRecord, savePatch, nextId, nextSort, defText, shortText } from './common.js';

/* ⚠ 這幾個中文字串是「存進 content.json 的識別碼」，server 端用 z.enum 驗證，
   所以必須維持原樣（繁體）—— 轉成簡體會讓存檔直接 422。
   畫面上要顯示簡體，靠下面的 *_LABEL 對照表（跟語系的 LANG_NAME 同一個做法）。
   之後若要徹底解決，正解是把識別碼換成 ASCII code（像遊戲分類的 SLOT/SPORTS），
   那需要連 schema 與既有資料一起遷移，不在這次範圍。 */
const CATS = ['代言人', '贊助', '品牌', '音樂'];
const CAT_LABEL = { 代言人: '代言人', 贊助: '赞助', 品牌: '品牌', 音樂: '音乐' };
const CAT_TAG = { 代言人: 'info', 贊助: 'warn', 音樂: 'pink' };

export function mediaCards() {
    const list = store.get('media');
    const pb = store.get('page_blocks');
    // 整份画出来，由标题列的搜寻框即时隐藏不符的列（见 ui.js 的 tableFilter）
    // 照「排序」由小到大顯示（跟前台同一個比較式）—— 原本是陣列原順序，
// 站方改了排序卻看不出任何變化，只能去前台對。i 是原索引，編輯／刪除要用。
const rows = list
  .map((r, i) => ({ r, i }))
  .sort((a, b) => (Number(a.r.sort) || 0) - (Number(b.r.sort) || 0) || a.i - b.i);
    const rights = (pb.brand && pb.brand.rights) || { label: '', text: {} };
    return `
<div class="card">
  <div class="card-h">影像列表 <span class="sub">共 ${list.length} 笔</span>
    <div class="right">${tableFilter('mediaQ', '搜寻标题 / 分类')}
      <button class="btn sm primary" data-act="media.new">新增影像</button></div>
  </div>
  <div class="card-b flush"><div class="tblwrap"><table class="tbl">
    <thead><tr>
      <th style="width:90px">ID</th><th style="width:52px">排序</th><th style="width:82px">分类</th>
      <th style="width:70px">封面</th><th style="width:180px">预设标题</th><th>说明文案</th>
      <th style="width:72px">状态</th><th style="width:150px">操作</th>
    </tr></thead>
    <tbody>${rows
      .map(
        ({ r: m, i }) => `
      <tr>
        <td class="mono" style="font-size:13px">${esc(m.id)}</td>
        <td class="num">${esc(m.sort)}</td>
        <td><span class="tag ${CAT_TAG[m.cat] || 'off'}">${esc(CAT_LABEL[m.cat] || m.cat)}</span></td>
        <td>${assetThumb(defText(m.cover), {
          kind: m.url ? 'video' : 'img',
          meta: defText(m.title),
          w: 62,
          h: 38,
        })}</td>
        <td style="font-weight:500">${esc(defText(m.title))}</td>
        <td class="muted">${esc(shortText(defText(m.note), 30))}</td>
        <td>${statusTag(m.on)}</td>
        <td class="ops">
          <button class="btn text" data-act="media.edit" data-arg="${i}">编辑</button>
          <button class="btn text danger-t" data-act="media.toggle" data-arg="${i}">${m.on ? '停用' : '启用'}</button>
          <button class="btn text danger-t" data-act="media.del" data-arg="${i}">删除</button>
        </td>
      </tr>`
      )
      .join('')}
    </tbody>
  </table></div>${pager(rows.length)}</div>
</div>

<div class="card">
  <div class="card-h">版权声明 <span class="sub">键值设定 · 前台 /brand 页尾（资料存于 page_blocks.brand.rights）</span>
    <div class="right"><button class="btn sm primary" data-act="media.saveRights">更新储存</button></div>
  </div>
  <div class="card-b">
    <div class="form-row">
      <div class="fitem"><label>拉丁小标</label><input class="inp mono" style="min-width:220px" data-bind="page_blocks:brand.rights.label" value="${esc(
        rights.label || ''
      )}" placeholder="CONTENT & COPYRIGHT"></div>
    </div>
    <div class="muted" style="font-size:13.5px;margin-bottom:6px">每个语系各一段版权声明；${esc(langName(DEFAULT_LANG))} 留空就不显示，其他语系留空则显示 ${esc(langName(DEFAULT_LANG))}。</div>
    <div class="langblk">
    ${langs()
      .map(
        (l) => `<div class="langrow">
      <div class="lname">${l === DEFAULT_LANG ? `预设: <b>${esc(langName(l))}</b>` : esc(langName(l))}</div>
      <textarea class="inp" style="min-height:52px" data-bind="page_blocks:brand.rights.text.${l}" placeholder="${
          l === DEFAULT_LANG ? '选填 · 留空就不显示版权声明' : langHint(l)
        }">${esc((rights.text && rights.text[l]) || '')}</textarea>
    </div>`
      )
      .join('')}
    </div>
  </div>
</div>`;
}

function mediaDialog(index) {
  const list = store.get('media');
  const isNew = index < 0;
  const rec = isNew
    ? {
        id: nextId(list, 'media'),
        cat: '代言人',
        title: {},
        note: {},
        cover: {},
        url: '',
        sort: nextSort(list),
        on: true,
      }
    : JSON.parse(JSON.stringify(list[index]));

  setModel('dlg', rec);
  setDlgLangs(langsWithContent(rec, ['title', 'note', 'cover']));

  openDlg(`品牌影像管理 - ${isNew ? '新增' : '编辑'}`, {
    step1: () => `
      ${langManager()}
      <div class="form-row">
        <div class="fitem"><label>分类</label>${selectOf(CATS.map((v) => ({ v, t: CAT_LABEL[v] || v })), rec.cat, 'dlg:cat')}</div>
        <div class="fitem"><label>排序</label><input class="inp sm num" data-bind="dlg:sort" data-type="int" value="${esc(
          rec.sort
        )}"></div>
        <div class="fitem"><label>状态</label>${sw(rec.on, 'dlg:on')}</div>
      </div>
`,
    step2: () => `
      ${langFieldsDyn('影像标题', 'dlg:title', rec.title, 'text')}
      ${langFieldsDyn('说明文案', 'dlg:note', rec.note, 'area', { req: false, ph: DESC_OPTIONAL_PH })}
      ${langFieldsDyn('封面图', 'dlg:cover', rec.cover, 'img', { req: false })}
      <div class="fieldset">
        <div class="fs-t">影片</div>
        <div class="form-row">
          <div class="fitem" style="flex:1"><label>影片网址</label>
            ${assetInput('dlg:url', rec.url, { w: 340, allowUpload: false, ph: 'https://youtu.be/… 或 CDN 影片网址' })}
          </div>
        </div>
      </div>`,
    onSave: async () => {
      await saveRecord('media', rec, index);
    },
  });
}

Object.assign(ACTIONS, {
  'media.new': () => mediaDialog(-1),
  'media.edit': (i) => mediaDialog(+i),
  'media.toggle': (i) =>
    savePatch('media', (list) => {
      list[+i].on = !list[+i].on;
    }),
  'media.del': (i) => {
    const m = store.get('media')[+i];
    return savePatch('media', (list) => list.splice(+i, 1), {
      confirmMsg: `确定要删除影像「${defText(m.title)}」吗？`,
      okMsg: '已删除',
    });
  },
  'media.saveRights': () => store.save('page_blocks'),
});
