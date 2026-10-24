<script setup lang="ts">
/** 畫面在 components/<皮>/Page/EventsRedPacket.vue，邏輯在 useEventsRedPacketPage。 */
definePageMeta({ requiresAuth: true, fullBleed: true, key: route => route.fullPath })
</script>

<template>
  <SkinPage name="EventsRedPacket" />
</template>
