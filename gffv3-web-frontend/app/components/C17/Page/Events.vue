<script setup lang="ts">
/**
 * 舊版（V1）活動列表。event/list 的卡片帶 event_v1_id 時導到這裡（?ids=…）。
 *
 * **這是彈窗式路由**（設計稿 Promo-pop out）：內容疊在促銷頁上，不是獨立一頁。
 * 仍然保留路由是為了深連結——直接開網址也進得來，只是背後沒有列表可退，
 * 關閉時由 backOr 退回 /promotions。
 *
 * 篩選規則全在 EventsLegacyList 裡（ids 優先、其次 preset、再來 query.category），
 * 這一頁只負責外殼。
 */
</script>

<template>
  <UiModal @close="backOr('/promotions')">
    <!-- UiModal 的內容區是無間距的 flex-col，段落間距由這裡給 -->
    <div class="flex flex-col gap-(--c17-gap-4)">
      <!--
        每日簽到在**自己的**彈窗（/events/checkin，設計稿 Daily-check in-pop out）。
        掛在這裡會讓每次開活動彈窗都打一次 checkin/records，那支跟活動無關。
      -->
      <EventsLegacyList />
    </div>
  </UiModal>
</template>
