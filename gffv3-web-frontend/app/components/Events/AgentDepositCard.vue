<script setup lang="ts">
import type { LegacyEventRow } from '#shared/api/endpoints/events-core'
import { PRIZE_TYPE, validateResult } from '~/utils/events-core'

/**
 * 下線儲值活動卡（舊皮 EventAgentDepositItem，detail[0].action_id 60）。
 * 能不能領不看 show_exchange，而是打 event/legacy-validate 問後端；
 * 不能領時後端的理由由 useApi 的全站彈窗顯示，卡上只鎖按鈕。按鈕文字固定是「立即兌換」。
 */
const props = defineProps<{ event: LegacyEventRow }>()
const emit = defineEmits<{ done: [] }>()

const { store, validateEvent } = useLegacyEvents()
const { drawPrize } = usePrize()

const isClaim = ref(false)
const choosing = ref(false)
const busy = ref(false)

/** action 60：驗證邀請人數達標；列表重抓（refreshEventStatusTick）時重打。 */
async function validate() {
  const res = await validateEvent(props.event.id)
  isClaim.value = validateResult(res).isClaim
}

onMounted(validate)
watch(() => store.refreshEventStatusTick, validate)

async function click() {
  if (!isClaim.value || busy.value) return
  if (Number(props.event.type) === PRIZE_TYPE.CHOOSE) {
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
    <UiButton
      :disabled="!isClaim || busy"
      @click="click"
    >
      {{ $t('events.claim.action.redeem') }}
    </UiButton>

    <EventsPrizeChooser
      v-if="choosing"
      :event="event"
      @close="choosing = false"
      @done="emit('done')"
    />
  </EventsLegacyCard>
</template>
