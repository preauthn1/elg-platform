<script setup lang="ts">
/** /signup/:shareCode 的 C17 畫面。推薦碼由 pages/signup/[shareCode].vue 從路由取出傳入。 */
defineProps<{
  lockedCode?: string | null
}>()
</script>

<template>
  <div class="mx-auto max-w-sm space-y-4">
    <h1 class="text-xl font-semibold">
      {{ $t('auth.signup.action.signUp') }}
    </h1>
    <AuthSignupForm :locked-code="lockedCode" />
  </div>
</template>
