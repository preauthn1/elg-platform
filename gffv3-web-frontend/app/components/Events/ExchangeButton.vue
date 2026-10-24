<script setup lang="ts">
import type { LegacyEventRow } from '#shared/api/endpoints/events-core'
import { exchangeButtonState } from '~/utils/events-core'

const { ct } = useContentI18n()

const props = defineProps<{ event: LegacyEventRow }>()
const emit = defineEmits<{ done: [] }>()

const { drawPrize } = usePrize()
const state = computed(() => exchangeButtonState(props.event))
const confirming = ref(false)
const busy = ref(false)

async function exchange(getAllPrize: boolean) {
  confirming.value = false
  if (busy.value) return
  busy.value = true
  try {
    const res = await drawPrize({
      event_id: props.event.id,
      type: props.event.type,
      action_id: props.event.action_id,
      reward_point: props.event.reward_point,
      getAllPrize,
    })
    // 失敗的彈窗由 useApi 全域處理
    if (isApiOk(res)) emit('done')
  }
  finally {
    busy.value = false
  }
}

function onClick() {
  if (state.value.isWheel) return navigateTo(`/events/lucky-wheel?ids=${props.event.id}`)
  if (state.value.askClaimAll) {
    confirming.value = true
    return
  }
  return exchange(false)
}
</script>

<template>
  <div class="flex flex-col gap-(--c17-gap-2)">
    <UiButton
      :disabled="state.disabled || busy"
      @click="onClick"
    >
      {{ ct(state.textKey) }}
    </UiButton>
    <div
      v-if="confirming"
      class="flex flex-col gap-(--c17-gap-2) rounded-(--c17-field-radius) border border-field-line p-(--c17-gap-3)"
    >
      <p :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }">
        {{ ct('events.claim.msg.claimAllConfirm') }}
      </p>
      <div class="flex gap-(--c17-gap-2)">
        <UiButton @click="exchange(true)">
          {{ ct('common.ui.action.confirm') }}
        </UiButton>
        <UiButton
          variant="secondary"
          @click="confirming = false"
        >
          {{ ct('common.ui.action.cancel') }}
        </UiButton>
      </div>
    </div>
  </div>
</template>
