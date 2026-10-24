<script setup lang="ts">
import type { LegacyEventDetailRow } from '#shared/api/endpoints/events-core'
import { decFormat } from '#shared/utils/amount'
import { legacyProgressPercent } from '~/utils/events-core'

const { ct } = useContentI18n()

/**
 * V1 活動的一個達成條件（舊皮 EventItem／EventDescription 的 detail 區塊）：
 * 進度條 → 指定遊戲商 → 遊戲類型 → 目前進度 → 達成期限 →（說明頁才有）遊戲列表。
 * 進度條與目前進度由呼叫端依 action_id 決定要不要畫（legacyShowsProgress）。
 */
const props = defineProps<{
  detail: LegacyEventDetailRow
  showProgress: boolean
  /** 說明頁才有「遊戲列表」按鈕 */
  withGameList?: boolean
}>()

const { settings } = useSite()
const pointText = computed(() => settings.value?.text_wallet_point || '')

const percent = computed(() => legacyProgressPercent(props.detail))

/** 舊皮直接印後端的值，這裡只去掉尾端多餘的 0。 */
const progressText = computed(() => {
  const { now_point: now, point, action_name: actionName } = props.detail
  const unit = pointText.value ? ` ${pointText.value}` : ''
  const tail = actionName ? ` (${actionName})` : ''
  return `${decFormat(now ?? 0, 'trim')}${unit} / ${decFormat(point ?? 0, 'trim')}${unit}${tail}`
})

/** 起訖時間只顯示到分鐘（舊皮 substring(0, 16)）。 */
const timeLimit = computed(() => {
  const from = (props.detail.start_time ?? '').slice(0, 16)
  const to = (props.detail.end_time ?? '').slice(0, 16)
  return from || to ? `${from} ~ ${to}` : ''
})

const gameNames = computed(() => (props.detail.games ?? []).map(game => game.name ?? ''))
const gameListTitle = computed(() => `${props.detail.vendor_name ?? ''} (${props.detail.action_name ?? ''})`)
const showGames = ref(false)
</script>

<template>
  <div class="flex flex-col gap-(--c17-gap-2)">
    <!-- 進度條：圖示跟著進度走（舊皮 EventProgress） -->
    <div
      v-if="showProgress"
      class="relative py-[10px]"
    >
      <VipProgressBar
        :value="percent"
        :max="100"
      />
      <img
        src="/skins/c17/promo/progress.webp"
        alt=""
        class="absolute top-0 size-7 -translate-x-1/2 object-contain"
        :style="{ left: `${percent}%` }"
      >
    </div>

    <EventsDetailVendor :detail="detail" />

    <p
      v-if="showProgress"
      class="flex flex-wrap items-baseline gap-x-(--c17-gap-2)"
      :style="{ fontSize: 'var(--c17-text-label)' }"
    >
      <span :style="{ color: 'var(--c17-primary)' }">{{ ct('events.mission.label.currentProgress') }} :</span>
      <span
        class="font-semibold tabular-nums"
        :style="{ color: 'var(--c17-ink)' }"
      >{{ progressText }}</span>
    </p>

    <p
      v-if="timeLimit"
      class="flex flex-col gap-[2px]"
      :style="{ fontSize: 'var(--c17-text-label)' }"
    >
      <span :style="{ color: 'var(--c17-primary)' }">{{ ct('events.progressiveJackpot.label.timeLimit') }} :</span>
      <span
        class="font-semibold"
        :style="{ color: 'var(--c17-ink)' }"
      >{{ timeLimit }}</span>
    </p>

    <template v-if="withGameList && gameNames.length">
      <button
        type="button"
        class="flex h-8 items-center justify-between rounded-(--c17-field-radius) border border-accent px-(--c17-gap-3) font-bold"
        :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-primary)' }"
        @click="showGames = true"
      >
        {{ gameListTitle }}
        <span aria-hidden="true">▾</span>
      </button>
      <UiModal
        v-if="showGames"
        :title="gameListTitle"
        @close="showGames = false"
      >
        <ul
          class="flex flex-col gap-(--c17-gap-3)"
          :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }"
        >
          <li
            v-for="(name, index) in gameNames"
            :key="index"
          >
            {{ name }}
          </li>
        </ul>
      </UiModal>
    </template>
  </div>
</template>
