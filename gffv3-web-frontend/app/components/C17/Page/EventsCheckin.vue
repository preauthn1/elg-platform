<script setup lang="ts">
/**
 * 每日簽到（設計稿 Daily-check in-pop out）。
 *
 * 遮罩、關閉鈕、標題與活動晶片是四張活動彈窗共用的外殼，抽在
 * EventsActivityShell，量到的值寫在那邊；這一頁只負責中間那張白卡。
 */
</script>

<template>
  <EventsActivityShell
    activity="dailyCheckin"
    fallback="/member"
  >
    <EventsCheckinWidget />
  </EventsActivityShell>
</template>
