<script setup lang="ts">
import { resolveRedirectPath } from '#shared/utils/auth'

/**
 * 登入。路由要保留：BFF 收到 9998 會導來 /login?redirect=…，深連結也要能開。
 * 畫面在 components/<皮>/Page/Login.vue，邏輯在 useLoginForm。
 */
definePageMeta({ fullBleed: true })

const route = useRoute()
const { loggedIn } = useUserSession()

// 已登入就不用再看登入頁，回 redirect 或首頁（SSR 也會導）
if (loggedIn.value) {
  await navigateTo(resolveRedirectPath(route.query.redirect), { replace: true })
}
</script>

<template>
  <SkinPage name="Login" />
</template>
