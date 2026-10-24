<script setup lang="ts">
import type { LegacyEventRow } from '#shared/api/endpoints/events-core'
import { describeRewardAmount, hidesExchangeButton, inviteButtonState } from '~/utils/events-core'

/**
 * 註冊邀請活動卡（舊皮 EventInviteItem，detail[0].action_id 31）。
 * 三段狀態：可兌換／已兌換／未達成，只有可兌換那段能按。
 * action 31 且 auto_payout=1 時獎金自動派發，整顆按鈕不出現。
 */
const props = defineProps<{ event: LegacyEventRow }>()
const emit = defineEmits<{ done: [] }>()

const { t } = useI18n()
const { settings } = useSite()
const { drawPrize } = usePrize()

const choosing = ref(false)
const busy = ref(false)

const names = computed(() => ({
  walletPoint: settings.value?.text_wallet_point ?? '',
  rollingChip: settings.value?.text_rolling_chip ?? '',
}))

const state = computed(() => inviteButtonState(props.event))
const showButton = computed(() => !hidesExchangeButton(props.event))

const codes = computed(() => (props.event.detail ?? [])
  .map((detail, index) => ({ key: detail.id ?? index, code: detail.code ?? '' }))
  .filter(row => row.code !== ''))

const rewards = computed(() => (props.event.prize_content ?? []).map((prize, index) => {
  const value = describeRewardAmount(prize, names.value)
  return {
    key: prize.id ?? index,
    label: `${t('common.amount.label.bonus')}${index + 1}`,
    value: 'i18n' in value ? t(value.i18n) : value.text,
  }
}))

async function click() {
  if (busy.value || state.value.disabled) return
  if (state.value.action === 'choose') {
    choosing.value = true
    return
  }
  busy.value = true
  try {
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
    <p
      v-for="row in codes"
      :key="row.key"
      class="flex flex-wrap items-baseline gap-x-(--c17-gap-2)"
      :style="{ fontSize: 'var(--c17-text-label)' }"
    >
      <span :style="{ color: 'var(--c17-primary)' }">{{ $t('agent.promote.label.promoteCode') }} :</span>
      <span
        class="font-mono font-bold"
        :style="{ color: 'var(--c17-ink)' }"
      >{{ row.code }}</span>
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
        <span :style="{ color: 'var(--c17-primary)' }">{{ row.label }} :</span>
        <span
          class="font-semibold"
          :style="{ color: 'var(--c17-ink)' }"
        >{{ row.value }}</span>
      </li>
    </ul>

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
