<script setup lang="ts">
/** 修改信箱。畫面在 components/<皮>/Page/MemberChangeEmail.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true, middleware: 'site-flag', siteFlag: 'modify_email_visible' })
</script>

<template>
  <SkinPage name="MemberChangeEmail" />
</template>
