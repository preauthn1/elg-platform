<script setup lang="ts">
/**
 * 活動 V2 詳情。event/list 的卡片帶 event_v2_id 時導到這裡（?ids=<event_detail_id>）。
 * 之前這個路由不存在，所有 V2 活動卡點了都是 404。
 *
 * 三段：活動內容（後台 HTML）→ 參加資格（後端 message／validate）→ 已取得的 bonus code。
 * 參加成功後依 redirect_path 導頁：外部連結開新視窗、站內路徑用 navigateTo、沒有就回首頁。
 */
const {
  ct,
  detail,
  hasBonusCodes,
  joining,
  eventDetailId,
  loading,
  banner,
  reloadDetail,
  canJoin,
  participationStatus,
  joinLabel,
  join,
} = useEventsPlatformEventPage()

/** 骨架的出現時機由 useSkeleton 決定，不可以直接綁 loading（見該 composable 的說明） */
const showSkeleton = useSkeleton(() => loading.value)
</script>

<template>
  <UiModal @close="backOr('/promotions')">
    <!-- UiModal 的內容區是無間距的 flex-col，段落間距由這裡給 -->
    <div class="flex flex-col gap-(--c17-gap-4)">
      <!-- 載入中用骨架，不要只印「載入中」：段落數抓得跟實際內容差不多，換上真內容時不會跳 -->
      <template v-if="showSkeleton">
        <UiSkeleton class="aspect-[39/14] w-full rounded-lg" />
        <UiSkeleton :rows="6" />
      </template>

      <p
        v-else-if="eventDetailId === null"
        class="text-muted"
      >
        {{ ct('events.list.empty.noEvents') }}
      </p>

      <template v-else>
        <EventsLanguageChips @change="reloadDetail" />

        <!-- Banner：設計稿 Promo-pop out 的 promo1，318×130、圓角 8 -->
        <img
          v-if="banner"
          :src="banner"
          alt=""
          class="block aspect-[318/130] w-full rounded-(--c17-radius-sm) object-cover"
        >

        <!-- 活動期間：設計稿是 26×26 圖示 + 藍色標籤 + 深色內容 -->
        <div
          v-if="detail?.end_time"
          class="flex items-center gap-(--c17-gap-2)"
        >
          <img
            src="/skins/c17/promo/period.webp"
            alt=""
            class="size-[26px] shrink-0 object-contain"
          >
          <div class="min-w-0">
            <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-primary)' }">
              {{ ct('events.detail.label.eventPeriod') }}
            </p>
            <p :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }">
              ~ {{ detail.end_time }}
            </p>
          </div>
        </div>

        <!-- 設計稿在活動期間與內文之間有一條螢光綠細線（Line 41，W318 #D8FF1A 1pt） -->
        <span
          class="block h-px"
          :style="{ background: 'var(--c17-accent)' }"
          aria-hidden="true"
        />

        <!-- 後台編輯器存的 HTML，照舊前台呈現 -->
        <!-- eslint-disable vue/no-v-html -->
        <article
          v-if="detail?.content"
          class="whitespace-pre-line"
          :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
          v-html="detail.content"
        />
        <!-- eslint-enable vue/no-v-html -->

        <!--
          參加狀態。舊專案 C13 的 PlatformEvent 就是「參加狀態 : xxx」一行紅字
          （.participation-status { color: red }），內容是 message 為空且 validate 時
          顯示「符合參加資格」，否則直接顯示後端的 message，不自己判斷條件。
        -->
        <p
          class="rounded-(--c17-field-radius) border border-field-line px-(--c17-gap-3) py-(--c17-gap-2) font-semibold"
          :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-error)' }"
        >
          {{ ct('events.list.label.participationStatus') }} : {{ participationStatus }}
        </p>

        <!-- 設計稿 Promo-pop out 底部那顆滿版主要按鈕（CLAIM NOW），用共用的 UiButton -->
        <UiButton
          :disabled="!canJoin"
          :loading="joining"
          @click="join"
        >
          {{ joinLabel }}
        </UiButton>

        <section
          v-if="hasBonusCodes"
          class="space-y-2"
        >
          <h2 class="font-semibold">
            {{ ct('wallet.exchange.label.promoCode') }}
          </h2>
          <ul class="divide-y divide-line rounded border border-line">
            <li
              v-for="row in detail?.bonus_code ?? []"
              :key="row.bonus_code"
              class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
            >
              <span class="font-mono">{{ row.bonus_code }}</span>
              <span class="text-xs text-muted">{{ row.created_at }}</span>
            </li>
          </ul>
        </section>
      </template>
    </div>
  </UiModal>
</template>
