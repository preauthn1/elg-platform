<script setup lang="ts">
import type { LegacyEventRow } from '#shared/api/endpoints/events-core'

/**
 * V1 活動卡的外殼。版型與 V2 詳情彈窗（設計稿 Promo-pop out）同一套，
 * 由上到下是 banner → 活動期間 → 螢光綠細線 → 活動文案，之後接各類活動自己的內容。
 * 卡片本身沒有外框，外面的 UiModal 已經是白底的卡了。
 */
const props = defineProps<{ event: LegacyEventRow }>()

const { template } = useSite()

/** 可重複領獎的圖示：舊皮每張 V1 卡都有，P2 流程不顯示。 */
const repeatable = computed(() => Number(props.event.prize_is_repeatedly) === 1 && template.value?.process !== 'P2')

/** 起訖時間：列表用 start_time，少數活動只有 event_start_time；只顯示到分鐘（舊皮 substring(0, 16)）。 */
const period = computed(() => {
  const from = (props.event.start_time || props.event.event_start_time || '').slice(0, 16)
  const to = (props.event.end_time || props.event.event_end_time || '').slice(0, 16)
  return from || to ? `${from} ~ ${to}` : ''
})
</script>

<template>
  <article class="flex flex-col gap-(--c17-gap-3)">
    <!-- 設計稿的 promo1：318×130、圓角 8 -->
    <img
      v-if="event.image_url"
      :src="event.image_url"
      :alt="event.title ?? ''"
      class="block aspect-[318/130] w-full rounded-(--c17-radius-sm) object-cover"
    >

    <!-- 標題（舊皮每張 V1 卡的 event-title），可重複領獎時前面加圖示 -->
    <h2
      v-if="event.title"
      class="flex items-center gap-(--c17-gap-2) font-extrabold"
      :style="{ fontFamily: 'var(--c17-font-display)', fontSize: 'var(--c17-text-body)', color: 'var(--c17-ink)' }"
    >
      <img
        v-if="repeatable"
        src="/skins/c17/promo/repeatable.webp"
        :alt="$t('events.claim.label.repeatable')"
        class="h-7 w-7 shrink-0 object-contain"
      >
      {{ event.title }}
    </h2>

    <!-- 活動期間：26×26 圖示 ＋ 藍色小標 ＋ 值，圖示與小標間距 10 -->
    <div
      v-if="period"
      class="flex items-center gap-[10px]"
    >
      <img
        src="/skins/c17/promo/period.webp"
        alt=""
        class="size-[26px] shrink-0 rounded-(--c17-radius-xs) object-contain"
      >
      <span class="flex min-w-0 flex-1 flex-col gap-[2px]">
        <span
          class="font-semibold"
          :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-primary)' }"
        >{{ $t('events.detail.label.eventPeriod') }}</span>
        <span
          class="font-semibold"
          :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }"
        >{{ period }}</span>
      </span>
    </div>

    <span
      class="block h-px"
      :style="{ background: 'var(--c17-accent)' }"
      aria-hidden="true"
    />

    <!-- 活動文案。後台編輯器存的 HTML，照舊前台呈現；沒有 content 的活動由呼叫端覆寫 -->
    <slot name="content">
      <!-- eslint-disable vue/no-v-html -->
      <div
        v-if="event.content"
        class="whitespace-pre-line font-semibold"
        :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }"
        v-html="event.content"
      />
      <!-- eslint-enable vue/no-v-html -->
    </slot>

    <slot />
  </article>
</template>
