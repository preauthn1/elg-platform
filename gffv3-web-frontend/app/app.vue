<script setup lang="ts">
import { PWA_MANIFEST_PATH, pwaAppFor } from '#shared/constants/pwa'

const { site, brand } = useSite()

// 有 PWA 設定的品牌才掛 manifest；沒有 manifest 瀏覽器不會丟 beforeinstallprompt，「下載 APP」就叫不出安裝視窗
const hasPwa = computed(() => pwaAppFor(brand.value) !== null)

/**
 * 皮與款式掛在 <html> 上：`C1311` → data-skin="C13" data-variant="11"。
 * 款式（舊版的 11/12/13…）只覆寫 skins/<皮>/theme.css 裡的 CSS 變數，
 * 元件與頁面一行都不用改——舊版是在 71 個元件裡各寫一份 &.variant-1 覆寫。
 */
useHead({
  title: () => site.value?.raw.title || site.value?.domain || 'web-frontend',
  link: () => [
    ...(site.value?.raw.icon ? [{ rel: 'icon' as const, href: site.value.raw.icon }] : []),
    ...(hasPwa.value ? [{ rel: 'manifest' as const, href: PWA_MANIFEST_PATH }] : []),
  ],
  htmlAttrs: {
    'data-skin': () => site.value?.template.skin || '',
    'data-variant': () => site.value?.template.variant || '',
  },
})
</script>

<template>
  <NuxtLayout>
    <NuxtPage />
  </NuxtLayout>
</template>
