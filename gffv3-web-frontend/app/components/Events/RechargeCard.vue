<script setup lang="ts">
import type { LegacyEventRow } from '#shared/api/endpoints/events-core'
import { describeRewardAmount, hidesExchangeButton, rechargeButtonState } from '~/utils/events-core'

/**
 * 儲值類活動卡（舊皮 EventRechargeItem，detail[0].action_id 51～61）。
 * 這一類的按鈕**不是**通用的「立即兌換／進度未達成」，而是七段狀態機：
 * 還沒儲值就直接顯示「儲值」並導到儲值頁，領過了顯示「已兌換」，
 * 獎項發完顯示「獎項已領取完畢」，action 59 當天達標顯示「今日已達標」。
 */
const props = defineProps<{ event: LegacyEventRow }>()
const emit = defineEmits<{ done: [] }>()

const { t } = useI18n()
const { settings } = useSite()
const { drawPrize } = usePrize()
const legacy = useLegacyEvents()

const notice = ref('')
const choosing = ref(false)
const busy = ref(false)
/** 按下去才發現獎項在這段時間被領完：舊皮會把狀態強制壓成 6。 */
const soldOut = ref(false)

const names = computed(() => ({
  walletPoint: settings.value?.text_wallet_point ?? '',
  rollingChip: settings.value?.text_rolling_chip ?? '',
}))

const state = computed(() => (
  soldOut.value
    ? { textKey: 'events.claim.empty.prizesFullyClaimed', text: '', disabled: true, action: 'none' as const }
    : rechargeButtonState(props.event)
))

const showButton = computed(() => !hidesExchangeButton(props.event))
const isConsecutive = computed(() => Number(props.event.action_id) === 59)

const rewards = computed(() => (props.event.prize_content ?? []).map((prize, index) => {
  const value = describeRewardAmount(prize, names.value)
  return {
    key: prize.id ?? index,
    name: prize.name ?? '',
    value: 'i18n' in value ? t(value.i18n) : value.text,
    // number -1 是不限量，不顯示剩餘
    remaining: Number(prize.number) === -1 ? '' : `${prize.sent ?? 0} / ${prize.number ?? 0}`,
  }
}))

/** 全部獎項都有限量時，按下去先重抓一次確認還沒被領完（舊皮同樣的防呆）。 */
async function isSoldOut(): Promise<boolean> {
  const prizes = props.event.prize_content ?? []
  if (prizes.length === 0 || prizes.some(p => Number(p.number) === -1)) return false
  await legacy.fetchLegacyEvents()
  const fresh = legacy.legacyEvents.value.find(e => Number(e.id) === Number(props.event.id))
  const list = fresh?.prize_content ?? []
  return list.length > 0 && list.every(p => Number(p.sent) === Number(p.number))
}

async function click() {
  if (busy.value || state.value.disabled) return
  busy.value = true
  try {
    if (await isSoldOut()) {
      soldOut.value = true
      notice.value = t('events.claim.empty.prizesFullyClaimed')
      return
    }
    if (state.value.action === 'topup') {
      await navigateTo('/topup')
      return
    }
    if (state.value.action === 'wheel') {
      await navigateTo(`/events/lucky-wheel?ids=${props.event.id}`)
      return
    }
    if (state.value.action === 'choose') {
      choosing.value = true
      return
    }
    const res = await drawPrize({ event_id: Number(props.event.id), type: props.event.type })
    if (isApiOk(res)) emit('done')
  }
  finally {
    busy.value = false
  }
}
</script>

<template>
  <EventsLegacyCard :event="event">
    <!-- 連續儲值（action 59）的天數進度 -->
    <p
      v-if="isConsecutive"
      class="font-semibold"
      :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-primary)' }"
    >
      {{ $t('events.checkin.label.totalDays') }} {{ event.consecutive_day ?? 0 }} /
      {{ $t('events.checkin.label.consecutiveDays') }} {{ event.max_consecutive_day ?? 0 }}
    </p>

    <ul
      v-if="rewards.length"
      class="flex flex-col gap-(--c17-gap-1)"
      :style="{ fontSize: 'var(--c17-text-label)' }"
    >
      <li
        v-for="row in rewards"
        :key="row.key"
        class="flex flex-wrap items-baseline gap-x-(--c17-gap-2)"
      >
        <span :style="{ color: 'var(--c17-primary)' }">{{ row.name }}</span>
        <span
          class="font-semibold"
          :style="{ color: 'var(--c17-ink)' }"
        >{{ row.value }}</span>
        <span
          v-if="row.remaining"
          :style="{ color: 'var(--c17-muted)' }"
        >{{ $t('events.list.label.remainingPrizes') }} : {{ row.remaining }}</span>
      </li>
    </ul>

    <EventsNotice
      :message="notice"
      @close="notice = ''"
    />

    <UiButton
      v-if="showButton"
      :disabled="state.disabled || busy"
      @click="click"
    >
      {{ state.textKey ? $t(state.textKey) : state.text }}
    </UiButton>

    <EventsPrizeChooser
      v-if="choosing"
      :event="event"
      @close="choosing = false"
      @done="emit('done')"
    />
  </EventsLegacyCard>
</template>
