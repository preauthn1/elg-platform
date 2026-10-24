/* 资讯分类管理（内联表格）＋ 品牌资讯管理（清单 + 即时搜寻 + 编辑弹窗 + 富文本内文） */
import * as store from '../store.js';
import { openDlg } from '../shell.js';
import {
  esc,
  ACTIONS,
  addRow,
  delRow,
  sw,
  statusTag,
  selectOf,
  assetThumb,
  assetInput,
  getInlineLang,
  langInput,
  langManager,
  langFieldsDyn,
  langsWithContent,
  setDlgLangs,
  dlgLangs,
  setModel,
  getModel,
  renderView,
  paintDlg,
  toast,
  pager,
  tableFilter,
  DEFAULT_LANG,
  langName,
  OPTIONAL_PH,
  DESC_OPTIONAL_PH,
} from '../ui.js';
import { saveRecord, savePatch, nextId, nextSort, defText, shortText, savedCodes, catOption, checkCatSaved } from './common.js';

/* ============================================================
   资讯分类管理
   ============================================================ */
/* 分类卡。跟文章同一页 —— 分类代码是文章挂的外键，分开两页的话
   「改代码会让文章变孤儿」这件事看不到彼此。 */
function categoryCard() {
  const cats = store.get('article_categories');
  const arts = store.get('articles');
  const lang = getInlineLang('page');
  return `
<div class="card">
  <div class="card-h">资讯分类 <span class="sub">共 ${cats.length} 项</span>
    <div class="right"><button class="btn sm" data-act="artcat.add">新增</button><button class="btn sm primary" data-act="artcat.save">更新储存</button></div>
  </div>
  <div class="card-b flush"><div class="tblwrap"><table class="tbl">
    <thead><tr>
      <th style="width:60px">排序</th><th style="width:150px">分类代码</th><th style="width:180px">英文标签</th>
      <th>显示名称（${esc(lang)}）</th><th style="width:90px">文章数</th><th style="width:84px">状态</th><th style="width:70px">操作</th>
    </tr></thead>
    <tbody>${cats
      .map(
        (c, i) => `
      <tr>
        <td><input class="inp xs num" data-bind="article_categories:${i}.sort" data-type="int" value="${esc(
          c.sort
        )}"></td>
        <td><input class="inp mono" style="min-width:120px" data-bind="article_categories:${i}.code" value="${esc(
          c.code
        )}" placeholder="platform"></td>
        <td><input class="inp mono" style="min-width:150px;font-size:13.5px" data-bind="article_categories:${i}.label" value="${esc(
          c.label
        )}" placeholder="LATEST UPDATE"></td>
        <td>${langInput(`article_categories:${i}.name`, c.name, lang, { ph: '必填 · 例：平台动态' })}</td>
        <td class="num">${arts.filter((a) => a.cat === c.code).length}</td>
        <td>${sw(c.on, `article_categories:${i}.on`)}</td>
        <td class="ops"><button class="btn text danger-t" data-act="artcat.del" data-arg="${i}">删除</button></td>
      </tr>`
      )
      .join('')}
    </tbody>
  </table></div></div>
</div>`;
}

Object.assign(ACTIONS, {
  'artcat.add': () => {
    const list = store.get('article_categories');
    if (addRow(list, { code: '', label: '', name: {}, sort: nextSort(list), on: true }, { max: 50 })) renderView();
  },
  'artcat.del': (i) => {
    if (delRow(store.get('article_categories'), +i, { min: 1 })) renderView();
  },
  'artcat.save': () => store.save('article_categories'),
});

/* ============================================================
   品牌资讯管理
   ============================================================ */
/** 品牌资讯页专属的两张卡：分类与文章列表。 */
export function articleCards() {
  return categoryCard() + listCard();
}

function listCard() {
  const list = store.get('articles');
  const cats = store.get('article_categories');
    // 整份画出来，由标题列的搜寻框即时隐藏不符的列（见 ui.js 的 tableFilter）
    // 照「排序」由小到大顯示（跟前台同一個比較式）—— 原本是陣列原順序，
// 站方改了排序卻看不出任何變化，只能去前台對。i 是原索引，編輯／刪除要用。
const rows = list
  .map((r, i) => ({ r, i }))
  .sort((a, b) => (Number(a.r.sort) || 0) - (Number(b.r.sort) || 0) || a.i - b.i);
    const catName = (code) => {
      const c = cats.find((x) => x.code === code);
      return c ? defText(c.name) : code;
    };
    return `
<div class="card">
  <div class="card-h">资讯列表 <span class="sub">「最新焦点」同时仅一篇有效，设定新的会自动取消旧的</span>
    <div class="right">${tableFilter('artQ', '搜寻标题 / 分类 / 状态')}
      <button class="btn sm primary" data-act="art.new">新增资讯</button></div>
  </div>
  <div class="card-b flush"><div class="tblwrap"><table class="tbl">
    <thead><tr>
      <th style="width:96px">ID</th><th style="width:52px">排序</th><th style="width:96px">分类</th>
      <th style="width:70px">封面</th><th style="width:126px">英文标签</th><th>预设标题 / 摘要</th>
      <th style="width:80px">最新焦点</th><th style="width:72px">状态</th><th style="width:150px">操作</th>
    </tr></thead>
    <tbody>${rows
      .map(
        ({ r: a, i }) => `
      <tr>
        <td class="mono" style="font-size:13px">${esc(a.id)}</td>
        <td class="num">${esc(a.sort)}</td>
        <td><span class="tag off">${esc(catName(a.cat))}</span></td>
        <td>${assetThumb(a.cover, { meta: defText(a.title), w: 62, h: 38 })}</td>
        <td class="mono" style="font-size:13px;color:var(--el-color-primary)">${esc(defText(a.label))}</td>
        <td>
          <div style="font-weight:500">${esc(defText(a.title))}</div>
          <div class="muted" style="font-size:13px;margin-top:2px">${esc(shortText(defText(a.excerpt), 34))}</div>
          <div class="mono muted" style="font-size:12.5px">/${esc(a.slug)}</div>
        </td>
        <td>${a.featured ? '<span class="tag warn">焦点</span>' : '<span class="muted">—</span>'}</td>
        <td>${statusTag(a.on)}</td>
        <td class="ops">
          <button class="btn text" data-act="art.edit" data-arg="${i}">编辑</button>
          <button class="btn text danger-t" data-act="art.toggle" data-arg="${i}">${a.on ? '停用' : '启用'}</button>
          <button class="btn text danger-t" data-act="art.del" data-arg="${i}">删除</button>
        </td>
      </tr>`
      )
      .join('')}
    </tbody>
  </table></div>${pager(rows.length)}</div>
</div>`;
}

function articleDialog(index) {
  const list = store.get('articles');
  const cats = store.get('article_categories');
  // 跟游戏商那边同一个坑：草稿里的分类选得到，但伺服器验外键时看的是磁碟
  const savedCats = savedCodes('article_categories');
  const isNew = index < 0;
  const rec = isNew
    ? {
        id: nextId(list, 'article'),
        slug: '',
        cat: cats[0] ? cats[0].code : '',
        label: {},
        title: {},
        excerpt: {},
        cover: '',
        lead: {},
        sections: [],
        featured: false,
        sort: nextSort(list),
        on: true,
      }
    : JSON.parse(JSON.stringify(list[index]));

  setModel('dlg', rec);
  setDlgLangs(langsWithContent(rec, ['label', 'title', 'excerpt', 'lead']));

  openDlg(`品牌资讯管理 - ${isNew ? '新增' : '编辑'}`, {
    step1: () => `
      ${langManager()}
      <div class="form-row">
        <div class="fitem"><label>分类</label>${selectOf(
          cats.map((c) => ({ v: c.code, t: catOption(c.code, `${defText(c.name)}（${c.code}）`, savedCats) })),
          rec.cat,
          'dlg:cat'
        )}</div>
        <div class="fitem"><label>网址代称</label><input class="inp mono" style="min-width:200px" data-bind="dlg:slug" value="${esc(
          rec.slug
        )}" placeholder="post-001-platform"></div>
        <div class="fitem"><label>排序</label><input class="inp sm num" data-bind="dlg:sort" data-type="int" value="${esc(
          rec.sort
        )}"></div>

        <div class="fitem"><label class="wide">设为最新焦点</label>${sw(rec.featured, 'dlg:featured')}</div>
        <div class="fitem"><label>状态</label>
          <div class="radios">
            <label><input type="radio" name="ast" value="true" data-bind="dlg:on" data-type="bool" ${
              rec.on ? 'checked' : ''
            }><span>启用</span></label>
            <label><input type="radio" name="ast" value="false" data-bind="dlg:on" data-type="bool" ${
              !rec.on ? 'checked' : ''
            }><span>停用</span></label>
          </div>
        </div>
      </div>`,
    step2: () => `
      ${langFieldsDyn('英文标签', 'dlg:label', rec.label, 'text', { req: false, ph: OPTIONAL_PH })}
      ${langFieldsDyn('资讯标题', 'dlg:title', rec.title, 'text')}
      ${langFieldsDyn('摘要（列表卡用）', 'dlg:excerpt', rec.excerpt, 'area', { req: false, ph: DESC_OPTIONAL_PH })}
      <div class="fieldset">
        <div class="fs-t">封面图<span class="muted" style="font-weight:400;font-size:13.5px">各语系共用一张</span></div>
        <div class="rowline" style="gap:12px;align-items:flex-start">
          ${assetThumb(rec.cover, { meta: defText(rec.title), w: 120, h: 74 })}
          ${assetInput('dlg:cover', rec.cover, { w: 300 })}
        </div>
      </div>
      ${langFieldsDyn('开场段落', 'dlg:lead', rec.lead, 'area', { ph: '文章开头那一段（列表卡的摘要另外填）' })}
      <div class="fieldset">
        <div class="fs-t">段落
          <span class="muted" style="font-weight:400;font-size:13.5px">一个小标题配一段说明，前台照顺序显示</span>
          <button class="btn sm" data-act="art.addSec" type="button" style="margin-left:auto">新增段落</button>
        </div>
        ${(rec.sections || [])
          .map(
            (sec, si) => `<div class="fieldset" style="margin-bottom:10px">
          <div class="fs-t">段落 ${si + 1}
            <button class="btn text danger-t" data-act="art.delSec" data-arg="${si}" type="button" style="margin-left:auto">删除</button>
          </div>
          ${langFieldsDyn('小标题', `dlg:sections.${si}.title`, sec.title, 'text', { req: false, ph: OPTIONAL_PH })}
          ${langFieldsDyn('说明', `dlg:sections.${si}.desc`, sec.desc, 'area', { req: false, ph: '选填 · 一行一段' })}
        </div>`
          )
          .join('')}
        ${(rec.sections || []).length ? '' : '<div class="muted" style="font-size:13.5px">还没有段落。只有开场段落也可以。</div>'}
      </div>`,
    onSave: async () => {
      if (!checkCatSaved(rec.cat, savedCats, '资讯分类')) return;
      await saveRecord('articles', rec, index, {
        pre: (arr) => {
          if (!rec.featured) return;
          arr.forEach((a, ix) => {
            if (ix !== index) a.featured = false;
          });
        },
      });
    },
  });
}

Object.assign(ACTIONS, {
  // 段落的增删只动弹窗草稿（dlg model），按「更新储存」才落地
  'art.addSec': () => {
    const rec = getModel('dlg');
    if (!rec) return;
    rec.sections = rec.sections || [];
    if (rec.sections.length >= 20) return toast('段落上限 20 个', 'info');
    rec.sections.push({ title: {}, desc: {} });
    paintDlg();
  },
  'art.delSec': (i) => {
    const rec = getModel('dlg');
    if (!rec || !rec.sections) return;
    rec.sections.splice(+i, 1);
    paintDlg();
  },

  'art.new': () => articleDialog(-1),
  'art.edit': (i) => articleDialog(+i),

  'art.toggle': (i) =>
    savePatch('articles', (list) => {
      list[+i].on = !list[+i].on;
    }),
  'art.del': (i) => {
    const a = store.get('articles')[+i];
    return savePatch('articles', (list) => list.splice(+i, 1), {
      confirmMsg: `确定要删除「${defText(a.title)}」吗？`,
      okMsg: '已删除',
    });
  },
});
