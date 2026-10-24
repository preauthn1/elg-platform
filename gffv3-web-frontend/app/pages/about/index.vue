<script setup lang="ts">
/** 畫面在 components/<皮>/Page/About.vue，邏輯在 useAboutPage。 */
definePageMeta({
  requiresAuth: true,
  middleware: (to) => {
    const queryIndex = to.query.index
    if (typeof queryIndex === 'string' && queryIndex !== '') {
      return navigateTo(`/about/${queryIndex}`, { replace: true })
    }
  },
})
</script>

<template>
  <SkinPage name="About" />
</template>
