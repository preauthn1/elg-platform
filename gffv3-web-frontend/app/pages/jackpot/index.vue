<script setup lang="ts">
/** 畫面在 components/<皮>/Page/Jackpot.vue，邏輯在 useJackpotPage。 */
definePageMeta({
  requiresAuth: true,
  middleware: [
    () => {
      const { flag } = useSite()
      if (!flag('event_jackpot_visible')) return navigateTo('/')
    },
  ],
})
</script>

<template>
  <SkinPage name="Jackpot" />
</template>
