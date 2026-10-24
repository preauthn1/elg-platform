<script setup lang="ts">
/**
 * 活動別名路由。舊皮 C13 的 /v-rebate、/w-rebate、/w-jackpot、/w-jackpot6d、
 * /lose-rebate、/wtt-sports（`_source2/src/router/C13.js:194-246`）掛的都是同一個
 * Events 元件，差別只在把列表限縮成某一類活動，所以這裡也只有一頁。
 * 限縮規則早就寫在 `utils/events-core.ts` 的 PRESET_MAP，缺的只是進得來的路由。
 *
 * /events/v-jackpot 另外有自己的頁（多一條往抽獎券期數的連結）。
 * 靜態路由優先於動態參數，不會被這支蓋掉。
 *
 * validate 擋掉清單外的值：/events/<亂打> 走 404，而不是渲染一頁空列表。
 */
const {
  preset,
  title,
} = useEventsPresetPage()
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-lg font-semibold">
      {{ title }}
    </h1>
    <EventsLegacyList :preset="preset" />
  </div>
</template>
