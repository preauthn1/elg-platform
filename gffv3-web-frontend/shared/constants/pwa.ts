/**
 * 各品牌的 PWA 與推播設定，key 是 `site.brand`（部署環境變數 NUXT_HOST_BRAND_MAP 的值）。
 *
 * 舊前台用網域判斷（helpers.js 的 $getDefaultManifestJsonPath、utils/fcmService.js 的 getFirebaseKey），
 * 新專案只認品牌代號，網域對品牌的對照放在部署設定。
 */

export interface PwaApp {
  name: string
  /** `public/pwa/<icon>.webp` */
  icon: string
  /** 圖示實際邊長（px），舊前台的 manifest 一律寫 1024，與實際尺寸不符 */
  size: number
}

/** 對應舊前台 `static/manifest/<站>.json`。沒有列在這裡的品牌不輸出 manifest。 */
export const PWA_APPS: Readonly<Record<string, PwaApp>> = {
  'dev': { name: 'Dev', icon: 'dev', size: 1024 },
  'wintoto': { name: 'Wintoto', icon: 'wintoto', size: 222 },
  'tpower': { name: 'T Power', icon: 'tpower', size: 512 },
  'tpower_hk': { name: 'T-POWER HK', icon: 'tpower_hk', size: 512 },
  'tpower_png': { name: 'T-POWER PNG', icon: 'tpower_png', size: 1024 },
  'sbs77': { name: 'SBS77', icon: 'sbs77', size: 1024 },
  'auk99': { name: 'AUK99', icon: 'auk99', size: 1024 },
  'vietwin88': { name: 'VietWin88', icon: 'vietwin88', size: 512 },
  'alfawin77': { name: 'Alfawin77', icon: 'alfawin77', size: 1024 },
  'yes2win': { name: 'Yes2Win', icon: 'yes2win', size: 1024 },
  'unet': { name: 'UNET', icon: 'unet', size: 1024 },
  'me77': { name: 'ME77', icon: 'me77', size: 1024 },
  '77win': { name: '77Win', icon: '77win', size: 250 },
  'togethergo': { name: 'TogetherGo', icon: 'togethergo', size: 1024 },
  'winstar': { name: 'WinStar', icon: 'winstar', size: 250 },
  'vworldmy': { name: 'V-World', icon: 'vworld', size: 512 },
  'vworldsg': { name: 'V-World SG', icon: 'vworld', size: 512 },
}

export function pwaAppFor(brand: string | null | undefined): PwaApp | null {
  return (brand && PWA_APPS[brand]) || null
}

/** manifest 由 server/routes/manifest.webmanifest.get.ts 依當下站台產生 */
export const PWA_MANIFEST_PATH = '/manifest.webmanifest'

/** Firebase Web 設定（瀏覽器端本來就公開），對應舊前台 `static/js/firebase-config.js` 的 FIREBASE_MAP。 */
export interface FirebaseWebConfig {
  apiKey: [REDACTED]
  authDomain: string
  projectId: string
  storageBucket: string
  messagingSenderId: string
  appId: string
  measurementId: string
  vapidKey: string
}

export const FIREBASE_CONFIGS: Readonly<Record<string, FirebaseWebConfig>> = {
  me77: {
    apiKey: [REDACTED]',
    authDomain: 'example.invalid',
    projectId: 'me77-758f0',
    storageBucket: 'example.invalid',
    messagingSenderId: '507927450131',
    appId: '1:507927450131:web:c0c249afc31a28cd8ee5e6',
    measurementId: 'G-6800P87ME1',
    vapidKey: 'BCfHiJ1rNTnVMYjC1g5yF8Fv2reXS6erdtpdXjhNdrHgPGWtalA5mho5WgoR4nB0lw_xt2XMLa9oj2vSnvVDGUE',
  },
  vworldmy: {
    apiKey: [REDACTED]',
    authDomain: 'example.invalid',
    projectId: 'v-world-c63f1',
    storageBucket: 'example.invalid',
    messagingSenderId: '1010590350929',
    appId: '1:1010590350929:web:ffe3846120099fe7e10270',
    measurementId: 'G-M6Q69P6XZ1',
    vapidKey: 'BAZYiHMy7F1nw7nCFjm3g9HwdI5c2DfMb4T-znjph-E-gtySJg_5J208fY4yMJR3Sg_kb-NMpdo_isGKkYeYE30',
  },
  dev: {
    apiKey: [REDACTED]',
    authDomain: 'example.invalid',
    projectId: 'project-248b5',
    storageBucket: 'example.invalid',
    messagingSenderId: '891980011725',
    appId: '1:891980011725:web:9bd49b67c79b074ad5f184',
    measurementId: 'G-KVMS6PVYLQ',
    vapidKey: 'BHTRQi9YC7afreGfR4gu9_iBrq5J1jM0nLrTRKrJTx12T4XFgN0U8GC_pbE3xqpknco2FLHuz5Ua0tPn7xaN1lg',
  },
}

export function firebaseConfigFor(brand: string | null | undefined): FirebaseWebConfig | null {
  return (brand && FIREBASE_CONFIGS[brand]) || null
}

/**
 * 會跳「開啟推播」提示的品牌，對應舊前台 PushNotificationPrompt.vue 的 isSupportedPlatform。
 * vworldsg 會跳提示但沒有 Firebase 設定，授權後不會拿到 token——舊前台就是這樣，照搬。
 */
export const PUSH_PROMPT_BRANDS: readonly string[] = ['vworldmy', 'vworldsg', 'dev']

/** service worker 路徑，由 server/routes/firebase-messaging-sw.js.get.ts 依 ?brand= 產生 */
export const FIREBASE_SW_PATH = '/firebase-messaging-sw.js'
