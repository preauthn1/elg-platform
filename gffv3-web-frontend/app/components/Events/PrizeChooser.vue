<script setup lang="ts">
import type { LegacyEventRow } from '#shared/api/endpoints/events-core'
import { filterChoosablePrizeContents } from '~/utils/events-core'

/**
 * 自選獎項（prize type 8）。舊皮 MultiplePrize：列出還有名額的獎項，選一個送 prize/choose。
 * 儲值、邀請、邀請充值、下線儲值四種卡都會用到，所以抽成共用元件。
 */
const props = defineProps<{ event: LegacyEventRow }>()
const emit = defineEmits<{ close: [], done: [] }>()

const { choosePrize } = usePrize()
const alert = useAlert()

const choosable = computed(() => filterChoosablePrizeContents(props.event.prize_content ?? []))
const selected = ref<number | null>(choosable.value[0]?.id ?? null)
const busy = ref(false)

async function confirm() {
  if (selected.value == null || busy.value) return
  busy.value = true
  try {
    const res = await choosePrize(Number(props.event.id), selected.value)
    // 失敗的彈窗由 useApi 全域處理；成功要告知選到了
    if (isApiOk(res)) alert.showResponse(res)
    emit('done')
    emit('close')
  }
  finally {
    busy.value = false
  }
}
</script>

<template>
  <UiModal
    :title="$t('events.depositReward.title.selectReward')"
    :dismissible="false"
    @close="emit('close')"
  >
    <div class="flex flex-col gap-(--c17-gap-3)">
      <label
        v-for="(prize, index) in choosable"
        :key="prize.id ?? index"
        class="flex items-center gap-(--c17-gap-2)"
        :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
      >
        <input
          v-model="selected"
          type="radio"
          :value="prize.id"
          class="accent-[var(--c17-primary)]"
        >
        <span>{{ prize.name }}</span>
      </label>

      <div class="flex gap-(--c17-gap-2)">
        <UiButton
          :disabled="busy || selected == null"
          @click="confirm"
        >
          {{ $t('common.ui.action.confirm') }}
        </UiButton>
        <UiButton
          variant="secondary"
          @click="emit('close')"
        >
          {{ $t('common.ui.action.cancel') }}
        </UiButton>
      </div>
    </div>
  </UiModal>
</template>
