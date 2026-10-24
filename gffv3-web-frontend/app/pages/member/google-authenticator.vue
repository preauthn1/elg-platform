<script setup lang="ts">
/** Google 2FA 設定。邏輯在 useGoogleAuthSetting，畫面在 components/<皮>/Page/MemberGoogleAuthenticator.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })
</script>

<template>
  <SkinPage name="MemberGoogleAuthenticator" />
</template>
