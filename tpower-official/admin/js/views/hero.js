/* 首屏卡 —— 一页一张，直接内嵌编辑（不用弹窗）。
   资料是 pages 那 11 笔的其中一笔，用 code 定位；bind 路径走索引，
   所以 pages 的顺序改了也不会绑错（索引是每次 render 现算的）。 */
import * as store from '../store.js';
import { esc, getInlineLang, langInput, assetThumb, assetInput, ACTIONS, OPTIONAL_PH, DESC_OPTIONAL_PH, BTN_OPTIONAL_PH } from '../ui.js';
import { shown } from '../site.js';

/** 这一页在 pages 阵列里的索引。找不到就回 -1（理论上不会，schema 锁死 11 笔）。 */
const indexOf = (code) => store.get('pages').findIndex((p) => p.code === code);

/** 首屏卡。首页的背景素材是首屏主视觉（也是 og:image）。
 *  内页读不读背景看站点样板（hero.innerBg）—— 样板不读还开给站方，等于让他上传永远看不到的图。 */
export function heroCard(code) {
  const i = indexOf(code);
  if (i < 0) return '';
  const p = store.get('pages')[i];
  const lang = getInlineLang('page');
  const F = (k) => `pages:${i}.${k}`;
  const row = (label, k, opts = {}) => `<div class="langrow">
      <div class="lname">${esc(label)}</div>
      ${langInput(F(k), p[k], lang, { style: 'width:100%', ...opts })}
    </div>`;

  return `
<div class="card">
  <div class="card-h">首屏 <span class="sub">${esc(p.path)}</span>
    <div class="right"><button class="btn sm primary" data-act="hero.save">更新储存</button></div>
  </div>
  <div class="card-b">
    <div class="langblk">
      ${row('英文小标', 'eyebrow', { ph: OPTIONAL_PH })}
      ${row('主标题', 'title')}
      ${row('副标题', 'sub', { ph: OPTIONAL_PH })}
      ${row('描述文案', 'desc', { area: true, ph: DESC_OPTIONAL_PH })}
      ${row('按钮文字', 'cta', { ph: BTN_OPTIONAL_PH })}
    </div>
    ${
      !shown('hero.placeholders')
        ? ''
        : `<div class="muted" style="font-size:13.5px;margin-top:8px">
      <code>{brand}</code> 会换成品牌名（上面每个文字栏都认得）；主标题、副标题里用大括号括住的字会显示成强调色，例如 <code>WIN 才是{结果}</code>。${
        code === 'home' ? '首页大字依第一个半形空白切成「关键字＋短句」两段（例：<code>YES 是选择</code>）。' : ''
      }
    </div>`
    }
    <div class="form-row" style="margin-top:10px">
      <div class="fitem" style="flex:1"><label>按钮连结</label><input class="inp mono" style="min-width:280px"
        data-bind="${F('ctaVal')}" value="${esc(p.ctaVal || '')}"
        placeholder="留空＝不显示按钮。#content、/games 或 https://…"></div>

    </div>
    <div class="fieldset" style="margin-top:12px">
      <div class="fs-t">此页自订 head</div>
      <textarea class="inp mono" style="width:100%;min-height:90px;font-size:13px" data-bind="${F('headCode')}.${lang}"
        placeholder="&lt;title&gt;这一页的标题&lt;/title&gt;&#10;&lt;meta name=&quot;description&quot; content=&quot;这一页的说明&quot;&gt;">${esc((p.headCode || {})[lang] || '')}</textarea>
    </div>
    ${
      code !== 'home' && !shown('hero.innerBg')
        ? ''
        : `<div class="fieldset" style="margin-top:12px">
      <div class="fs-t">${
        !shown('hero.innerBg')
          ? '背景素材'
          : `${code === 'home' ? '首屏主视觉' : '背景素材'} <span class="muted" style="font-weight:400;font-size:13.5px">${
              code === 'home' ? '各语系共用 · 也是分享到社群时的预览图' : '各语系共用 · 内页留空就用网站预设背景'
            }</span>`
      }</div>
      <div class="rowline" style="gap:12px;align-items:flex-start">
        ${assetThumb(p.bg, { meta: '首屏背景', w: 170, h: 96 })}
        ${assetInput(F('bg'), p.bg, { w: 250 })}
      </div>
    </div>`
    }
  </div>
</div>`;
}

Object.assign(ACTIONS, {
  'hero.save': () => store.save('pages'),
});
