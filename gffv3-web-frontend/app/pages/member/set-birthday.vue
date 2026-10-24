<script setup lang="ts">
/** 設定生日。邏輯在 useSetBirthday，畫面在 components/<皮>/Page/MemberSetBirthday.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })
</script>

<template>
  <SkinPage name="MemberSetBirthday" />
</template>
