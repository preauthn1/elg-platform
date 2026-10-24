<script setup lang="ts">
/**
 * V-Jackpot 活動頁。event/list 的 banner（params.type 4／5）導到這裡。
 * 舊皮的 /v-jackpot 也是掛同一個 Events 元件、只是把列表限縮到抽獎券類活動，
 * 所以這裡就是帶 preset 的 EventsLegacyList，不另做一套。
 *
 * 期數／抽獎券的操作在 /lucky-draw/issues（另一組頁面），活動卡本身會導過去。
 */
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-baseline justify-between gap-3">
      <h1 class="text-lg font-semibold">
        V-Jackpot
      </h1>
      <NuxtLink
        to="/lucky-draw/issues"
        class="text-sm text-accent"
      >
        {{ $t('events.jackpot.label.drawDate') }}
      </NuxtLink>
    </div>
    <EventsLegacyList preset="v-jackpot" />
  </div>
</template>
