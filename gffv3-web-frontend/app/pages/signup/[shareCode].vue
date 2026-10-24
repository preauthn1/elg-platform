<script setup lang="ts">
/** 帶推薦碼的註冊連結。畫面在 components/<皮>/Page/SignupShareCode.vue。 */
definePageMeta({ middleware: 'signup-guest' })

const route = useRoute()
const { canRegister } = useAuth()

if (!canRegister.value) {
  await navigateTo('/login', { replace: true })
}

const param = route.params.shareCode
const code = typeof param === 'string' ? param : Array.isArray(param) ? param[0] ?? null : null
</script>

<template>
  <SkinPage
    name="SignupShareCode"
    :locked-code="code"
  />
</template>
