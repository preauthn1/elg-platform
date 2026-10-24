<script setup lang="ts">
/** 畫面在 components/<皮>/Page/EventsPreset.vue，邏輯在 useEventsPresetPage。 */
definePageMeta({
  requiresAuth: true,
  validate: route => isLegacyEventPreset(route.params.preset),
})
</script>

<template>
  <SkinPage name="EventsPreset" />
</template>
