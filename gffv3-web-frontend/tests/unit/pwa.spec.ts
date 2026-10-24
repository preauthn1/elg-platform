import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { buildPwaManifest } from '../../server/utils/pwaManifest'
import {
  FIREBASE_CONFIGS,
  firebaseConfigFor,
  PUSH_PROMPT_BRANDS,
  PWA_APPS,
  pwaAppFor,
} from '../../shared/constants/pwa'

const ROOT = fileURLToPath(new URL('../..', import.meta.url))
const PWA_DIR = join(ROOT, 'public', 'pwa')

/** 從 WebP 檔頭讀寬高（VP8 有損、VP8L 無損、VP8X 延伸三種格式） */
function webpSize(file: string): [number, number] {
  const b = readFileSync(file)
  const chunk = b.toString('ascii', 12, 16)
  if (chunk === 'VP8 ') return [b.readUInt16LE(26) & 0x3FFF, b.readUInt16LE(28) & 0x3FFF]
  if (chunk === 'VP8L') {
    const bits = b.readUInt32LE(21)
    return [(bits & 0x3FFF) + 1, ((bits >> 14) & 0x3FFF) + 1]
  }
  return [b.readUIntLE(24, 3) + 1, b.readUIntLE(27, 3) + 1]
}

describe('PWA manifest', () => {
  /** 守門：表裡加了品牌卻沒放圖示，安裝時會是破圖 */
  it('每個品牌的圖示都在 public/pwa', () => {
    for (const [brand, app] of Object.entries(PWA_APPS)) {
      expect(existsSync(join(PWA_DIR, `${app.icon}.webp`)), brand).toBe(true)
    }
  })

  /** 守門：manifest 宣告的 sizes 要跟實際圖檔一致，不然瀏覽器挑圖會挑錯 */
  it('宣告的尺寸與圖檔實際尺寸一致', () => {
    for (const [brand, app] of Object.entries(PWA_APPS)) {
      expect(webpSize(join(PWA_DIR, `${app.icon}.webp`)), brand).toEqual([app.size, app.size])
    }
  })

  it('public/pwa 沒有表裡沒用到的圖', () => {
    const used = new Set(Object.values(PWA_APPS).map(a => `${a.icon}.webp`))
    expect(readdirSync(PWA_DIR).filter(f => !used.has(f))).toEqual([])
  })

  it('沒有設定的品牌不輸出', () => {
    expect(pwaAppFor('')).toBeNull()
    expect(pwaAppFor('default')).toBeNull()
    expect(pwaAppFor(undefined)).toBeNull()
  })

  it('manifest 內容照舊前台 static/manifest/*.json', () => {
    expect(buildPwaManifest(PWA_APPS.vworldmy!)).toEqual({
      name: 'V-World',
      short_name: 'V-World',
      start_url: '/',
      display: 'standalone',
      background_color: '#000',
      theme_color: '#000',
      icons: [{ src: '/pwa/vworld.webp', sizes: '512x512', type: 'image/webp' }],
    })
  })
})

describe('FCM 推播', () => {
  it('只有舊前台 firebase-config.js 有的三個品牌有設定', () => {
    expect(Object.keys(FIREBASE_CONFIGS).sort()).toEqual(['dev', 'me77', 'vworldmy'])
    expect(firebaseConfigFor('tgg')).toBeNull()
    expect(firebaseConfigFor('default')).toBeNull()
  })

  it('跳提示的品牌照舊前台 PushNotificationPrompt 的 isSupportedPlatform', () => {
    expect([...PUSH_PROMPT_BRANDS].sort()).toEqual(['dev', 'vworldmy', 'vworldsg'])
  })
})
