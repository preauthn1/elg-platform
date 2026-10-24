/* TPOWER 官方站的站點設定。共用程式（render.js、schema、後台）只讀這裡，不判斷站名。
   decor 是版面裝飾（圖示名稱對照 lucide，見 templates/tpower/partials/icons.njk），不是站方內容。 */
export default {
  id: 'tpower',
  name: 'official-tpower',
  // 後台左上角與預設 favicon 的字母
  letter: 'T',
  // cookie 不分 port，本機兩站同時跑時名稱不能相同
  cookieName: 'otp_sess',
  devAdmin: { username: 'admin', password: [REDACTED]' },

  features: {
    // 首屏文字不處理 {brand}／{強調} 占位
    heroPlaceholders: false,
    // 頁尾眉標固定用 UI_TEXT.footerKicker（品牌標語不進頁尾）
    footerKickerFromSlogan: false,
    // /guide/<code> 的首屏跟著該段自己的 eyebrow/title/sub 走
    guideHeroPerSection: true,
    // 「豐富遊戲」方格沒有自己的資料時，拿特色標籤攤開
    advantageGridFromTags: true,
    // 數據卡說明裡「數字＋單位」外面不另包一層
    statDescWrap: false,
    // 404 的導覽沿用首頁路徑（首页會亮）
    notFoundNavNeutral: false,
  },

  // 跟 UI_TEXT（render.js）不同的介面字串，逐語系覆寫
  uiText: {},

  // 前台語言選單：沒給 name 的就用 schema 的 LANG_NAME
  langFront: {
    en: { short: 'EN' },
    'zh-CN': { short: '中' },
    ms: { short: 'BM' },
  },

  decor: {
    iconFallback: 'layout-grid',
    statIcons: {
      註冊會員數: 'users-round',
      代理數: 'handshake',
      累計代理佣金: 'wallet-cards',
      累計活動獎金: 'gift',
    },
    journeyIcons: ['rocket', 'network', 'layers', 'trophy'],
    // 核心優勢六項的主圖示（總覽卡與細節小標共用），依 items[].id
    advantageIcons: {
      'fast-stable': 'zap',
      games: 'gamepad2',
      transactions: 'wallet-cards',
      security: 'shield-check',
      fairness: 'scale',
      support: 'headphones',
    },
    // 六種右側視覺各自的圖示，依 variant 取；陣列依序對應該區塊的每一列
    advantageVisualIcons: {
      speed: { core: 'server', status: ['wifi', 'activity', 'smartphone'] },
      gameCategories: { items: ['monitor', 'gamepad2', 'trophy', 'ticket', 'radar', 'zap'] },
      transaction: { steps: ['circle-dollar-sign', 'refresh-cw', 'wallet-cards', 'circle-check'] },
      security: { core: 'shield-check', layers: ['lock-keyhole', 'database', 'radar', 'wifi'] },
      fairness: { items: ['book-open-check', 'receipt-text', 'circle-check', 'scale'] },
      support: {
        core: 'headphones',
        items: ['users-round', 'gamepad2', 'credit-card', 'message-circle', 'languages'],
        footer: 'life-buoy',
      },
    },
    gameCatIcons: { SLOT: 'gamepad2', SPORTS: 'trophy', LIVE: 'users-round', FISHING: 'fish', '4D': 'ticket' },
    gameCatFallback: 'layout-grid',
    // 認得出品牌的渠道用 SVG，其餘用字元
    contactIcons: { Telegram: 'contact-telegram', WhatsApp: 'contact-whatsapp', LINE: 'contact-line' },
    contactGlyphs: { Email: '✉', 自訂: '◆' },
    partnerBenefitIcons: ['gamepad2', 'monitor', 'headphones', 'database'],
    partnerSupportIcons: ['gamepad2', 'monitor', 'headphones', 'wallet-cards'],
    partnerStepIcons: ['message-circle', 'users-round', 'rocket'],
    partnerMarkIcon: 'handshake',
    guideNoticeIcon: 'shield-check',
    // 信任與安全：依面板 code
    complianceIcons: {
      compliance: { tab: 'shield-check', notice: 'shield-check', policies: ['earth', 'book-open-check', 'shield-check', 'receipt-text'] },
      responsible: { tab: 'activity', notice: 'shield-check', policies: ['users-round', 'circle-dollar-sign', 'scale', 'radar'] },
      privacy: { tab: 'lock-keyhole', notice: 'shield-check', policies: ['database', 'lock-keyhole', 'circle-check', 'book-open-check'] },
      identity: { tab: 'credit-card', notice: 'shield-check', policies: ['users-round', 'wallet-cards', 'radar', 'shield-check'] },
    },
    complianceIconFallback: { tab: 'shield-check', notice: 'shield-check', policies: [] },
  },

  // 核心優勢各 variant 至少要一列的視覺清單
  advantageRequired: {
    speed: ['status', '系统状态'],
    transaction: ['steps', '交易流程'],
    security: ['layers', '防护层'],
    fairness: ['items', '项目清单'],
    support: ['items', '项目清单'],
  },

  /* 後台：這一站的樣板用不到、不該給站方填的欄位。鍵名對應 admin/js/views/*.js 裡的
     site: '<鍵>' 與 shown('<鍵>')。 */
  admin: {
    hidden: [
      'hero.innerBg', // 內頁首屏背景（內頁是 .hero-no-media）
      'hero.placeholders', // 首屏 {brand}／{強調} 的說明
      'settings.footerBlurb',
      'adv.detailTitle',
      'adv.img',
      'adv.gameCards', // 豐富遊戲的圖卡（這一站從特色標籤攤開）
      'guide.seo', // 指南分頁的 SEO 頁名／描述（desc 同時是子頁首屏說明，不另開）
      'guide.image',
      'guide.heroNote',
    ],
    // 樣板有退路、所以這一站選填的欄位（鍵名同 hidden）。schema 與後台都讀這份
    optional: [],
    text: {
      registerLabel: '立即加入',
      registerPh: '/partners 或 https://…',
      sloganPh: '选填 · 留空就不显示首屏按钮旁的标语',
    },
  },
};
