/* 游戏分类管理（内联表格）＋ 游戏商管理（清单 + 即时搜寻 + 编辑弹窗） */
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
  setModel,
  renderView,
  pager,
  tableFilter,
  DEFAULT_LANG,
  DESC_OPTIONAL_PH,
} from '../ui.js';
import { saveRecord, savePatch, nextId, nextSort, defText, shortText, checkClient, savedCodes, catOption, checkCatSaved } from './common.js';


/* ============================================================
   游戏分类管理
   ============================================================ */
const CAT_TPL = { code: '', label: '', name: {}, sort: 0, on: true };

/* 分类卡。跟游戏商同一页 —— 分类是给游戏商挂的外键，分开两页的话
   「改分类代码会让游戏商变孤儿」这件事看不到彼此。 */
function categoryCard() {
  const cats = store.get('game_categories');
  const provs = store.get('game_providers');
  const lang = getInlineLang('page');
  return `
<div class="card">
  <div class="card-h">游戏分类 <span class="sub">共 ${cats.length} 项</span>
    <div class="right"><button class="btn sm" data-act="gamecat.add">新增</button><button class="btn sm primary" data-act="gamecat.save">更新储存</button></div>
  </div>
  <div class="card-b">

  </div>
  <div class="card-b flush"><div class="tblwrap"><table class="tbl">
    <thead><tr>
      <th style="width:60px">排序</th><th style="width:150px">分类代码</th><th style="width:170px">英文标签</th>
      <th>显示名称（${esc(lang)}）</th><th style="width:100px">游戏商数</th><th style="width:84px">状态</th><th style="width:70px">操作</th>
    </tr></thead>
    <tbody>${cats
      .map(
        (c, i) => `
      <tr>
        <td><input class="inp xs num" data-bind="game_categories:${i}.sort" data-type="int" value="${esc(c.sort)}"></td>
        <td><input class="inp mono" style="min-width:120px" data-bind="game_categories:${i}.code" value="${esc(
          c.code
        )}" placeholder="SLOT"></td>
        <td><input class="inp mono" style="min-width:140px;font-size:13.5px" data-bind="game_categories:${i}.label" value="${esc(
          c.label
        )}" placeholder="SLOT"></td>
        <td>${langInput(`game_categories:${i}.name`, c.name, lang, { ph: '必填 · 例：电子游戏' })}</td>
        <td class="num">${provs.filter((p) => p.cat === c.code).length}</td>
        <td>${sw(c.on, `game_categories:${i}.on`)}</td>
        <td class="ops"><button class="btn text danger-t" data-act="gamecat.del" data-arg="${i}">删除</button></td>
      </tr>`
      )
      .join('')}
    </tbody>
  </table></div></div>
</div>`;
}

Object.assign(ACTIONS, {
  'gamecat.add': () => {
    const list = store.get('game_categories');
    const tpl = { ...CAT_TPL, sort: nextSort(list), name: {} };
    if (addRow(list, tpl, { max: 50 })) renderView();
  },
  'gamecat.del': (i) => {
    if (delRow(store.get('game_categories'), +i, { min: 1 })) renderView();
  },
  'gamecat.save': () => store.save('game_categories'),
});

/* ============================================================
   游戏商管理
   ============================================================ */

const CAT_TAG = { SLOT: 'info', SPORTS: 'warn', LIVE: 'pink' };

/** 游戏页专属的两张卡：分类与游戏商。挂在 views/pages.js 的「游戏内容」画面上。 */
export function gameCards() {
  return categoryCard() + providerCard();
}

function providerCard() {
  const list = store.get('game_providers');
  const cats = store.get('game_categories');
    // 不再预先过滤 —— 整份画出来，由标题列的搜寻框即时隐藏不符的列（见 ui.js 的 tableFilter）
    // 照「排序」由小到大顯示（跟前台同一個比較式）—— 原本是陣列原順序，
// 站方改了排序卻看不出任何變化，只能去前台對。i 是原索引，編輯／刪除要用。
const rows = list
  .map((r, i) => ({ r, i }))
  .sort((a, b) => (Number(a.r.sort) || 0) - (Number(b.r.sort) || 0) || a.i - b.i);
    return `
<div class="card">
  <div class="card-h">游戏商列表 <span class="sub">重点游戏商会显示于前台 FEATURED PROVIDERS 区块</span>
    <div class="right">
      ${tableFilter('provQ', '搜寻名称 / 分类 / 状态')}
      <button class="btn sm primary" data-act="prov.new">新增游戏商</button>
    </div>
  </div>
  <div class="card-b flush"><div class="tblwrap"><table class="tbl">
    <thead><tr>
      <th style="width:96px">ID</th><th style="width:52px">排序</th><th style="width:96px">分类</th>
      <th style="width:70px">Logo</th><th style="width:150px">游戏商名称</th><th>预设描述</th>
      <th style="width:160px">重点标签</th><th style="width:76px">重点</th><th style="width:72px">状态</th><th style="width:150px">操作</th>
    </tr></thead>
    <tbody>${rows
      .map(({ r: p, i }) => {
        const tags = (p.tags && p.tags[DEFAULT_LANG]) || [];
        return `
      <tr>
        <td class="mono" style="font-size:13px">${esc(p.id)}</td>
        <td class="num">${esc(p.sort)}</td>
        <td><span class="tag ${CAT_TAG[p.cat] || 'off'}">${esc(p.cat)}</span></td>
        <td>${assetThumb(p.logo, { meta: p.name + ' Logo', w: 60, h: 34 })}</td>
        <td style="font-weight:600">${esc(p.name)}</td>
        <td class="muted">${esc(shortText(defText(p.desc), 46))}</td>
        <td>${
          tags.filter((t) => String(t).trim()).length
            ? tags.map((t) => `<span class="tag off" style="margin:1px 2px 1px 0">${esc(t)}</span>`).join('')
            : '<span class="muted">—</span>'
        }</td>
        <td>${p.featured ? '<span class="tag warn">重点</span>' : '<span class="muted">—</span>'}</td>
        <td>${statusTag(p.on)}</td>
        <td class="ops">
          <button class="btn text" data-act="prov.edit" data-arg="${i}">编辑</button>
          <button class="btn text danger-t" data-act="prov.toggle" data-arg="${i}">${p.on ? '停用' : '启用'}</button>
          <button class="btn text danger-t" data-act="prov.del" data-arg="${i}">删除</button>
        </td>
      </tr>`;
      })
      .join('')}
    </tbody>
  </table></div>${pager(rows.length)}</div>
</div>`;
}

function providerDialog(index) {
  const list = store.get('game_providers');
  const cats = store.get('game_categories');
  // 草稿里可能有还没存档的分类；选得到但存不了，所以要标出来并挡下来
  const savedCats = savedCodes('game_categories');
  const isNew = index < 0;
  const rec = isNew
    ? {
        id: nextId(list, 'provider'),
        cat: cats[0] ? cats[0].code : '',
        name: '',
        desc: {},
        logo: '',
        featured: false,
        tags: {},
        featuredImage: '',
        sort: nextSort(list),
        on: true,
      }
    : JSON.parse(JSON.stringify(list[index]));

  setModel('dlg', rec);
  setDlgLangs(langsWithContent(rec, ['desc', 'tags']));

  openDlg(`游戏商管理 - ${isNew ? '新增' : '编辑'}`, {
    step1: () => `
      ${langManager()}
      <div class="form-row">
        <div class="fitem"><label>所属分类</label>${selectOf(
          cats.map((c) => ({ v: c.code, t: catOption(c.code, `${c.code} · ${defText(c.name)}`, savedCats) })),
          rec.cat,
          'dlg:cat'
        )}</div>
        <div class="fitem"><label>游戏商名称</label><input class="inp" data-bind="dlg:name" value="${esc(
          rec.name
        )}" placeholder="918KISS"></div>
        <div class="fitem"><label>排序</label><input class="inp sm num" data-bind="dlg:sort" data-type="int" value="${esc(
          rec.sort
        )}"></div>
        <div class="fitem"><label>状态</label>
          <div class="radios">
            <label><input type="radio" name="gst" value="true" data-bind="dlg:on" data-type="bool" ${
              rec.on ? 'checked' : ''
            }><span>启用</span></label>
            <label><input type="radio" name="gst" value="false" data-bind="dlg:on" data-type="bool" ${
              !rec.on ? 'checked' : ''
            }><span>停用</span></label>
          </div>
        </div>
        <div class="fitem"><label class="wide">列为重点游戏商</label>${sw(rec.featured, 'dlg:featured')}</div>
      </div>
`,
    step2: () => `
      ${langFieldsDyn('游戏商描述', 'dlg:desc', rec.desc, 'area', { req: false, ph: DESC_OPTIONAL_PH })}
      <div class="fieldset">
        <div class="fs-t">游戏商 Logo</div>
        <div class="rowline" style="align-items:flex-start;gap:10px">
          ${assetThumb(rec.logo, { meta: rec.name + ' Logo', w: 96, h: 54 })}
          ${assetInput('dlg:logo', rec.logo, { w: 260 })}
        </div>
      </div>
      <div class="fieldset">
        <div class="fs-t">重点游戏商区块 <span class="muted" style="font-weight:400;font-size:13.5px">（开启「列为重点」时，预览图必填）</span></div>
        ${langFieldsDyn('重点标签', 'dlg:tags', rec.tags, 'tags', { req: false })}
        <div class="rowline" style="align-items:flex-start;gap:10px">
          <span class="muted" style="font-size:14.5px;padding-top:8px">重点预览图</span>
          ${assetThumb(rec.featuredImage, { meta: rec.name + ' 重点预览图', w: 96, h: 62 })}
          ${assetInput('dlg:featuredImage', rec.featuredImage, { w: 260 })}
        </div>
      </div>`,
    onSave: async () => {
      if (!checkCatSaved(rec.cat, savedCats, '游戏分类')) return;
      if (rec.featured && !checkClient(!!rec.featuredImage, '列为重点游戏商时，重点预览图必填')) return;
      await saveRecord('game_providers', rec, index);
    },
  });
}

Object.assign(ACTIONS, {
  'prov.new': () => providerDialog(-1),
  'prov.edit': (i) => providerDialog(+i),
  'prov.toggle': (i) =>
    savePatch('game_providers', (list) => {
      list[+i].on = !list[+i].on;
    }),
  'prov.del': (i) => {
    const p = store.get('game_providers')[+i];
    return savePatch(
      'game_providers',
      (list) => list.splice(+i, 1),
      { confirmMsg: `确定要删除游戏商「${p.name}」吗？`, okMsg: '已删除' }
    );
  },
});

