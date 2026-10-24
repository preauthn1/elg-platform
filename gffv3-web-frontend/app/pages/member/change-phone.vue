<script setup lang="ts">
/** 修改手機。畫面在 components/<皮>/Page/MemberChangePhone.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true, middleware: 'site-flag', siteFlag: 'modify_mobile_visible' })
</script>

<template>
  <SkinPage name="MemberChangePhone" />
</template>
