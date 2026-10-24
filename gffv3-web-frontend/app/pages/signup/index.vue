<script setup lang="ts">
/**
 * 註冊。畫面在 components/<皮>/Page/Signup.vue，表單邏輯在 useSignupForm。
 * 這裡只放路由層：版面設定、進頁條件、網址帶進來的推薦碼。
 */
definePageMeta({ fullBleed: true, middleware: 'signup-guest' })

const route = useRoute()
const { canRegister } = useAuth()

// 註冊頁只在 agent/template 的 frontend_register==1 才可進入
if (!canRegister.value) {
  await navigateTo('/login', { replace: true })
}

const code = typeof route.query.a === 'string' ? route.query.a : null
</script>

<template>
  <SkinPage
    name="Signup"
    :locked-code="code"
  />
</template>
