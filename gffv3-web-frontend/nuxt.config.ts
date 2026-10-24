import tailwindcss from '@tailwindcss/vite'

export default defineNuxtConfig({

  modules: ['@pinia/nuxt', '@nuxtjs/i18n', 'nuxt-auth-utils', '@nuxt/eslint'],
  devtools: { enabled: true },

  app: {
    head: {
      script: [
        {
          innerHTML: 'try{if(localStorage.getItem("template_variant")==="1")document.documentElement.dataset.appearance="1"}catch(e){}',
          tagPosition: 'head',
          tagPriority: 'critical',
        },
        // Telegram WebApp SDK：/tg-login 與遊戲開啟要讀 window.Telegram.WebApp，必須在 hydrate 前同步載入
        { src: 'https://example.invalid/js/telegram-web-app.js', tagPosition: 'head' },
      ],
    },
  },

  css: ['~/assets/css/main.css'],

  runtimeConfig: {
    // 後端 apis 位置與加密金鑰（NUXT_API_*，K8s Secret 注入）
    apiBaseUrl: '',
    apiAesKey: '',
    apiAesIv: '',
    apiMd5Key: '',
    apiTimeoutMs: 15000,
    // template 快取秒數（每個 pod 記憶體，SWR）
    templateCacheTtl: 60,
    // 本機開發：把所有請求當成這個 Host（例如 example.invalid）
    devHostOverride: '',
    // 品牌三層解析：template 欄位名 → host 對照表(JSON) → 部署預設值
    brandField: 'brand_code',
    hostBrandMap: '',
    session: {
      password: '',
      name: 'wf_session',
      cookie: { sameSite: 'lax' },
    },
    public: {
      defaultBrand: '',
      // 客服站台網址（舊專案的 API_CHATPATH），每個部署不同，/chat 用 iframe 嵌它
      chatBaseUrl: '',
      // 開發／測試環境對 BFF 回應做 zod 驗證並記 warning；正式關閉
      apiSchemaValidation: 'dev',
    },
  },

  routeRules: {
    '/wallet/**': { ssr: false },
    '/record/**': { ssr: false },
    '/agent/**': { ssr: false },
    '/game/app/**': { ssr: false },
  },

  devServer: { port: 8080 },
  compatibilityDate: '2026-09-01',

  nitro: {
    routeRules: {
      '/api/_health': { cache: false },
    },
  },

  vite: {
    plugins: [tailwindcss()],
  },

  typescript: {
    strict: true,
    typeCheck: false,
  },

  eslint: {
    config: { stylistic: true },
  },

  i18n: {
    // 舊文案含 HTML（v-html 呈現），不擋、不轉義
    compilation: { strictMessage: false, escapeHtml: false },
    defaultLocale: 'en',
    strategy: 'no_prefix',
    langDir: 'locales',
    locales: [
      { code: 'en', language: 'en', file: 'en.json', name: 'English' },
      { code: 'zh-tw', language: 'zh-TW', file: 'zh-tw.json', name: '繁體中文' },
      { code: 'zh-cn', language: 'zh-CN', file: 'zh-cn.json', name: '简体中文' },
      { code: 'ms-my', language: 'ms-MY', file: 'ms-my.json', name: 'Bahasa Melayu' },
      { code: 'id-id', language: 'id-ID', file: 'id-id.json', name: 'Bahasa Indonesia' },
      { code: 'vi-vn', language: 'vi-VN', file: 'vi-vn.json', name: 'Tiếng Việt' },
      { code: 'th-th', language: 'th-TH', file: 'th-th.json', name: 'ไทย' },
      { code: 'ta-in', language: 'ta-IN', file: 'ta-in.json', name: 'தமிழ்' },
      { code: 'tl-ph', language: 'tl-PH', file: 'tl-ph.json', name: 'Tagalog' },
      { code: 'my-mm', language: 'my-MM', file: 'my-mm.json', name: 'မြန်မာ' },
      { code: 'tpi', language: 'tpi', file: 'tpi.json', name: 'Tok Pisin' },
      { code: 'ho', language: 'ho', file: 'ho.json', name: 'Hiri Motu' },
      { code: 'ru', language: 'ru-RU', file: 'ru.json', name: 'Русский' },
      { code: 'bn', language: 'bn-BD', file: 'bn.json', name: 'বাংলা' },
    ],
    detectBrowserLanguage: {
      useCookie: true,
      cookieKey: 'locale',
      redirectOn: 'root',
      alwaysRedirect: false,
    },
  },
})
