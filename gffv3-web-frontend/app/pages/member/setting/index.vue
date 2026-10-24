<script setup lang="ts">
/** 設定選單。邏輯在 useSettingMenu，畫面在 components/<皮>/Page/MemberSetting.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })
</script>

<template>
  <SkinPage name="MemberSetting" />
</template>
