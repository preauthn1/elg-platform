<script setup lang="ts">
/**
 * 首頁。畫面與區塊資料在 components/<皮>/Page/Home.vue（各皮首頁放的區塊不同）。
 * 這裡放所有皮共用的：SEO、進頁時的補資料檢查。
 */
// 設計稿 Home v3 最上面是下載列，沒有餘額列——餘額在頁面中段那條黃綠 WalletBar
definePageMeta({ fullBleed: true, hideAppHeader: true })

// 後台「推廣管理 → SEO 元數據」的首頁設定；沒設定的欄位不輸出，title 退回 app.vue 的站名。
// og:* 給 Telegram 等貼連結預覽用，og:title 與 <title> 保持一致
const { site, settings } = useSite()
const seo = computed(() => seoMetaFor(settings.value, 'home'))
const siteName = computed(() => site.value?.raw.title?.trim() || '')
useHead({
  title: () => seo.value.title || undefined,
  meta: () => {
    const title = seo.value.title || siteName.value
    return [
      ...(seo.value.description ? [{ name: 'description', content: seo.value.description }] : []),
      ...(seo.value.keywords ? [{ name: 'keywords', content: seo.value.keywords }] : []),
      ...(siteName.value ? [{ property: 'og:site_name', content: siteName.value }] : []),
      ...(title ? [{ property: 'og:title', content: title }] : []),
      ...(seo.value.description ? [{ property: 'og:description', content: seo.value.description }] : []),
    ]
  },
})

/** 照舊版 C13 Lobby 的 created：已登入就檢查還有哪一項資料沒驗證，沒過會彈訊息、關掉後導去補資料 */
const { loggedIn } = useUserSession()
const member = useMember()
onMounted(() => {
  if (loggedIn.value) void member.checkProfile('/')
})
</script>

<template>
  <SkinPage name="Home" />
</template>
