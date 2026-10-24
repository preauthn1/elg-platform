<script setup lang="ts">
import type { LegacyEventRow } from '#shared/api/endpoints/events-core'
import { describeRewardAmount, hidesExchangeButton, inviteButtonState } from '~/utils/events-core'

/**
 * 邀請充值活動卡（舊皮 EventInviteRechargeItem，detail[0].action_id 16）。
 * 狀態機與註冊邀請同一套，差別在多三個人數統計，而且兌換時是 getAllPrize
 * ——這一類一次把達標的份全部領走。
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

const counters = computed(() => {
  const canReceive = Number(props.event.can_receive ?? 0)
  const hasReceive = Number(props.event.has_receive ?? 0)
  return [
    { key: 'can', label: t('events.claim.hint.prizesAvailable'), value: canReceive },
    { key: 'has', label: t('wallet.exchange.label.redeemCount'), value: hasReceive },
    { key: 'met', label: t('events.mission.label.targetMet'), value: canReceive + hasReceive },
  ]
})

const rewards = computed(() => {
  const list = props.event.prize_content ?? []
  return list.map((prize, index) => {
    const value = describeRewardAmount(prize, names.value)
    return {
      key: prize.id ?? index,
      label: `${t('common.amount.label.bonus')}${list.length > 1 ? index + 1 : ''}`,
      value: 'i18n' in value ? t(value.i18n) : value.text,
    }
  })
})

async function click() {
  if (busy.value || state.value.disabled) return
  if (state.value.action === 'choose') {
    choosing.value = true
    return
  }
  busy.value = true
  try {
    const res = await drawPrize({ event_id: Number(props.event.id), type: props.event.type, getAllPrize: true })
    if (isApiOk(res)) emit('done')
  }
  finally {
    busy.value = false
  }
}
</script>

<template>
  <EventsLegacyCard :event="event">
    <ul
      class="flex flex-col gap-(--c17-gap-1)"
      :style="{ fontSize: 'var(--c17-text-label)' }"
    >
      <li
        v-for="row in counters"
        :key="row.key"
        class="flex flex-wrap items-baseline gap-x-(--c17-gap-2)"
      >
        <span :style="{ color: 'var(--c17-primary)' }">{{ row.label }} :</span>
        <span
          class="font-semibold"
          :style="{ color: 'var(--c17-ink)' }"
        >{{ row.value }}</span>
      </li>
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
