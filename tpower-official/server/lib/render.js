/* 渲染層 —— 把 content.json 攤成樣板要的 context，回傳一頁 HTML。不碰檔案系統。
   兩條規矩：圖示／插圖對照表放 server/sites/<站>.js 的 decor（版面裝飾，不是站方內容）；
   樣板不寫死文案 —— 介面固定字串放 UI_TEXT（各站差異在 site.uiText），站方文案一律走 content。 */
import nunjucks from 'nunjucks';
import { config } from './config.js';
import { site } from '../sites/index.js';
import { readEntity, contentStamp } from './store.js';
import { langVal, DEFAULT_LANG, LANG_NAME } from '../schema/content.js';
import { buildStatsPayload } from './stats.js';
import { assetUrl } from './assets.js';
import { headOverrides } from './headkeys.js';

const env = new nunjucks.Environment(
  new nunjucks.FileSystemLoader(config.dirs.templates, { noCache: !config.isProd }),
  { autoescape: true, throwOnUndefined: false }
);

/* 靜態資產的網址要帶版號 —— 樣板一律寫 {{ asset('/assets/js/foo.js') }}，
   不要直接寫死路徑。理由見 lib/assets.js。 */
env.addGlobal('asset', assetUrl);

// 補零：{{ loop.index | pad(2) }} → 01
env.addFilter('pad', (v, len) => String(v == null ? '' : v).padStart(len || 2, '0'));

/** 內嵌 <script> 的 JSON：跳脫 </ 避免資料裡的 </script> 提前關閉標籤 */
export function safeInlineJson(obj) {
  return JSON.stringify(obj).replace(/<\//g, '<\\/');
}

function absUrl(baseUrl, p) {
  if (!p) return '';
  if (/^https?:\/\//.test(p)) return p;
  return baseUrl + (p.startsWith('/') ? p : '/' + p);
}

const D = site.decor;
const F = site.features;

/* 生態圖最多放 5 個分類，第 6 格固定給「更多內容」。
   ⚠ 多出來的分類不進這張圖（環形版面只排了 6 個位置）；它們仍然出現在上方的篩選列。 */
const ECOSYSTEM_MAX_NODES = 5;
const CONTACT_GLYPH_FALLBACK = '◆';
// 卡片的修飾 class（contact-card--telegram…），樣式不用依賴中文的「自訂」
const CONTACT_KEY = { Telegram: 'telegram', WhatsApp: 'whatsapp', Email: 'email', LINE: 'line', 自訂: 'custom' };

/* 即時數據卡的單位。/api/stats 的 base/step 已經除過中文計數單位（万+／万元+ = 1e4、
   亿+／亿元+ = 1e8），而那份 payload 所有語系共用 —— 所以非中文語系只能在顯示層再縮放：
   scale 換算量級（万+ → M+ 是 ×0.01）。中文語系 scale=1。「元」只在中文顯示。 */
const STAT_UNIT_DIVISOR = { 無: 1, '万+': 1e4, '亿+': 1e8, '万元+': 1e4, '亿元+': 1e8 };
const STAT_UNIT_LATIN = [
  { min: 1e9, div: 1e9, suffix: 'B+' },
  { min: 1e6, div: 1e6, suffix: 'M+' },
  { min: 1e3, div: 1e3, suffix: 'K+' },
  { min: 0, div: 1, suffix: '+' },
];
function statUnitFor(row, lang) {
  const src = row.unit || '無';

  /* 「無」＝站方在後台選了「不要單位」，那就所有語系都不要。

     ⚠ 以前非中文語系無論站方選什麼都會自動挑一個拉丁量級，於是同一張卡
     中文顯示 76,831,845、英文顯示 76.831446M+ —— 兩個語系長得不一樣，
     而且那個 M+ 是站方沒有選過的東西。 */
  if (src === '無') return { unit: '', scale: 1, extraDecimals: 0 };

  if (lang === 'zh-CN') return { unit: src, scale: 1, extraDecimals: 0 };

  const div = STAT_UNIT_DIVISOR[src] ?? 1;
  const raw = Number(row.base || 0) * div; // 還原成真正的數量級再挑單位
  const pick = STAT_UNIT_LATIN.find((x) => raw >= x.min) || STAT_UNIT_LATIN[STAT_UNIT_LATIN.length - 1];

  /* 有單位就不給小數位。原本會依縮放倍數自動補小數（万+ → M+ 補 2 位、
     無 → M+ 補 6 位），用意是讓爬升在縮小後的數字上仍然看得見，
     但帶單位的數字本來就是概數，配上六位小數只會看起來像壞掉。

     ⚠ 代價：選了單位之後，非中文語系那張卡要累積到跨過一個整單位才會跳一次。
     要它在英文版也一直跳，單位就留「無」。 */
  return { unit: pick.suffix, scale: div / pick.div, extraDecimals: 0 };
}
/** 與 assets/js/stats.js 的 fmt() 同義：千分位 + 固定小數位 */
function formatStat(v, decimals) {
  const d = Math.max(0, Math.min(6, Number(decimals) || 0));
  return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
}

/** 依位置取圖示；清單比資料列短時退回該站的 iconFallback */
function pickIcon(list, i) {
  return (list || [])[i] || D.iconFallback;
}
/** 依位置輪替（超過清單長度就從頭來）；清單是空的回空字串 */
function cycleAt(list, i) {
  return list && list.length ? list[i % list.length] : '';
}

// 導覽與介面字串
// 導覽列：主項 6 個 + 「更多」5 個，對應 spec 的 15 條路徑裡有頁面的那些
const NAV_PRIMARY = ['/', '/advantages', '/promotions', '/games', '/brand', '/contact/channels'];
const NAV_MORE = ['/guide', '/faq', '/partners', '/blog', '/compliance'];

// og:locale 用底線格式（zh_CN），跟 html lang 的 zh-CN 不同，所以另開一張表
const OG_LOCALE = { en: 'en_US', 'zh-CN': 'zh_CN', ms: 'ms_MY' };
// 前台語言選單的顯示名與短碼：site.langFront 沒給名稱的退回 schema 的 LANG_NAME
const langFrontName = (l) => (site.langFront[l] || {}).name || LANG_NAME[l] || l;
const langShort = (l) => (site.langFront[l] || {}).short || l.slice(0, 2).toUpperCase();

// 樣板需要的固定介面字串（不是站方內容，所以放程式裡；要多語就補這張表）
const UI_TEXT = {
  en: { langGlyph: 'EN', ageBadge: '18+',
    mainNav: 'Main navigation',
    more: 'More',
    switchLanguage: 'Change language',
    openMenu: 'Open menu',
    homeHighlights: 'Highlights',
    // 品牌影像
    mediaAll: 'All',
    mediaWatch: 'Watch now',
    mediaSoon: 'More coming soon',
    mediaModalSoon: 'This content is on the way. Stay tuned.',
    contactGo: 'Contact now',
    // 遊戲內容
    gameProviderTabs: 'Game provider categories',
    filterAllLabel: 'ALL',
    filterAllName: 'All',
    viewGames: 'VIEW GAMES',
    viewGamesSub: 'Explore games',
    providerCardAria: 'View the {name} provider profile',
    /* 某個分類底下還沒有遊戲商時顯示的空狀態（設計站的 .provider-empty） */
    providerEmptyTitle: '{cat} content is being added',
    providerEmptyDesc:
      'Provider details will be published in line with the platform integration schedule.',
    featuredVisualAlt: '{cat} content visual',
    contentPreview: 'CONTENT PREVIEW',
    // 品牌資訊
    readMore: 'Read more',
    readArticle: 'Read ',
    allCategories: 'All',
    articleCategories: 'Article categories',
    searchArticles: 'Search insights',
    searchArticlesAria: 'Search brand insights',
    backToBlog: 'Back to insights',
    // 信任與安全
    trustTabs: 'Trust & safety policy categories',
    // 404
    notFoundEyebrow: 'ERROR 404',
    notFoundTitle: 'This page could not be found',
    notFoundDesc: 'The link may have expired, or the page has been moved. Head back to the homepage and continue from there.',
    notFoundCta: 'Back to homepage',
    // 頁尾
    footerKicker: 'POWERING EVERY MOMENT',
    footerExplore: 'Explore {brand}',
    footerBrandStory: 'Brand stories',
    // 遊戲商彈窗（設計站的互動；XD 沒有這一段）
    // 三種彈窗（遊戲商／文章／影像）共用的眉標，設計站三處都是這一行字
    modalEyebrow: '{brand} EXCLUSIVE',
    /* 活動詳情彈窗的條款說明。設計站三張卡的這一段一字不差 —— 它是「條款以正式活動頁
       為準」的聲明，不是某一場活動的內容，所以是介面字串而不是逐張卡的文案。 */
    promoModalNote:
      'Event dates, eligible regions, entry requirements, reward conditions and wagering requirements are subject to the full terms published on the official event page.',
    promoModalOk: 'Got it',
    providerModalBack: 'Back to the line-up',
    insightModalClose: 'Close this story',
    close: 'Close',
  },
  'zh-CN': { langGlyph: '文', ageBadge: '18+',
    mainNav: '主要导航',
    more: '更多',
    switchLanguage: '切换语言',
    openMenu: '打开菜单',
    homeHighlights: '首页重点内容',
    mediaAll: '全部',
    mediaWatch: '立即观看',
    mediaSoon: '更多精彩内容即将上线',
    mediaModalSoon: '精彩内容正在准备中，敬请期待。',
    contactGo: '立即联系',
    gameProviderTabs: '游戏商分类',
    filterAllLabel: 'ALL',
    filterAllName: '全部',
    viewGames: 'VIEW GAMES',
    viewGamesSub: '探索游戏',
    providerCardAria: '查看 {name} 游戏商介绍',
    providerEmptyTitle: '{cat}内容持续更新',
    providerEmptyDesc: '相关游戏商资料将按平台实际接入情况公布。',
    featuredVisualAlt: '{cat}内容视觉',
    contentPreview: 'CONTENT PREVIEW',
    readMore: '查看详情',
    readArticle: '阅读',
    allCategories: '全部',
    articleCategories: '资讯分类',
    searchArticles: '搜索资讯',
    searchArticlesAria: '搜索品牌资讯',
    backToBlog: '返回品牌资讯',
    trustTabs: '信任与安全政策分类',
    notFoundEyebrow: 'ERROR 404',
    notFoundTitle: '找不到这个页面',
    notFoundDesc: '链接可能已失效，或该页面已经迁移。回到首页再继续浏览。',
    notFoundCta: '回到首页',
    footerKicker: 'POWERING EVERY MOMENT',
    footerExplore: '探索 {brand}',
    footerBrandStory: '品牌故事',
    // 三種彈窗（遊戲商／文章／影像）共用的眉標，設計站三處都是這一行字
    modalEyebrow: '{brand} EXCLUSIVE',
    promoModalNote:
      '活动时间、适用地区、参与资格、奖励条件及流水要求，请以正式活动页面公布的完整条款为准。',
    promoModalOk: '知道了',
    providerModalBack: '返回游戏阵容',
    insightModalClose: '关闭内容',
    close: '关闭',
  },
  ms: { langGlyph: 'BM', ageBadge: '18+',
    mainNav: 'Navigasi utama',
    more: 'Lagi',
    switchLanguage: 'Tukar bahasa',
    openMenu: 'Buka menu',
    homeHighlights: 'Kandungan utama',
    mediaAll: 'Semua',
    mediaWatch: 'Tonton sekarang',
    mediaSoon: 'Lagi kandungan akan datang',
    mediaModalSoon: 'Kandungan ini akan menyusul. Nantikan.',
    contactGo: 'Hubungi sekarang',
    gameProviderTabs: 'Kategori penyedia permainan',
    filterAllLabel: 'ALL',
    filterAllName: 'Semua',
    viewGames: 'VIEW GAMES',
    viewGamesSub: 'Jelajah permainan',
    providerCardAria: 'Lihat profil penyedia {name}',
    providerEmptyTitle: 'Kandungan {cat} sedang ditambah',
    providerEmptyDesc:
      'Maklumat penyedia akan diterbitkan mengikut jadual integrasi platform.',
    featuredVisualAlt: 'Visual kandungan {cat}',
    contentPreview: 'CONTENT PREVIEW',
    readMore: 'Baca lanjut',
    readArticle: 'Baca ',
    allCategories: 'Semua',
    articleCategories: 'Kategori berita',
    searchArticles: 'Cari berita',
    searchArticlesAria: 'Cari berita jenama',
    backToBlog: 'Kembali ke berita',
    trustTabs: 'Kategori polisi kepercayaan & keselamatan',
    notFoundEyebrow: 'ERROR 404',
    notFoundTitle: 'Halaman ini tidak ditemui',
    notFoundDesc: 'Pautan mungkin telah tamat tempoh, atau halaman ini telah dipindahkan. Kembali ke laman utama untuk teruskan.',
    notFoundCta: 'Kembali ke laman utama',
    footerKicker: 'POWERING EVERY MOMENT',
    footerExplore: 'Jelajahi {brand}',
    footerBrandStory: 'Kisah jenama',
    // 三種彈窗（遊戲商／文章／影像）共用的眉標，設計站三處都是這一行字
    modalEyebrow: '{brand} EXCLUSIVE',
    promoModalNote:
      'Tempoh acara, wilayah yang layak, kelayakan penyertaan, syarat hadiah dan keperluan perolehan adalah tertakluk kepada terma penuh yang diterbitkan di halaman acara rasmi.',
    promoModalOk: 'Faham',
    providerModalBack: 'Kembali ke senarai permainan',
    insightModalClose: 'Tutup kandungan',
    close: 'Tutup',
  },
};

/** 某語系的介面字串（共用表＋該站覆寫）。給了 brand 就把每個值裡的 {brand} 換成品牌名；
 *  其他占位（{cat}、{name}…）原樣保留，由樣板自己 replace。 */
function ui(lang, brand) {
  const key = UI_TEXT[lang] ? lang : DEFAULT_LANG;
  const base = { ...UI_TEXT[key], ...(site.uiText[key] || {}) };
  if (!brand) return base;
  const out = {};
  for (const [k, v] of Object.entries(base)) {
    out[k] = typeof v === 'string' ? v.split('{brand}').join(brand) : v;
  }
  return out;
}

// 內容讀取與共用小工具
/** 讀取全部內容（一次讀完，渲染多語系時共用） */
export function loadContent() {
  return {
    settings: readEntity('settings'),
    tagline: readEntity('site_tagline'),
    pages: readEntity('pages', []),
    contacts: readEntity('contacts', []),
    homeBlocks: readEntity('home_blocks', {}),
    pageBlocks: readEntity('page_blocks', {}),
    promotions: readEntity('promotions', []),
    gameCategories: readEntity('game_categories', []),
    gameProviders: readEntity('game_providers', []),
    media: readEntity('media', []),
    articleCategories: readEntity('article_categories', []),
    articles: readEntity('articles', []),
  };
}

/** 穩定排序，同 sort 值維持陣列順序。方向依模組語意：
 *  內聯表格（分類、官方聯繫、遊戲商）的 sort 是「位置」小的在前；
 *  清單模組（活動、品牌影像、品牌資訊）的 sort 是「權重」大的在前。 */
/** 依「排序」欄由小到大。停用的列不輸出。
 *  ⚠ 全站一律 asc —— 曾經有三個實體用 desc（文章／活動／影像），
 *  資料就得反著填 10,9,8… 才排得對，於是同一個「排序」欄在不同頁意思相反：
 *  有的頁面 1 在最前面，有的頁面 1 在最後面。站方沒辦法從欄位名猜出是哪一種。 */
function ordered(rows, dir = 'asc') {
  const k = dir === 'desc' ? -1 : 1;
  return (rows || [])
    .filter((r) => r && r.on !== false)
    .map((r, i) => ({ r, i }))
    .sort((a, b) => k * ((a.r.sort || 0) - (b.r.sort || 0)) || a.i - b.i)
    .map((x) => x.r);
}

/** 規格明載時區固定 GMT+8，所以上下架比對一律換算到 GMT+8 再比字串
 *  （欄位本身就是 'YYYY-MM-DD HH:mm:ss' 字面值，字串比較即時間比較）。 */
function stamp(now) {
  return new Date(now.getTime() + 8 * 3600 * 1000).toISOString().slice(0, 19).replace('T', ' ');
}
const notEnded = (v, at) => !v || String(v) >= at;

function langSlug(settings, lang) {
  return (settings.langMeta[lang] || {}).slug || 'en';
}
function langHtml(settings, lang) {
  return (settings.langMeta[lang] || {}).htmlLang || 'en';
}

/** 某個語系下、某條前台路徑的完整網址（含語系前綴） */
function localePath(settings, lang, routePath) {
  const slug = langSlug(settings, lang);
  const p = routePath === '/' ? '' : routePath;
  return '/' + slug + p;
}


/** 內部連結要帶上語系前綴；錨點與外部網址原樣保留 */
function makeResolve(c, lang) {
  return (target) => {
    if (!target) return '';
    if (/^(#|https?:\/\/)/.test(target)) return target;
    return localePath(c.settings, lang, target);
  };
}

/** 區塊標題（眉標＋主標＋紅色副標＋說明） */
function heading(h, lang) {
  if (!h) return null;
  return {
    eyebrow: langVal(h.eyebrow, lang),
    title: langVal(h.title, lang),
    sub: langVal(h.sub, lang),
    desc: langVal(h.desc, lang),
  };
}

/* 首屏文字的占位（site.features.heroPlaceholders 開著才處理）：{brand} 換成品牌名、{文字} 強調色。
   產出純文字（alt、SEO）與 HTML（樣板 | safe）兩種。
   ⚠ 一定要「先轉義、再包標籤」。反過來的話站方打的 < 會原樣進 HTML，就是儲存型 XSS。 */
const HERO_BRACE_RE = /\{([^{}]*)\}/g;

function heroPlain(s, brand) {
  const str = String(s ?? '');
  if (!F.heroPlaceholders) return str;
  return str.replace(HERO_BRACE_RE, (_, inner) => (inner === 'brand' ? brand || '' : inner));
}

function heroHtml(s, brand) {
  if (!F.heroPlaceholders) return escHtml(s);
  return escHtml(s).replace(HERO_BRACE_RE, (_, inner) => {
    if (inner === 'brand') return `<span class="hero-brand">${escHtml(brand || '')}</span>`;
    return inner ? `<em>${inner}</em>` : '';
  });
}

/** 第一個「不在 {…} 裡面」的半形空白；沒有回 -1。大括號裡的空白不能拿來切，否則占位會失效。 */
function lockupSplitAt(raw) {
  const spans = [...raw.matchAll(HERO_BRACE_RE)].map((m) => [m.index, m.index + m[0].length]);
  for (let i = raw.indexOf(' '); i >= 0; i = raw.indexOf(' ', i + 1)) {
    if (!spans.some(([a, b]) => i > a && i < b)) return i;
  }
  return -1;
}

/** 首頁的交錯標題：依第一個半形空白（大括號外的）切成「關鍵字＋短句」，例 'YES 是选择' → { key:'YES', rest:'是选择' }。 */
function lockupPart(s, brand) {
  const raw = String(s ?? '').trim();
  const i = lockupSplitAt(raw);
  const key = i < 0 ? '' : raw.slice(0, i);
  const rest = i < 0 ? raw : raw.slice(i + 1).trim();
  return {
    key: heroPlain(key, brand),
    keyHtml: heroHtml(key, brand),
    rest: heroPlain(rest, brand),
    restHtml: heroHtml(rest, brand),
  };
}

// 匯出給驗證腳本用
export { heroPlain, heroHtml, lockupPart };

function heroFrom(brand, h) {
  const title = h.title || '';
  const sub = h.sub || '';
  return {
    eyebrow: heroPlain(h.eyebrow, brand),
    title: heroPlain(title, brand),
    titleHtml: heroHtml(title, brand),
    sub: heroPlain(sub, brand),
    subHtml: heroHtml(sub, brand),
    desc: heroPlain(h.desc, brand),
    cta: heroPlain(h.cta, brand),
    ctaUrl: h.ctaUrl || '',
    // 首頁是主視覺；內頁是首屏背景（空字串＝樣板退回預設）
    bg: h.bg || '',
  };
}

/** pages 的一列 → hero context。bgRow：背景圖從哪一列拿（品牌子頁沿用 pages[brand]、往期回顧沿用 pages[promotions]）。 */
function heroOf(c, row, lang, resolve, bgRow) {
  const p = row || {};
  return heroFrom(c.tagline.brand, {
    eyebrow: langVal(p.eyebrow, lang),
    title: langVal(p.title, lang),
    sub: langVal(p.sub, lang),
    desc: langVal(p.desc, lang),
    cta: langVal(p.cta, lang),
    ctaUrl: resolve(p.ctaVal),
    bg: (bgRow || p).bg,
  });
}

/** 收尾行動區（advantages.final / games.final / partners.finalCta） */
function ctaBlockOf(block, lang, resolve) {
  const b = block || {};
  const url = resolve(b.ctaVal);
  return {
    eyebrow: langVal(b.eyebrow, lang),
    title: langVal(b.title, lang),
    title2: langVal(b.title2, lang),
    sub: langVal(b.sub, lang),
    desc: langVal(b.desc, lang),
    cta: langVal(b.cta, lang),
    btn: langVal(b.btn, lang),
    ctaUrl: url,
    link: url,
  };
}

function pageRow(c, code) {
  return (c.pages || []).find((p) => p.code === code) || {};
}

function titleDescRows(rows, lang, icons) {
  return (rows || []).map((r, i) => ({
    title: langVal(r.title, lang),
    desc: langVal(r.desc, lang),
    icon: pickIcon(icons, i),
  }));
}

// 版面層 context
/** meta 全自動，沒有後台設定：
 *    title    =「頁名 | 品牌」，頁名取自 pages 的導覽標籤（子頁與文章由 fallback 帶入）
 *    desc     = 該頁首屏的說明文案，截到 200 字以內
 *    og:image = 首頁的首屏素材 */
function metaDesc(s) {
  const t = String(s || '').replace(/\s+/g, ' ').trim();
  return t.length <= 200 ? t : t.slice(0, 199).replace(/\s\S*$/, '') + '…';
}

function buildSeo(c, lang, routePath, fallback) {
  const brand = c.tagline.brand;
  const row = (c.pages || []).find((p) => p.path === routePath) || {};
  const fb = fallback || {};
  // SEO 一律用純文字版，不能出現大括號
  const page = heroPlain(langVal(fb.page, lang) || langVal(row.navLabel, lang) || row.name || '', brand);
  /* 標題格式固定「品牌 頁名 | 品牌」—— 這是設計站原本的樣子，不要改成別的排列。
     例外：頁名本身已含品牌名時（文章標題尤其）去掉開頭那段，
     否則會變成「TPOWER222 TPOWER 持续优化… | TPOWER222」。 */
  const title = !page
    ? brand
    : page.includes(brand)
      ? `${page} | ${brand}`
      : `${brand} ${page} | ${brand}`;
  return {
    title,
    desc: metaDesc(heroPlain(langVal(fb.desc, lang) || langVal(row.desc, lang), brand)),
    ogImage: absUrl(c.settings.baseUrl, pageRow(c, 'home').bg || ''),
  };
}

function buildNav(c, lang, currentPath) {
  const byPath = new Map((c.pages || []).map((p) => [p.path, p]));
  const label = (routePath) => {
    const p = byPath.get(routePath);
    return p ? langVal(p.navLabel, lang) || p.name : routePath;
  };
  // 子頁（/brand/films、/blog/<slug>、/guide/login…）要讓所屬主項亮起來
  const isCurrent = (routePath) =>
    routePath === currentPath || (routePath !== '/' && String(currentPath).startsWith(routePath + '/'));
  const item = (routePath) => ({
    url: localePath(c.settings, lang, routePath),
    label: label(routePath),
    current: isCurrent(routePath),
  });
  const primary = NAV_PRIMARY.map(item);
  const more = NAV_MORE.map(item);
  // 「更多」本身也要亮：CSS 有 .desktop-more>summary.active（紅色漸層底＋陰影），
  // 設計站在當前頁落在 more 那五項時會給 summary 加 active。
  // 少了它，/guide /faq /partners /blog /compliance 五頁的導覽完全不亮，看不出在哪一區。
  return { primary, more, moreActive: more.some((x) => x.current), all: primary.concat(more) };
}

/** 各語系的替代連結（hreflang 與語言切換共用） */
function buildAlternates(c, lang, routePath) {
  return (c.settings.languages || []).map((l) => ({
    lang: l,
    name: langFrontName(l),
    mark: langShort(l),
    short: langShort(l),
    adminName: LANG_NAME[l] || l,
    htmlLang: langHtml(c.settings, l),
    ogLocale: OG_LOCALE[l] || '',
    path: localePath(c.settings, l, routePath),
    url: c.settings.baseUrl + localePath(c.settings, l, routePath),
    current: l === lang,
  }));
}

/** 頁尾三欄對應設計站的 .footer-links：
 *    探索 <品牌>  → 六條主要路徑，標籤取自 pages
 *    品牌故事     → 四個品牌影像子頁，標籤取自 page_blocks.brand.categories 的 page
 *    信任與安全   → 四個政策面板，標籤與錨點取自 page_blocks.compliance.panels */
function buildFooter(c, lang) {
  const brand = c.tagline.brand;
  const t = ui(lang, brand);
  const byPath = new Map((c.pages || []).map((r) => [r.path, r]));
  const label = (p) => {
    const r = byPath.get(p);
    return (r && (langVal(r.navLabel, lang) || r.name)) || p;
  };
  const link = (p) => ({ url: localePath(c.settings, lang, p), label: label(p) });
  const year = new Date().getFullYear();
  const panels = ((c.pageBlocks || {}).compliance || {}).panels || [];

  return {
    // 裝飾眉標；有的站拿品牌標語（留空退回 UI_TEXT）
    kicker: (F.footerKickerFromSlogan && langVal(c.tagline.slogan, lang)) || t.footerKicker,
    // 留空退回首頁首屏的描述文案
    blurb: heroPlain(langVal(c.settings.footerBlurb, lang) || langVal(pageRow(c, 'home').desc, lang), brand),
    // {year} 換成當年；順手也支援 {brand}
    copyright: String(langVal(c.settings.copyright, lang) || '')
      .split('{year}')
      .join(year)
      .split('{brand}')
      .join(brand),
    groups: [
      {
        title: t.footerExplore,
        items: ['/advantages', '/promotions', '/games', '/brand', '/guide', '/faq'].map(link),
      },
      {
        title: t.footerBrandStory,
        // page 是子頁的完整名稱（代言人作品），label 是篩選晶片用的短標（代言人）
        items: brandCategories(c).map((x) => ({
          url: localePath(c.settings, lang, x.path),
          label: langVal(x.page, lang) || langVal(x.label, lang),
        })),
      },
      {
        title: label('/compliance'),
        items: panels.map((p) => ({
          url: localePath(c.settings, lang, '/compliance') + '#' + p.code,
          label: langVal(p.title, lang),
        })),
      },
    ],
  };
}

function buildHeaderActions(c, lang) {
  const cta = c.settings.headerCta || {};
  const reg = langVal(cta.registerLabel, lang);
  const gui = langVal(cta.guideLabel, lang);
  /* 用 makeResolve 不是 localePath —— 這兩顆按鈕可以指到外部網址（例如遊戲站），
     localePath 會無腦加語系前綴，把 https://… 變成 /cn https://…。
     resolve 自己判斷：# 與 http(s):// 原樣保留，站內路徑才加前綴。 */
  const resolve = makeResolve(c, lang);
  return {
    register: reg ? { url: resolve(cta.registerPath || ''), label: reg } : null,
    guide: gui ? { url: resolve(cta.guidePath || ''), label: gui } : null,
  };
}

function baseContext(c, lang, routePath, seoFallback) {
  /* 这一页自己的 head 程式码（后台每页首屏卡上那一格）。逐语系、不退回英文。
     只有 pages 那 11 页有；文章、品牌子页这些路径找不到对应的 row，就是空的。 */
  const pageRowHere = (c.pages || []).find((p) => p.path === routePath) || {};
  const pageHeadCode = String((pageRowHere.headCode || {})[lang] || '');
  /* 站方贴的（共用的＋这一页的）有哪些标签，head.njk 就跳过同名的自动标签。
     站方的标题／描述文字也拿来喂 og:title 这些 —— 没贴 og:title 时分享预览才会跟着换。 */
  const ov = headOverrides(c.settings.headCode, pageHeadCode);
  const seo = buildSeo(c, lang, routePath, seoFallback);
  return {
    pageHeadCode,
    headOverride: ov.keys,
    lang,
    htmlLang: langHtml(c.settings, lang),
    /* logo 留空就退回 seed 灌進去的那張原圖 —— 站方還沒上傳過的站台照樣有 logo。
       favicon 留空則保持空字串，head.njk 看到空的就不輸出 <link>。 */
    settings: { ...c.settings, logo: c.settings.logo || '/uploads/logo.webp' },
    brand: c.tagline.brand,
    tagline: { slogan: langVal(c.tagline.slogan, lang) },
    home: localePath(c.settings, lang, '/'),
    canonical: c.settings.baseUrl + localePath(c.settings, lang, routePath),
    xDefaultUrl: c.settings.baseUrl + localePath(c.settings, c.settings.defaultLanguage, routePath),
    alternates: buildAlternates(c, lang, routePath),
    ogLocale: OG_LOCALE[lang] || '',
    currentLang: {
      mark: langShort(lang),
      short: langShort(lang),
      name: langFrontName(lang),
    },
    nav: buildNav(c, lang, routePath),
    actions: buildHeaderActions(c, lang),
    footer: buildFooter(c, lang),
    seo: { ...seo, title: ov.title || seo.title, desc: ov.description || seo.desc },
    t: ui(lang, c.tagline.brand),
  };
}

// 首頁
export async function buildHomeContext(c, lang, { now = new Date() } = {}) {
  const page = pageRow(c, 'home');
  const hb = c.homeBlocks || {};
  const stats = await buildStatsPayload();
  const resolve = makeResolve(c, lang);

  return {
    ...baseContext(c, lang, '/'),
    hero: {
      ...heroOf(c, page, lang, resolve),
      /* 首頁首屏的交錯大字：主標與副標各自依第一個半形空白切成「關鍵字＋短句」 */
      lockup: {
        title: lockupPart(langVal(page.title, lang), c.tagline.brand),
        sub: lockupPart(langVal(page.sub, lang), c.tagline.brand),
      },
    },
    headings: {
      stats: heading((hb.headings || {}).stats, lang),
      journey: heading((hb.headings || {}).journey, lang),
      ambassador: heading((hb.headings || {}).ambassador, lang),
      sponsors: heading((hb.headings || {}).sponsors, lang),
    },
    navCards: (hb.navCards || []).map((x) => ({
      no: x.no,
      name: langVal(x.name, lang),
      en: langVal(x.en, lang),
      link: resolve(x.link),
    })),
    milestones: (hb.milestones || []).map((m, i) => ({
      no: m.no,
      title: langVal(m.title, lang),
      desc: langVal(m.desc, lang),
      icon: cycleAt(D.journeyIcons, i),
    })),
    ambassador: hb.ambassador
      ? {
          title: langVal(hb.ambassador.title, lang),
          desc: langVal(hb.ambassador.desc, lang),
          btn: langVal(hb.ambassador.btn, lang),
          link: resolve(hb.ambassador.link),
          img: hb.ambassador.img,
        }
      : {},
    sponsorCards: (hb.sponsorCards || []).map((x) => ({
      title: langVal(x.title, lang),
      link: resolve(x.link),
      img: x.img,
    })),
    stats: {
      ...stats,
      /* 內容版號。前台把它接在 /api/stats 後面當查詢字串 ——
         存檔後版號一換，網址就是新的，瀏覽器與 CDN 的舊快取自然失效。
         少了它：那支端點帶 max-age=60，改完跳動秒數之後 F5 也還是舊的 step，
         要等一分鐘或手動清快取。 */
      v: contentStamp(),
      rows: stats.rows.map((r) => {
        const u = statUnitFor(r, lang);
        // ⚠ 用 current 不是 base —— base 是爬升的起点，current 才是现在该显示的值
        const formatted = formatStat(r.current * u.scale, r.decimals + u.extraDecimals);
        return {
          ...r,
          title: langVal(r.title, lang),
          // 單位是資料層的中文計數單位（万+/亿+/万元+/亿元+）。中文照原樣，其他語系換算成
          // 該語系看得懂的量級 —— 除數在 /api/stats 那份 payload 裡是共用的，
          // 所以換算只做在「顯示」這一層，縮放係數隨頁面（＝隨語系）帶給 stats.js。
          unit: u.unit,
          unitScale: u.scale,
          unitDecimals: r.decimals + u.extraDecimals,
          formatted,
          /* 說明文案的 {value} 換成一個 <span class="stat-desc-num">，assets/js/stats.js
             才有東西可以更新 —— 原本是直接把數字拼進純文字裡，於是大數字每 15 秒跳一次、
             下面這句永遠停在開頁那一刻的值，兩個數字愈差愈多。

             ⚠ 這裡開始輸出 HTML，所以樣板那側要 | safe，而文案本身（站方可編輯）
             一定要自己 escHtml —— 少了它就是一個儲存型 XSS。 */
          desc: String(langVal(r.desc, lang) || '')
            .split('{value}')
            .map((part) => escHtml(part))
            .join(statValueHtml(formatted, u.unit)),
          icon: D.statIcons[r.source] || 'users-round',
          numLen: D.statNumLen ? D.statNumLen(formatted) : 0,
          art: D.statArt ? D.statArt[r.source] || D.statArt['註冊會員數'] : '',
        };
      }),
    },
    now,
  };
}

/** 數據卡說明裡 {value} 換成的那段 HTML。statDescWrap：外層 .stat-desc-val 讓「數字＋單位」不被拆成兩行 */
function statValueHtml(formatted, unit) {
  const html = `<span class="stat-desc-num">${escHtml(formatted)}</span>${escHtml(unit || '')}`;
  return F.statDescWrap ? `<span class="stat-desc-val">${html}</span>` : html;
}

// 核心優勢
export function buildAdvantagesContext(c, lang) {
  const pb = (c.pageBlocks || {}).advantages || {};
  const resolve = makeResolve(c, lang);

  const items = (pb.items || []).map((a) => {
    const icons = D.advantageVisualIcons[a.variant] || {};
    const v = a.visual || {};
    const title = langVal(a.title, lang);
    return {
      id: a.id,
      variant: a.variant,
      icon: D.advantageIcons[a.id] || D.iconFallback,
      art: (D.advantageArt || {})[a.id] || '',
      en: a.en,
      title,
      detailTitle: langVal(a.detailTitle, lang) || title, // 留空＝沿用 title
      summary: langVal(a.summary, lang),
      subtitle: langVal(a.subtitle, lang),
      desc: langVal(a.desc, lang),
      tags: langVal(a.tags, lang) || [],
      short: langVal(a.short, lang),
      img: a.img || '', // 細節段背景圖，空字串＝只有底色

      visual: {
        coreIcon: icons.core || '',
        coreLabel: v.coreLabel || '',
        coreState: v.coreState || '',
        coreNote: v.coreNote || '',
        headIcon: icons.core || '',
        headLabel: v.headLabel || '',
        status: (v.status || []).map((s, i) => ({
          icon: pickIcon(icons.status, i),
          label: langVal(s.label, lang),
        })),
        steps: (v.steps || []).map((s, i) => ({
          icon: pickIcon(icons.steps, i),
          label: langVal(s.label, lang),
        })),
        layers: (v.layers || []).map((s, i) => ({
          icon: pickIcon(icons.layers, i),
          label: langVal(s.label, lang),
        })),
        /* advantageGridFromTags：「豐富遊戲」的方格沒有自己的資料時，拿 tags 攤成 items
           （左邊那排標籤與右邊方格是同一份清單，不在資料層存兩份）。
           另一種做法是每格自己有圖（items[].img），那就不能從 tags 攤開。 */
        items: (v.items && v.items.length
          ? v.items.map((s) => ({
              title: langVal(s.title, lang),
              desc: langVal(s.desc, lang),
              label: langVal(s.label, lang),
              img: s.img || '',
            }))
          : a.variant === 'gameCategories' && F.advantageGridFromTags
            ? (langVal(a.tags, lang) || []).map((label) => ({ title: '', desc: '', label }))
            : []
        ).map((s, i) => ({ ...s, icon: pickIcon(icons.items, i), state: v.rowState || '' })),
        footerIcon: icons.footer || '',
        footer: langVal(v.footer, lang),
      },
    };
  });

  return {
    ...baseContext(c, lang, '/advantages'),
    hero: heroOf(c, pageRow(c, 'advantages'), lang, resolve),
    headings: {
      overview: heading((pb.headings || {}).overview, lang),
      ecosystem: heading((pb.headings || {}).ecosystem, lang),
    },
    advantages: items,
    ecosystem: {
      coreLabel: (pb.ecosystem || {}).coreLabel || '',
    },
    final: ctaBlockOf(pb.final, lang, resolve),
  };
}

// 遊戲內容
export function buildGamesContext(c, lang) {
  const pb = (c.pageBlocks || {}).games || {};
  const resolve = makeResolve(c, lang);

  const cats = ordered(c.gameCategories, 'asc');
  const catByCode = new Map(cats.map((x) => [x.code, x]));
  const catText = (code) => {
    const x = catByCode.get(code);
    return { label: x ? x.label : code, name: x ? langVal(x.name, lang) : code };
  };

  const providerRows = ordered(c.gameProviders, 'asc');
  /* 哪些分類底下一個遊戲商都沒有。點那顆分頁時前台要顯示「內容持續更新」的空狀態，
     而不是一片空白 —— 目前彩票娛樂（4D）就是這個情況。
     ⚠ 在伺服器端算：分類名稱與圖示都在樣板與 UI_TEXT 裡，交給前台 JS 組字串就得再
     搬一份語系表過去。 */
  const catsWithProvider = new Set(providerRows.map((p) => p.cat));

  const catNodes = cats.map((x) => ({
    code: x.code,
    label: x.label,
    name: langVal(x.name, lang),
    icon: D.gameCatIcons[x.code] || D.gameCatFallback,
    empty: !catsWithProvider.has(x.code),
  }));
  /* 生態圖兩兩一列（列裡的橫向光線要貫穿左右兩格，所以有的樣板是一列一個容器）。 */
  const ecoMore = (pb.ecosystem || {}).more || {};
  const ecoItems = catNodes.slice(0, ECOSYSTEM_MAX_NODES).map((x) => ({ label: x.label, name: x.name, icon: x.icon }));
  if (ecoMore.label) ecoItems.push({ label: ecoMore.label, name: langVal(ecoMore.name, lang), icon: D.gameCatFallback });
  const ecoRows = [];
  for (let i = 0; i < ecoItems.length; i += 2) ecoRows.push(ecoItems.slice(i, i + 2));
  const providers = providerRows.map((p) => {
    const ct = catText(p.cat);
    return {
      cat: p.cat,
      catLabel: ct.label,
      catName: ct.name,
      name: p.name,
      desc: langVal(p.desc, lang),
      // 留空時樣板退回文字 wordmark（設計站本來就是文字，不會開天窗）
      logo: p.logo || '',
    };
  });
  const featuredProviders = providerRows
    .filter((p) => p.featured)
    .map((p) => {
      const ct = catText(p.cat);
      return {
        name: p.name,
        catLabel: ct.label,
        catName: ct.name,
        desc: langVal(p.desc, lang),
        tags: langVal(p.tags, lang) || [],
        featuredImage: p.featuredImage || '',
      };
    });

  return {
    ...baseContext(c, lang, '/games'),
    hero: heroOf(c, pageRow(c, 'games'), lang, resolve),
    headings: {
      providers: heading((pb.headings || {}).providers, lang),
      featured: heading((pb.headings || {}).featured, lang),
      ecosystem: heading((pb.headings || {}).ecosystem, lang),
    },
    categories: catNodes,
    providers,
    featuredProviders,
    ecosystem: {
      coreLabel: (pb.ecosystem || {}).coreLabel || '',
      // 環上只放前 5 個，第 6 格是「更多內容」（見 ECOSYSTEM_MAX_NODES）
      nodes: catNodes.slice(0, ECOSYSTEM_MAX_NODES),
      more: {
        label: ((pb.ecosystem || {}).more || {}).label || '',
        name: langVal(((pb.ecosystem || {}).more || {}).name, lang),
      },
      // [[左, 右], …]，每格 { label, name, icon }
      rows: ecoRows,
    },
    final: ctaBlockOf(pb.final, lang, resolve),
  };
}

// 熱門活動
export function buildPromotionsContext(c, lang, { now = new Date(), archive = false } = {}) {
  const pb = (c.pageBlocks || {}).promotions || {};
  const resolve = makeResolve(c, lang);
  const at = stamp(now);

  // 規格：結束時間到期後自動歸入「往期回顧」。設計站把兩者做成兩條路由
  // （/promotions、/promotions/archive），點分頁是換頁、不是捲到同頁的另一段。
  // archive 那條不在規格的 15 條路徑裡，SEO 走 pageBlocks.promotions.archive 的 fallback。
  const rows = ordered(c.promotions, 'asc');
  const card = (p) => ({
    label: langVal(p.label, lang),
    title: langVal(p.title, lang),
    desc: langVal(p.desc, lang),
    img: langVal(p.img, lang),
    link: resolve(p.linkVal),
  });
  const current = rows.filter((p) => notEnded(p.end, at)).map(card);
  const past = rows
    .filter((p) => p.end && !notEnded(p.end, at))
    .sort((a, b) => String(b.end).localeCompare(String(a.end)))
    .map(card);

  // 兩個分頁在兩條路由上都要出現：少了它，進到 archive 就沒有路走回熱門活動。
  // 這跟品牌影像的五個分頁是同一個做法（brandTabs）。
  const tabs = [
    {
      label: langVal((pb.tabs || {}).current, lang),
      url: localePath(c.settings, lang, '/promotions'),
      current: !archive,
    },
    {
      label: langVal((pb.tabs || {}).past, lang),
      url: localePath(c.settings, lang, '/promotions/archive'),
      current: archive,
    },
  ];

  const ab = pb.archive || {};
  return {
    // archive 不在 pages 那 11 筆裡，所以帶 page/desc 進去當 meta 的 fallback
    ...baseContext(c, lang, archive ? '/promotions/archive' : '/promotions',
      archive ? { page: ab.page, desc: ab.desc } : undefined),
    // 背景圖沿用 pages[promotions]
    hero: heroOf(c, archive ? ab : pageRow(c, 'promotions'), lang, resolve, pageRow(c, 'promotions')),
    tabs: tabs.filter((x) => x.label),
    heading: heading((pb.headings || {})[archive ? 'past' : 'current'], lang),
    isArchive: archive,
    cards: archive ? past : current,
    promoCta: langVal(archive ? pb.pastCta : pb.cta, lang),
  };
}

// 品牌影像（hub ＋ 四個分類子頁，共用 partials/media-archive.njk）
function brandCategories(c) {
  return (((c.pageBlocks || {}).brand || {}).categories) || [];
}

function brandTabs(c, lang, currentPath) {
  const rows = [{ label: ui(lang).mediaAll, path: '/brand' }].concat(
    brandCategories(c).map((x) => ({ label: langVal(x.label, lang), path: x.path }))
  );
  // mediaTabAnchor（例 #archive）：換頁後直接落在影像館，不會被首屏彈回頁頂
  return rows.map((x) => ({
    label: x.label,
    url: localePath(c.settings, lang, x.path) + (D.mediaTabAnchor || ''),
    current: x.path === currentPath,
  }));
}

function brandArchive(c, lang, { now = new Date(), cat = '' } = {}) {
  const pb = ((c.pageBlocks || {}).brand) || {};
  const at = stamp(now);
  const catLabel = new Map(brandCategories(c).map((x) => [x.cat, langVal(x.label, lang)]));
  const items = ordered(c.media, 'asc')
    .filter((m) => !cat || m.cat === cat)
    .map((m) => ({
      cover: langVal(m.cover, lang),
      title: langVal(m.title, lang),
      note: langVal(m.note, lang),
      cat: catLabel.get(m.cat) || m.cat,
      url: m.url || '',
    }));
  return {
    headings: { archive: heading((pb.headings || {}).archive, lang) },
    items,
    rights: {
      label: (pb.rights || {}).label || '',
      text: langVal((pb.rights || {}).text, lang),
    },
  };
}

export function buildBrandContext(c, lang, opts = {}) {
  const resolve = makeResolve(c, lang);
  return {
    ...baseContext(c, lang, '/brand'),
    hero: heroOf(c, pageRow(c, 'brand'), lang, resolve),
    tabs: brandTabs(c, lang, '/brand'),
    ...brandArchive(c, lang, opts),
  };
}

export function buildBrandCategoryContext(c, lang, cat, opts = {}) {
  const resolve = makeResolve(c, lang);
  return {
    // 品牌影像四個子頁不在 pages 裡，meta 用分類自己的頁名／說明
    ...baseContext(c, lang, cat.path, { page: cat.page, desc: cat.desc }),
    // 背景圖沿用 pages[brand]
    hero: heroOf(c, cat, lang, resolve, pageRow(c, 'brand')),
    tabs: brandTabs(c, lang, cat.path),
    ...brandArchive(c, lang, { ...opts, cat: cat.cat }),
  };
}

// 官方聯繫
export function buildContactContext(c, lang) {
  const pb = (c.pageBlocks || {}).contact || {};
  const resolve = makeResolve(c, lang);
  return {
    ...baseContext(c, lang, '/contact/channels'),
    hero: heroOf(c, pageRow(c, 'contact'), lang, resolve),
    headings: { contact: heading(pb.heading, lang) },
    contacts: ordered(c.contacts, 'asc').map((x) => ({
      key: CONTACT_KEY[x.type] || 'custom',
      icon: D.contactIcons[x.type] || '',
      glyph: D.contactGlyphs[x.type] || (D.contactIcons[x.type] ? '' : CONTACT_GLYPH_FALLBACK),
      art: (D.contactArt || {})[x.type] || '',
      name: langVal(x.name, lang),
      value: langVal(x.value, lang),
      link: resolve(x.link),
    })),
  };
}

// 品牌資訊（列表 + 內頁）

/* 資料存的是純文字（lead ＋ sections 的 title/desc），HTML 在這裡組。
   ⚠ 因為要交給樣板的 | safe，這裡必須自己轉義 —— 站方打的 < 或 & 沒轉義就是 XSS。
   用 split/join 不用正規表示式：& 一定要第一個換，不然會把後面產生的 &lt; 再換一次。
   說明欄每一行是一個段落（打 Enter 就換段），跟站方在輸入框看到的樣子一致。 */
const escHtml = (s) =>
  String(s ?? '').split('&').join('&amp;').split('<').join('&lt;').split('>').join('&gt;').split('"').join('&quot;');

/* 換行字元用 fromCharCode 取，不寫字面的跳脫序列 —— 純粹是為了讓這段
   在各種產生／編輯工具之間搬動時不會被吃掉反斜線。 */
const LF = String.fromCharCode(10);
const CR = String.fromCharCode(13);

const paras = (text) =>
  String(text ?? '')
    .split(CR)
    .join('')
    .split(LF)
    .map((x) => x.trim())
    .filter(Boolean)
    .map((x) => `<p>${escHtml(x)}</p>`)
    .join('');

/** 內文 HTML：開場段落 + 每個段落的「<h3>小標題</h3> + 說明段落」。
 *  ⚠ 小標題一定要是 h3 —— 彈窗（assets/js/insights.js）靠 h3 切段。 */
function articleBodyHtml(a, lang) {
  const out = [paras(langVal(a.lead, lang))];
  for (const s of a.sections || []) {
    const t = langVal(s.title, lang);
    if (t) out.push(`<h3>${escHtml(t)}</h3>`);
    out.push(paras(langVal(s.desc, lang)));
  }
  return out.filter(Boolean).join('');
}

/** 已啟用的資訊（列表與內頁共用同一份判斷） */
export function liveArticles(c) {
  return ordered(c.articles, 'asc');
}

export function buildBlogContext(c, lang, { now = new Date() } = {}) {
  const pb = (c.pageBlocks || {}).blog || {};
  const resolve = makeResolve(c, lang);
  const catName = new Map(
    (c.articleCategories || []).map((x) => [x.code, langVal(x.name, lang)])
  );
  const rows = liveArticles(c, now);
  // 走 heroPlain：跟文章頁的 <title>（buildSeo）同一套
  const plain = (v) => heroPlain(langVal(v, lang), c.tagline.brand);
  const card = (a) => ({
    url: localePath(c.settings, lang, '/blog/' + a.slug),
    title: plain(a.title),
    excerpt: plain(a.excerpt),
    img: a.cover,
    cat: a.cat,
    catName: catName.get(a.cat) || '',
    label: plain(a.label),
    // 內文給列表的彈窗用（設計站點卡片是開彈窗，不是換頁）
    body: articleBodyHtml(a, lang),
  });
  const featuredRow = rows.find((a) => a.featured);

  return {
    ...baseContext(c, lang, '/blog'),
    hero: heroOf(c, pageRow(c, 'blog'), lang, resolve),
    headings: {
      featured: heading((pb.headings || {}).featured, lang),
      latest: heading((pb.headings || {}).latest, lang),
    },
    // 設計站的最新焦點也會再出現在下方列表的第一張卡，所以 articles 不排除它
    featured: featuredRow ? card(featuredRow) : null,
    articles: rows.map(card),
    categories: ordered(c.articleCategories, 'asc').map((x) => ({
      code: x.code,
      name: langVal(x.name, lang),
    })),
  };
}

export function buildArticleContext(c, lang, a) {
  const catName = (c.articleCategories || []).find((x) => x.code === a.cat);
  const routePath = '/blog/' + a.slug;
  const cover = a.cover || '';
  return {
    // 文章內頁不在 pages 裡，meta 由文章本身補
    ...baseContext(c, lang, routePath, { page: a.title, desc: a.excerpt }),
    // 文章頁的 OG 型別是 article，不是預設的 website。
    // 沒有 article:published_time —— 站方不再填發佈時間（那個欄位對他們沒有意義），
    // 而寧可不輸出也不要輸出一個假的日期。
    ogType: 'article',
    articleMeta: { section: catName ? langVal(catName.name, lang) : '' },
    // 背景圖沿用 pages[blog]
    hero: heroFrom(c.tagline.brand, {
      eyebrow: langVal(a.label, lang),
      title: langVal(a.title, lang),
      sub: '',
      desc: langVal(a.excerpt, lang),
      cta: '',
      ctaUrl: '',
      bg: pageRow(c, 'blog').bg,
    }),
    article: {
      title: heroPlain(langVal(a.title, lang), c.tagline.brand),
      catName: catName ? langVal(catName.name, lang) : '',
      label: heroPlain(langVal(a.label, lang), c.tagline.brand),
      cover,
      body: articleBodyHtml(a, lang),
      /* lead / sections[].body 是 paras() 產生的 <p>（已 escape），樣板用 | safe。 */
      lead: paras(langVal(a.lead, lang)),
      sections: (a.sections || [])
        .map((s) => ({ title: langVal(s.title, lang), body: paras(langVal(s.desc, lang)) }))
        .filter((s) => s.title || s.body),
    },
    blogUrl: localePath(c.settings, lang, '/blog'),
  };
}

// 使用指南
export function guideSections(c) {
  return (((c.pageBlocks || {}).guide || {}).sections) || [];
}

export function buildGuideContext(c, lang, routePath = '/guide') {
  const sections = guideSections(c);
  const isRoot = routePath === '/guide';
  /* /guide 是總覽頁：首屏用 pages 表的 guide，內容顯示第一段（分頁列上第一段是亮的）。
     子頁的內容跟著自己那一段走；首屏則看 guideHeroPerSection —— 開著就用該段自己的首屏文案，
     關著就一律用 pages[guide]。 */
  const active = isRoot ? sections[0] || {} : sections.find((s) => s.path === routePath) || sections[0] || {};
  const resolve = makeResolve(c, lang);
  /* SEO 頁名逐語系退回分頁名稱。
     ⚠ 不能寫 active.page || active.name：page 是逐語系物件，清空後是 { en:'' }，仍然是真值。 */
  const seoPage = { ...(active.name || {}) };
  for (const [l, v] of Object.entries(active.page || {})) if (String(v ?? '').trim()) seoPage[l] = v;
  return {
    // /guide 在 pages 裡；子頁沒有，SEO 用該段自己的 page / desc 補
    ...baseContext(c, lang, routePath, isRoot ? undefined : { page: seoPage, desc: active.desc }),
    hero: heroOf(c, isRoot || !F.guideHeroPerSection ? pageRow(c, 'guide') : active, lang, resolve),
    guideTabs: sections.map((s) => ({
      name: langVal(s.name, lang),
      url: localePath(c.settings, lang, s.path),
      current: s.path === (active.path || routePath),
    })),
    guide: {
      // 這一段沒有手機示意圖就沿用第一段的，都沒有就空字串（樣板不畫）
      image: active.image || (sections[0] || {}).image || '',
      heading: heading(active.heading, lang),
      steps: (active.steps || []).map((s) => ({
        title: langVal(s.title, lang),
        desc: langVal(s.desc, lang),
      })),
      notice: {
        icon: D.guideNoticeIcon,
        title: langVal((active.notice || {}).title, lang),
        desc: langVal((active.notice || {}).desc, lang),
      },
    },
  };
}

// 常見問題
export function buildFaqContext(c, lang) {
  const pb = (c.pageBlocks || {}).faq || {};
  const resolve = makeResolve(c, lang);
  return {
    ...baseContext(c, lang, '/faq'),
    hero: heroOf(c, pageRow(c, 'faq'), lang, resolve),
    faqItems: (pb.items || []).map((x) => ({ q: langVal(x.q, lang), a: langVal(x.a, lang) })),
  };
}

// 代理招募
export function buildPartnersContext(c, lang) {
  const pb = (c.pageBlocks || {}).partners || {};
  const resolve = makeResolve(c, lang);
  const support = pb.support || {};
  const finalCta = ctaBlockOf(pb.finalCta, lang, resolve);
  return {
    ...baseContext(c, lang, '/partners'),
    hero: heroOf(c, pageRow(c, 'partners'), lang, resolve),
    headings: {
      benefits: heading((pb.headings || {}).benefits, lang),
      steps: heading((pb.headings || {}).steps, lang),
    },
    // img：右側淡化插圖（依索引輪替）
    benefits: titleDescRows(pb.benefits, lang, D.partnerBenefitIcons).map((r, i) => ({
      ...r,
      img: cycleAt(D.partnerBenefitImages, i),
    })),
    support: {
      eyebrow: langVal(support.eyebrow, lang),
      title: langVal(support.title, lang),
      sub: langVal(support.sub, lang),
      desc: langVal(support.desc, lang),
      // partnerSupportCycle：超過圖示數就從頭輪；否則多出來的列用 iconFallback
      items: titleDescRows(support.items, lang, D.partnerSupportIcons).map((r, i) => ({
        ...r,
        icon: D.partnerSupportCycle ? cycleAt(D.partnerSupportIcons, i) : r.icon,
      })),
    },
    steps: titleDescRows(pb.steps, lang, D.partnerStepIcons).map((r) => ({ ...r, img: D.partnerStepImage || '' })),
    finalCta: {
      ...finalCta,
      markIcon: D.partnerMarkIcon || '',
      markTop: (pb.finalCta || {}).markTop || '',
      markBottom: (pb.finalCta || {}).markBottom || '',
    },
  };
}

// 信任與安全
/** 面板 code 在 complianceArtCodes 裡才有分頁插圖與主視覺 */
function complianceArt(code) {
  if (!(D.complianceArtCodes || []).includes(code)) return { tabImg: '', bg: '' };
  const base = D.complianceArtBase + code;
  return { tabImg: `${base}-icon.webp`, bg: `${base}-bg.webp` };
}

export function buildComplianceContext(c, lang) {
  const pb = (c.pageBlocks || {}).compliance || {};
  const resolve = makeResolve(c, lang);
  return {
    ...baseContext(c, lang, '/compliance'),
    hero: heroOf(c, pageRow(c, 'compliance'), lang, resolve),
    headings: { policies: heading((pb.headings || {}).policies, lang) },
    panels: (pb.panels || []).map((p) => {
      const icons = D.complianceIcons[p.code] || D.complianceIconFallback;
      const art = complianceArt(p.code);
      return {
        code: p.code,
        icon: icons.tab,
        // 分頁插圖與面板主視覺；'' ＝ 沒有（樣板退回 SVG 圖示、不畫主視覺）
        tabImg: art.tabImg,
        bg: art.bg,
        tab: langVal(p.tab, lang),
        kicker: p.kicker || '',
        title: langVal(p.title, lang),
        sub: langVal(p.sub, lang),
        desc: langVal(p.desc, lang),
        policies: titleDescRows(p.policies, lang, icons.policies),
        notice: {
          icon: icons.notice,
          label: (p.notice || {}).label || '',
          desc: langVal((p.notice || {}).desc, lang),
        },
      };
    }),
  };
}

// 單頁渲染入口
export async function renderHome(lang, opts = {}) {
  const c = opts.content || loadContent();
  return env.render('pages/home.njk', await buildHomeContext(c, lang, opts));
}
export function renderAdvantages(lang, opts = {}) {
  const c = opts.content || loadContent();
  return env.render('pages/advantages.njk', buildAdvantagesContext(c, lang));
}
export function renderGames(lang, opts = {}) {
  const c = opts.content || loadContent();
  return env.render('pages/games.njk', buildGamesContext(c, lang));
}
export function renderPromotions(lang, opts = {}) {
  const c = opts.content || loadContent();
  return env.render('pages/promotions.njk', buildPromotionsContext(c, lang, { ...opts, archive: false }));
}
/** 往期回顧。設計站是獨立路由，不是同頁錨點。 */
export function renderPromotionsArchive(lang, opts = {}) {
  const c = opts.content || loadContent();
  return env.render('pages/promotions.njk', buildPromotionsContext(c, lang, { ...opts, archive: true }));
}
export function renderBrand(lang, opts = {}) {
  const c = opts.content || loadContent();
  return env.render('pages/brand.njk', buildBrandContext(c, lang, opts));
}
/** 品牌影像子頁。cat 可以是分類代碼（代言人…）或路徑（/brand/films） */
export function renderBrandCategory(lang, key, opts = {}) {
  const c = opts.content || loadContent();
  const row = brandCategories(c).find((x) => x.cat === key || x.path === key || x.path === '/brand/' + key);
  if (!row) throw Object.assign(new Error(`未知的品牌影像分類：${key}`), { status: 404 });
  return env.render('pages/brand-category.njk', buildBrandCategoryContext(c, lang, row, opts));
}
export function renderContact(lang, opts = {}) {
  const c = opts.content || loadContent();
  return env.render('pages/contact.njk', buildContactContext(c, lang));
}
export function renderBlog(lang, opts = {}) {
  const c = opts.content || loadContent();
  return env.render('pages/blog.njk', buildBlogContext(c, lang, opts));
}
export function renderArticle(lang, slug, opts = {}) {
  const c = opts.content || loadContent();
  const a = liveArticles(c, opts.now || new Date()).find((x) => x.slug === slug);
  if (!a) throw Object.assign(new Error(`找不到資訊：${slug}`), { status: 404 });
  return env.render('pages/article.njk', buildArticleContext(c, lang, a));
}
export function renderGuide(lang, routePath = '/guide', opts = {}) {
  const c = opts.content || loadContent();
  return env.render('pages/guide.njk', buildGuideContext(c, lang, routePath));
}
export function renderFaq(lang, opts = {}) {
  const c = opts.content || loadContent();
  return env.render('pages/faq.njk', buildFaqContext(c, lang));
}
export function renderPartners(lang, opts = {}) {
  const c = opts.content || loadContent();
  return env.render('pages/partners.njk', buildPartnersContext(c, lang));
}
export function renderCompliance(lang, opts = {}) {
  const c = opts.content || loadContent();
  return env.render('pages/compliance.njk', buildComplianceContext(c, lang));
}

/** 404 —— nginx 的 error_page 用。不在規格的 15 條路徑裡，
 *  但一個沒有 404 頁的網站等於在錯字時給訪客看 nginx 的預設白頁。
 *  noindex：這一頁不能自我 canonical 到一個不存在的網址。 */
export function buildNotFoundContext(c, lang) {
  const t = ui(lang, c.tagline.brand);
  return {
    ...baseContext(c, lang, '/'),
    /* 404 借用首页的路径，但不能带首页自己的 head —— 不然 404 页会显示首页的自订标题。
       只留全站共用的那段。 */
    pageHeadCode: '',
    headOverride: headOverrides(c.settings.headCode).keys,
    // notFoundNavNeutral：導覽不亮「首页」（借用首頁路徑只是為了語言切換與 hreflang）
    ...(F.notFoundNavNeutral ? { nav: buildNav(c, lang, '/__not_found__') } : {}),
    noindex: true,
    seo: { ...buildSeo(c, lang, '/'), title: t.notFoundTitle + ' | ' + c.tagline.brand },
    hero: heroFrom(c.tagline.brand, {
      eyebrow: t.notFoundEyebrow,
      title: t.notFoundTitle,
      sub: '',
      desc: t.notFoundDesc,
      cta: t.notFoundCta,
      ctaUrl: localePath(c.settings, lang, '/'),
      bg: '',
    }),
  };
}

export function renderNotFound(lang, opts = {}) {
  const c = opts.content || loadContent();
  return env.render('pages/404.njk', buildNotFoundContext(c, lang));
}

/** 首頁的語言導向頁（/ → /<語系前綴>/） */
export function renderRootRedirect(c) {
  const rules = (c.settings.redirect.rules || []).map((r) => ({
    contains: r.contains,
    to: langSlug(c.settings, r.to),
  }));
  return env.render('root-redirect.njk', {
    brand: c.tagline.brand,
    /* 站方贴的 head 程式码，整段原样带进来。这一页不走 layout，不带的话首页上什么都没有。

       ⚠ 不能只挑 <meta>。Search Console 不管用哪种方法验证都是来抓首页 / ——
       「HTML 标记」看 <meta>，「Google Analytics」看 gtag 的 <script>。
       原本只挑 <meta>，结果站方用 GA 方法验证就失败：「在索引网页上找不到追踪程式码」。

       会不会让访客被算两次浏览？不会，实测过：让 /cn 故意延迟 3 秒回应，这 3 秒里
       / 的 gtag.js 在 165ms 就下载完了，却一笔纪录都没送出 —— location.replace 一启动，
       浏览器就中止这份文件后续的执行。样板也刻意把它放在跳转的 <script> 之后。 */
    headCode: c.settings.headCode || '',
    rulesJson: safeInlineJson(rules),
    fallback: langSlug(c.settings, c.settings.defaultLanguage),
    locales: (c.settings.languages || []).map((l) => ({
      slug: langSlug(c.settings, l),
      htmlLang: langHtml(c.settings, l),
      name: l,
    })),
  });
}

/** 站台地圖：路徑全部從內容自己列舉出來，沒有需要人工維護的清單。
 *  漏一條的代價是那頁不被索引，所以寧可多列（下架的文章由 liveArticles 擋掉）。 */
function allRoutes(c) {
  return [
    ...(c.pages || []).map((p) => p.path),
    '/promotions/archive',
    ...brandCategories(c).map((x) => x.path),
    ...guideSections(c).map((s) => s.path),
    ...liveArticles(c).map((a) => '/blog/' + a.slug),
  ];
}

export function renderSitemap(c, lastmod, extraPaths = []) {
  const urls = [];
  const paths = allRoutes(c).concat(extraPaths);
  for (const lang of c.settings.languages || []) {
    for (const p of paths) urls.push(c.settings.baseUrl + localePath(c.settings, lang, p));
  }
  const items = urls
    .map((u) => '  <url>\n    <loc>' + u + '</loc>\n    <lastmod>' + lastmod + '</lastmod>\n  </url>')
    .join('\n');
  return '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://example.invalid/schemas/sitemap/0.9">\n' + items + '\n</urlset>\n';
}

export function renderRobots(c) {
  return 'User-agent: *\nAllow: /\nDisallow: /admin\n\nSitemap: ' + c.settings.baseUrl + '/sitemap.xml\n';
}
