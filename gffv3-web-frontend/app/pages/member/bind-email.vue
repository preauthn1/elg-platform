<script setup lang="ts">
/** 綁定信箱。畫面在 components/<皮>/Page/MemberBindEmail.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true, middleware: 'site-flag', siteFlag: 'binding_email_visible' })
</script>

<template>
  <SkinPage name="MemberBindEmail" />
</template>
