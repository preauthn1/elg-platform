<script setup lang="ts">
/** C17 標籤清單（熱門／最新／推薦）。清單與 /game/tag/:tag 共用 GAME_TAGS。 */
const tags = GAME_TAGS
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-xl font-semibold">
      {{ $t('site.nav.nav.games') }}
    </h1>
    <ul class="grid grid-cols-1 gap-3 sm:grid-cols-3">
      <li
        v-for="item in tags"
        :key="item.tag"
      >
        <NuxtLink
          :to="`/game/tag/${item.tag}`"
          class="block rounded border border-line bg-surface p-4 text-center font-medium"
        >
          {{ $t(item.titleKey) }}
        </NuxtLink>
      </li>
    </ul>
  </div>
</template>
