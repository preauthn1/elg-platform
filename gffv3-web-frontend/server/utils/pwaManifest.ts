import type { PwaApp } from '#shared/constants/pwa'

/** 欄位與底色照舊前台 static/manifest/*.json，各站只差名稱與圖示 */
export function buildPwaManifest(app: PwaApp) {
  return {
    name: app.name,
    short_name: app.name,
    start_url: '/',
    display: 'standalone',
    background_color: '#000',
    theme_color: '#000',
    icons: [{ src: `/pwa/${app.icon}.webp`, sizes: `${app.size}x${app.size}`, type: 'image/webp' }],
  }
}
