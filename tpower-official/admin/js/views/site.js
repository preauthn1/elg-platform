/* 全站共用 —— 每一页都会出现的东西：品牌、导览列、页首按钮、页尾。
   这些原本散在别处或根本没有介面（headerCta 与 copyright 只能手改 JSON）。

   刻意不放进来的 settings 栏位：baseUrl / languages / langMeta / redirect ——
   那些是部署设定，改错整站的网址与语系前缀就会坏，而且改动频率是「一辈子一次」。
   维持手改 content.json。 */
import * as store from '../store.js';
import { crumb } from '../shell.js';
import { esc, langChips, getInlineLang, langInput, langName, ACTIONS, assetThumb, assetInput, BTN_OPTIONAL_PH } from '../ui.js';
import { SITE, shown } from '../site.js';

// 站点给的说明字（空字串就不传，langInput 才会照常显示「必填」提示）
const phOf = (s) => (s ? { ph: s } : {});

/** 逐语系的一列（label + 每个语系一个输入框，跟着上方语系晶片切换） */
function langRow(label, bind, map, lang, opts = {}) {
  return `<div class="langrow">
    <div class="lname">${esc(label)}</div>
    ${langInput(bind, map, lang, { style: 'width:100%', ...opts })}
  </div>`;
}

/* logo 留空时前台用的那张（server/lib/render.js 的退路）。缩图要显示它 ——
   显示「无」的话站方会以为前台现在没有 logo。 */
const DEFAULT_LOGO = '/uploads/logo.webp';

export const site = {
  needs: ['site_tagline', 'settings', 'pages'],
  render() {
    const tag = store.get('site_tagline');
    const st = store.get('settings');
    const pages = store.get('pages');
    const lang = getInlineLang('site');
    const cta = st.headerCta || {};

    return `
${crumb('site')}
<div class="card">
  <div class="card-h">品牌
    <div class="right"><button class="btn sm primary" data-act="site.saveTagline">更新储存</button></div>
  </div>
  <div class="card-b">${langChips('site')}</div>
  <div class="card-b">
    <div class="form-row">
      <div class="fitem"><label>品牌名</label><input class="inp" style="min-width:200px"
        data-bind="site_tagline:brand" value="${esc(tag.brand || '')}"><span class="muted" style="font-size:13.5px">各语系共用；也是每页浏览器标题结尾的那个名字</span></div>
    </div>
    <div class="langblk">
      ${langRow('标语', 'site_tagline:slogan', tag.slogan, lang, phOf(SITE.text.sloganPh))}
    </div>
    ${shown('hero.placeholders') ? '<div class="muted" style="font-size:13.5px;margin-top:8px">首屏文字里打 <code>{brand}</code> 会换成这里的品牌名。</div>' : ''}
  </div>
</div>

<div class="card">
  <div class="card-h">Logo 与网站图示
    <div class="right"><button class="btn sm primary" data-act="site.saveSettings">更新储存</button></div>
  </div>
  <div class="card-b">
    <div class="fieldset">
      <div class="fs-t">Logo <span class="muted" style="font-weight:400;font-size:13.5px">页首与页尾共用 · 留空＝使用预设那张</span></div>
      <div class="rowline" style="align-items:flex-start;gap:10px">
        ${assetThumb(st.logo || DEFAULT_LOGO, { meta: 'Logo', w: 160, h: 52, cls: 'contain' })}
        ${assetInput('settings:logo', st.logo, { w: 320 })}
      </div>
    </div>
    <div class="fieldset">
      <div class="fs-t">网站图示（favicon） <span class="muted" style="font-weight:400;font-size:13.5px">浏览器分页与手机主画面上的小图示 · 上传后会自动转成正方形 PNG</span></div>
      <div class="rowline" style="align-items:flex-start;gap:10px">
        ${assetThumb(st.favicon, { meta: '网站图示', w: 52, h: 52, cls: 'contain' })}
        ${assetInput('settings:favicon', st.favicon, { w: 320, kind: 'favicon', ph: '/uploads/fav-xxx.png 或 https://…' })}
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-h">自订程式码
    <div class="right"><button class="btn sm primary" data-act="site.saveSettings">更新储存</button></div>
  </div>
  <div class="card-b">
    <div class="fitem" style="align-items:flex-start;margin-bottom:12px"><label style="padding-top:6px">放在 &lt;head&gt;</label>
      <textarea class="inp mono" style="width:620px;max-width:100%;min-height:120px;font-size:13px" data-bind="settings:headCode"
        placeholder="&lt;meta name=&quot;google-site-verification&quot; content=&quot;xxxxxxxxxxxx&quot; /&gt;">${esc(st.headCode || '')}</textarea>
    </div>
    <div class="fitem" style="align-items:flex-start"><label style="padding-top:6px">放在 &lt;body&gt; 结尾</label>
      <textarea class="inp mono" style="width:620px;max-width:100%;min-height:80px;font-size:13px" data-bind="settings:bodyCode"
        placeholder="&lt;noscript&gt;&lt;iframe src=&quot;https://example.invalid/ns.html?id=GTM-XXXXXXX&quot;&gt;&lt;/iframe&gt;&lt;/noscript&gt;">${esc(st.bodyCode || '')}</textarea>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-h">导览列与页尾 <span class="sub">选单文字 · 11 条路径</span>
    <div class="right"><button class="btn sm primary" data-act="site.savePages">更新储存</button></div>
  </div>
  <div class="card-b flush"><div class="tblwrap"><table class="tbl">
    <thead><tr><th style="width:150px">页面</th><th style="width:170px">路径</th><th>选单文字（${esc(
      langName(lang)
    )}）</th></tr></thead>
    <tbody>${pages
      .map(
        (p, i) => `<tr>
        <td style="font-weight:500">${esc(p.name)}</td>
        <td class="mono muted" style="font-size:13px">${esc(p.path)}</td>
        <td>${langInput(`pages:${i}.navLabel`, p.navLabel, lang, { style: 'width:100%', ph: p.name })}</td>
      </tr>`
      )
      .join('')}
    </tbody>
  </table></div></div>
</div>

<div class="card">
  <div class="card-h">页首按钮与页尾版权
    <div class="right"><button class="btn sm primary" data-act="site.saveSettings">更新储存</button></div>
  </div>
  <div class="card-b">
    <div class="langblk">
      ${langRow(`「${SITE.text.registerLabel}」文字`, 'settings:headerCta.registerLabel', cta.registerLabel, lang, { ph: BTN_OPTIONAL_PH })}
      ${langRow('「使用指南」文字', 'settings:headerCta.guideLabel', cta.guideLabel, lang, { ph: BTN_OPTIONAL_PH })}
      ${langRow('页尾版权', 'settings:copyright', st.copyright, lang, { ph: '选填 · 例：©{year} 品牌名 All rights reserved.' })}
      ${
        shown('settings.footerBlurb')
          ? langRow('页尾简介', 'settings:footerBlurb', st.footerBlurb, lang, {
              area: true,
              ph: '页尾 logo 下方那段文字 · 留空＝沿用首页首屏的描述文案',
            })
          : ''
      }
    </div>
    <div class="form-row" style="margin-top:10px">
      <div class="fitem"><label>「${esc(SITE.text.registerLabel)}」连结</label><input class="inp mono" style="min-width:200px"
        data-bind="settings:headerCta.registerPath" value="${esc(cta.registerPath || '')}"
        placeholder="${esc(SITE.text.registerPh)}"></div>
      <div class="fitem"><label>「使用指南」连结</label><input class="inp mono" style="min-width:200px"
        data-bind="settings:headerCta.guidePath" value="${esc(cta.guidePath || '')}"
        placeholder="/guide 或 https://…"></div>
    </div>
    <div class="muted" style="font-size:13.5px;margin-top:8px">版权文字里的 <code>{year}</code> 会换成当年年份。</div>
  </div>
</div>`;
  },
};

Object.assign(ACTIONS, {
  'site.saveTagline': () => store.save('site_tagline'),
  'site.savePages': () => store.save('pages'),
  'site.saveSettings': () => store.save('settings'),
});
