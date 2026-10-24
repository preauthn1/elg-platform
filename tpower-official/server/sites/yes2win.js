/* YES2WIN 官方站的站點設定。共用程式（render.js、schema、後台）只讀這裡，不判斷站名。
   decor 是版面裝飾（templates/yes2win/partials/icons-*.njk 的圖示名、assets/yes2win/img 的插圖），不是站方內容。
   ⚠ /assets/ 是一年 immutable 快取，要換圖就換檔名。 */

/** 數字的「等效位數」：數字算 1，千分位逗號與小數點算 0.45。assets/yes2win/js/stats.js 用同一個公式，兩邊要一起改。 */
function statNumLen(text) {
  const s = String(text || '');
  const digits = (s.match(/\d/g) || []).length;
  const marks = (s.match(/[.,]/g) || []).length;
  return Math.round((digits + marks * 0.45) * 100) / 100 || 1;
}

export default {
  id: 'yes2win',
  name: 'official-yes2win',
  letter: 'Y',
  cookieName: 'oy2w_sess',
  devAdmin: { username: 'admin', password: [REDACTED]', role: 'admin' },

  features: {
    heroPlaceholders: true,
    footerKickerFromSlogan: true,
    guideHeroPerSection: false,
    advantageGridFromTags: false,
    statDescWrap: true,
    notFoundNavNeutral: true,
  },

  uiText: {
    en: {
      mediaSoon: 'Coming soon',
      mediaTabsAria: 'Media categories',
      featuredVisualAlt: '{name} game preview',
      readMoreCard: 'View full details',
      noArticles: 'No matching stories yet. Try another category or keyword.',
      guideTabs: 'Guide sections',
      guideVisualAlt: '{brand} app on a phone',
      promoModalOk: 'Close',
      promoTabs: 'Promotion views',
      promoArchiveEmptyTitle: 'Past promotions',
      promoArchiveEmptyDesc: 'More past highlights are being compiled',
    },
    'zh-CN': {
      mediaSoon: '即将上线',
      mediaTabsAria: '影像分类',
      featuredVisualAlt: '{name} 游戏画面',
      readMoreCard: '查看活动详情',
      noArticles: '暂无符合条件的资讯，换个分类或关键词试试。',
      guideTabs: '使用指南分类',
      guideVisualAlt: '{brand} 手机版画面',
      promoModalOk: '关闭',
      promoTabs: '活动分类',
      promoArchiveEmptyTitle: '往期回顾',
      promoArchiveEmptyDesc: '更多往期精彩活动正在整理中',
    },
    ms: {
      mediaSoon: 'Akan datang',
      mediaTabsAria: 'Kategori media',
      featuredVisualAlt: 'Pratonton permainan {name}',
      readMoreCard: 'Lihat butiran penuh',
      noArticles: 'Tiada berita yang sepadan. Cuba kategori atau kata kunci lain.',
      guideTabs: 'Bahagian panduan',
      guideVisualAlt: 'Aplikasi {brand} pada telefon',
      promoModalOk: 'Tutup',
      promoTabs: 'Paparan promosi',
      promoArchiveEmptyTitle: 'Promosi lalu',
      promoArchiveEmptyDesc: 'Lebih banyak sorotan lalu sedang disusun',
    },
  },

  // 前台選單只有一種中文，所以不用 schema 的 LANG_NAME（那張是後台的「简体中文」）
  langFront: {
    en: { name: 'English', short: 'EN' },
    'zh-CN': { name: '中文', short: '中' },
    ms: { name: 'Bahasa Melayu', short: 'BM' },
  },

  decor: {
    // 圖示比資料列少時給空字串（樣板就不畫）
    iconFallback: '',
    statIcons: {},
    // 首頁數據卡的 3D 插圖，依「指標來源」；對不到用第一張
    statArt: {
      註冊會員數: '/assets/img/home/stat-members.webp',
      代理數: '/assets/img/home/stat-agents.webp',
      累計代理佣金: '/assets/img/home/stat-commission.webp',
      累計活動獎金: '/assets/img/home/stat-bonus.webp',
    },
    statNumLen,
    journeyIcons: [],
    advantageIcons: {},
    // 總覽卡右上的淡化插圖，依 items[].id；對不到的 id 就不畫
    advantageArt: {
      'fast-stable': '/assets/img/advantages/adv-fast-stable.webp',
      games: '/assets/img/advantages/adv-games.webp',
      transactions: '/assets/img/advantages/adv-transactions.webp',
      security: '/assets/img/advantages/adv-security.webp',
      fairness: '/assets/img/advantages/adv-fairness.webp',
      support: '/assets/img/advantages/adv-support.webp',
    },
    // 細節段各列的實心圖示（icons-advantages.njk），陣列依序對應每一列
    advantageVisualIcons: {
      speed: { status: ['adv-signal', 'adv-uptime', 'adv-mobile'] },
      transaction: { steps: ['adv-cash', 'adv-refresh', 'adv-wallet', 'adv-done'] },
      support: { items: ['adv-user', 'adv-gamepad', 'adv-card', 'adv-question', 'adv-language'] },
    },
    gameCatIcons: { SLOT: 'cat-slot', SPORTS: 'cat-sports', LIVE: 'cat-live', FISHING: 'cat-fishing', '4D': 'cat-4d' },
    gameCatFallback: 'cat-more',
    contactIcons: {
      Telegram: 'contact-telegram',
      WhatsApp: 'contact-whatsapp',
      Email: 'contact-email',
      LINE: 'contact-line',
    },
    contactGlyphs: { 自訂: '◆' },
    contactArt: {
      Telegram: '/assets/img/promotions_contact/contact-telegram-3d.webp',
      WhatsApp: '/assets/img/promotions_contact/contact-whatsapp-3d.webp',
      Email: '/assets/img/promotions_contact/contact-email-3d.webp',
    },
    // 依索引輪替，不存資料（後台調換順序時插圖不跟著走）
    partnerBenefitImages: [1, 2, 3, 4].map((n) => `/assets/img/partners/benefit-${n}.webp`),
    partnerStepImage: '/assets/img/partners/benefit-1.webp',
    partnerSupportIcons: ['partner-product', 'partner-assets', 'partner-service', 'partner-settlement'],
    partnerSupportCycle: true,
    guideNoticeIcon: 'shield',
    complianceIcons: {},
    complianceIconFallback: { tab: 'shield-check', notice: '', policies: [] },
    // 分頁插圖與面板主視覺依 code 固定；不在表裡的面板退回 SVG 圖示、不畫主視覺
    complianceArtCodes: ['compliance', 'responsible', 'privacy', 'identity'],
    complianceArtBase: '/assets/img/blog_compliance/trust-',
    // 品牌影像分頁網址帶 #archive：換頁後直接落在影像館
    mediaTabAnchor: '#archive',
  },

  advantageRequired: {
    speed: ['status', '系统状态'],
    transaction: ['steps', '交易流程'],
    support: ['items', '服务项目'],
  },

  admin: {
    hidden: [
      'adv.securityCore', // 安全防護的核心標籤／注記／防護層
      'adv.supportPanel', // 專業服務的面板標籤／列狀態字
      'adv.fairnessItems', // 公平透明的項目清單
      'adv.tagsAll', // 豐富遊戲／專業服務不顯示特色標籤
      'partners.mark', // 代理招募收尾區的角落裝飾字
      'brand.rightsNote',
    ],
    // 這一站樣板有退路、所以選填的欄位（他站可能必填）。schema 與後台都讀這份
    optional: [
      'adv.short', // 生態圈節點留空＝沿用優勢標題
    ],
    text: {
      registerLabel: '立即注册',
      registerPh: '/guide/register 或 https://…',
      sloganPh: '页尾 logo 下方那一行 · 留空＝POWERING EVERY MOMENT',
    },
  },
};
