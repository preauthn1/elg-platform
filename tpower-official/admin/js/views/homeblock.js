/* 首页区块 —— 一个前台区块一张卡，顺序跟 templates/pages/home.njk 一致。
   ⚠ 每个区块的标题放在该区块自己的卡里，不要再收进一张「区块标题」总表。 */
import * as store from '../store.js';
import {
  esc,
  ACTIONS,
  addRow,
  delRow,
  assetThumb,
  assetInput,
  getInlineLang,
  langInput,
  headingFields,
  renderView,
  DESC_OPTIONAL_PH,
  BTN_OPTIONAL_PH,
  SECTION_OPTIONAL_PH,
} from '../ui.js';

const H = () => store.get('home_blocks');
const L = () => getInlineLang('page');

/** 卡头：标题 + 右侧按钮（每张卡都存同一个 home_blocks，所以共用 hb.save） */
function head(title, sub, ops = '') {
  return `<div class="card-h">${esc(title)} <span class="sub">${esc(sub)}</span>
    <div class="right">${ops}<button class="btn sm primary" data-act="hb.save">更新储存</button></div>
  </div>`;
}

/* ---------- 四格导览卡（前台在首屏下方，属于 hero 区段） ---------- */
export function navCardsCard() {
  const lang = L();
  return `
<div class="card">
  ${head('四格导览卡', '首屏下方 · 固定 4 张')}
  <div class="card-b flush"><div class="tblwrap"><table class="tbl">
    <thead><tr><th style="width:62px">编号</th><th style="width:210px">名称</th><th style="width:235px">英文小标</th><th>连结目标</th></tr></thead>
    <tbody>${(H().navCards || [])
      .map(
        (c, i) => `
      <tr>
        <td><input class="inp xs num" data-bind="home_blocks:navCards.${i}.no" value="${esc(c.no)}"></td>
        <td>${langInput(`home_blocks:navCards.${i}.name`, c.name, lang)}</td>
        <td>${langInput(`home_blocks:navCards.${i}.en`, c.en, lang, {
          style: 'font-family:var(--mono);font-size:13px',
        })}</td>
        <td><input class="inp mono" style="min-width:0;width:100%;font-size:13.5px" data-bind="home_blocks:navCards.${i}.link" value="${esc(
          c.link
        )}"></td>
      </tr>`
      )
      .join('')}
    </tbody>
  </table></div></div>
</div>`;
}

/* ---------- 发展历史 OUR JOURNEY ---------- */
export function journeyCard() {
  const lang = L();
  const h = (H().headings || {}).journey;
  return `
<div class="card">
  ${head('发展历史', 'OUR JOURNEY · 时间轴可增减（至少一列）', '<button class="btn sm" data-act="hb.addMilestone">新增</button>')}
  <div class="card-b">${headingFields('home_blocks:headings.journey', h, lang)}</div>
  <div class="card-b flush"><div class="tblwrap"><table class="tbl">
    <thead><tr><th style="width:62px">编号</th><th style="width:270px">标题</th><th>说明文案</th><th style="width:70px">操作</th></tr></thead>
    <tbody>${(H().milestones || [])
      .map(
        (m, i) => `
      <tr>
        <td><input class="inp xs num" data-bind="home_blocks:milestones.${i}.no" value="${esc(m.no)}"></td>
        <td>${langInput(`home_blocks:milestones.${i}.title`, m.title, lang)}</td>
        <td>${langInput(`home_blocks:milestones.${i}.desc`, m.desc, lang, { area: true, ph: DESC_OPTIONAL_PH })}</td>
        <td class="ops"><button class="btn text danger-t" data-act="hb.delMilestone" data-arg="${i}">删除</button></td>
      </tr>`
      )
      .join('')}
    </tbody>
  </table></div></div>
</div>`;
}

/* ---------- 代言人回顾 AMBASSADOR LEGACY ---------- */
export function ambassadorCard() {
  const lang = L();
  const a = H().ambassador || {};
  const h = (H().headings || {}).ambassador;
  return `
<div class="card">
  ${head('代言人回顾', 'AMBASSADOR LEGACY · 1 张大图')}
  <div class="card-b">${headingFields('home_blocks:headings.ambassador', h, lang)}</div>
  <div class="card-b">
    <div class="rowline" style="gap:24px;align-items:flex-start">
      <div style="display:flex;flex-direction:column;gap:7px">
        <span class="muted" style="font-size:14.5px">区块素材（各语系共用）</span>
        ${assetThumb(a.img, { meta: '首页代言人回顾配图', w: 168, h: 126 })}
        ${assetInput('home_blocks:ambassador.img', a.img, { w: 250 })}
      </div>
      <div style="flex:1;min-width:280px;display:flex;flex-direction:column;gap:10px">
        <div class="fitem" style="align-items:flex-start"><label style="padding-top:8px">卡片标题</label>
          ${langInput('home_blocks:ambassador.title', a.title, lang, { ph: SECTION_OPTIONAL_PH })}</div>
        <div class="fitem" style="align-items:flex-start"><label style="padding-top:8px">卡片内文</label>
          ${langInput('home_blocks:ambassador.desc', a.desc, lang, { area: true, ph: DESC_OPTIONAL_PH })}</div>
        <div class="rowline" style="align-items:flex-start">
          <div class="fitem" style="align-items:flex-start"><label style="padding-top:8px">按钮文案</label>
            ${langInput('home_blocks:ambassador.btn', a.btn, lang, { ph: BTN_OPTIONAL_PH })}</div>
          <div class="fitem"><label>连结目标</label><input class="inp mono" style="min-width:200px;font-size:13.5px" data-bind="home_blocks:ambassador.link" value="${esc(
            a.link || ''
          )}"></div>
        </div>
      </div>
    </div>
  </div>
</div>`;
}

/* ---------- 赞助入口 SPONSORSHIP HIGHLIGHTS ---------- */
export function sponsorsCard() {
  const lang = L();
  const h = (H().headings || {}).sponsors;
  return `
<div class="card">
  ${head('赞助入口', 'SPONSORSHIP HIGHLIGHTS · 版面 3 张', '<button class="btn sm" data-act="hb.addSponsor">新增</button>')}
  <div class="card-b">${headingFields('home_blocks:headings.sponsors', h, lang)}</div>
  <div class="card-b flush"><div class="tblwrap"><table class="tbl">
    <thead><tr><th style="width:112px">素材</th><th style="width:290px">素材路径</th><th style="width:230px">卡片标题</th><th>连结目标</th><th style="width:70px">操作</th></tr></thead>
    <tbody>${(H().sponsorCards || [])
      .map(
        (c, i) => `
      <tr>
        <td>${assetThumb(c.img, { meta: '首页赞助卡', w: 96, h: 62 })}</td>
        <td>${assetInput(`home_blocks:sponsorCards.${i}.img`, c.img, { w: 230 })}</td>
        <td>${langInput(`home_blocks:sponsorCards.${i}.title`, c.title, lang)}</td>
        <td><input class="inp mono" style="min-width:0;width:100%;font-size:13.5px" data-bind="home_blocks:sponsorCards.${i}.link" value="${esc(
          c.link
        )}"></td>
        <td class="ops"><button class="btn text danger-t" data-act="hb.delSponsor" data-arg="${i}">删除</button></td>
      </tr>`
      )
      .join('')}
    </tbody>
  </table></div></div>
</div>`;
}

Object.assign(ACTIONS, {
  'hb.save': () => store.save('home_blocks'),
  'hb.addMilestone': () => {
    const h = H();
    const no = String(h.milestones.length + 1).padStart(2, '0');
    if (addRow(h.milestones, { no, title: {}, desc: {} }, { max: 20 })) renderView();
  },
  'hb.delMilestone': (i) => {
    if (delRow(H().milestones, +i, { min: 1 })) renderView();
  },
  'hb.addSponsor': () => {
    if (addRow(H().sponsorCards, { title: {}, link: '', img: '' }, { max: 12 })) renderView();
  },
  'hb.delSponsor': (i) => {
    if (delRow(H().sponsorCards, +i, { min: 1 })) renderView();
  },
});
