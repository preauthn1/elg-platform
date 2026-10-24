<script setup lang="ts">
import type { LegacyEventRow } from '#shared/api/endpoints/events-core'
import { decDiv, decFloorFixed, decMul } from '#shared/utils/amount'

/**
 * 輸分回扣活動卡（舊皮 EventLoseRebateItem，action_id 9）。
 *
 * 可領的是 `pay_out_detail`（上期／上上期業績）**不是** `detail`：一期一筆，
 * 各自一顆兌換鈕，按鈕文字直接用後端的 show_exchange_text，送 prize/draw-by-detail。
 */
const props = defineProps<{ event: LegacyEventRow }>()
const emit = defineEmits<{ done: [] }>()

const { t } = useI18n()
const { drawByDetail } = usePrize()

const busy = ref<number | null>(null)

const first = computed(() => props.event.detail?.[0] ?? null)
const percentage = computed(() => props.event.prize_content?.[0]?.point_percentage ?? 0)

/** 上期／上上期兩筆，標題不同其餘一樣。 */
const PERIOD_TITLE_KEYS = ['events.detail.title.lastWinLossSales', 'events.detail.title.winLossSalesBefore']

const periods = computed(() => (props.event.pay_out_detail ?? []).slice(0, 2).map((row, index) => ({
  key: row.id ?? index,
  id: Number(row.id ?? 0),
  title: t(PERIOD_TITLE_KEYS[index] ?? PERIOD_TITLE_KEYS[0]!),
  // 舊皮是 now_point × (percentage / 100)，顯示到小數 2 位
  amount: `${t('events.detail.hint.rebateBonusRate', {
    nowpoint: row.now_point ?? 0,
    percentage: percentage.value,
  })}=${decFloorFixed(decDiv(decMul(row.now_point ?? 0, percentage.value), 100, 6), 2)}`,
  awardDate: (row.end_time ?? '').slice(0, 10),
  buttonText: row.show_exchange_text ?? '',
  disabled: Number(row.show_exchange) !== 1,
})))

async function exchange(detailId: number) {
  if (busy.value !== null) return
  busy.value = detailId
  try {
    const res = await drawByDetail(Number(props.event.id), detailId)
    if (isApiOk(res)) emit('done')
  }
  finally {
    busy.value = null
  }
}
</script>

<template>
  <EventsLegacyCard :event="event">
    <template #content>
      <div class="flex flex-col gap-(--c17-gap-2)">
        <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-primary)' }">
          {{ $t('common.misc.label.description') }}
        </p>
        <!-- eslint-disable vue/no-v-html -->
        <div
          v-if="event.content"
          class="whitespace-pre-line font-semibold"
          :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }"
          v-html="event.content"
        />
        <!-- eslint-enable vue/no-v-html -->
        <template v-else>
          <p
            class="whitespace-pre-line font-semibold"
            :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }"
          >
            {{ $t('events.detail.text.rebateRules01') }}
          </p>
          <p
            class="whitespace-pre-line font-semibold"
            :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-error)' }"
          >
            {{ $t('events.detail.text.rebateRules02') }}
          </p>
        </template>
      </div>
    </template>

    <EventsDetailVendor
      v-if="first"
      :detail="first"
      with-game-type
    />

    <section
      v-for="period in periods"
      :key="period.key"
      class="flex flex-col gap-(--c17-gap-2) rounded-(--c17-field-radius) border border-field-line p-(--c17-gap-3)"
      :style="{ fontSize: 'var(--c17-text-label)' }"
    >
      <p class="flex flex-wrap items-baseline gap-x-(--c17-gap-2)">
        <span :style="{ color: 'var(--c17-primary)' }">{{ period.title }} :</span>
        <span
          class="font-semibold tabular-nums"
          :style="{ color: 'var(--c17-ink)' }"
        >{{ period.amount }}</span>
      </p>
      <p class="flex flex-wrap items-baseline gap-x-(--c17-gap-2)">
        <span :style="{ color: 'var(--c17-primary)' }">{{ $t('events.detail.label.awardDate') }} :</span>
        <span
          class="font-semibold"
          :style="{ color: 'var(--c17-ink)' }"
        >{{ period.awardDate }} {{ $t('events.detail.hint.reserved48Hours') }}</span>
      </p>
      <UiButton
        :disabled="period.disabled || busy !== null"
        @click="exchange(period.id)"
      >
        {{ period.buttonText }}
      </UiButton>
    </section>
  </EventsLegacyCard>
</template>
