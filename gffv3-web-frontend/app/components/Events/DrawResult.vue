<script setup lang="ts">
import { getPrizeText } from '~/utils/events-core'

const emit = defineEmits<{ close: [] }>()

const { store, clearDraw } = usePrize()
const { settings } = useSite()
const { t } = useI18n()

const names = computed(() => ({
  walletPoint: settings.value?.text_wallet_point || t('common.amount.label.platformPoint'),
  rollingChip: settings.value?.text_rolling_chip || t('common.amount.label.rollingChip'),
}))
const draw = computed(() => store.lastDraw)

function close() {
  clearDraw()
  emit('close')
}
</script>

<template>
  <UiModal
    v-if="draw"
    :title="$t('events.jackpot.title.congratulations')"
    @close="close"
  >
    <div class="flex flex-col gap-(--c17-gap-4)">
      <div
        class="flex flex-col gap-(--c17-gap-2) text-center text-ink"
        :style="{ fontSize: 'var(--c17-text-body)' }"
      >
        <template v-if="draw.kind === 'single'">
          <p>{{ $t('events.common.label.rewardItem') }}: {{ draw.data.prize.name }}</p>
          <p v-if="store.rewardPoint != null">
            {{ $t('events.luckyDraw.label.collectibleAmount') }}: {{ store.rewardPoint }}
          </p>
          <p v-else>
            {{ $t('events.luckyDraw.label.award') }}: {{ getPrizeText(draw.data.prize, names) }}
          </p>
          <p
            v-if="draw.drawKind === 'scratch'"
            class="text-muted"
          >
            {{ $t('events.luckyDraw.label.scratchCard') }}
          </p>
        </template>
        <ul
          v-else
          class="space-y-1"
        >
          <li
            v-for="(prize, index) in draw.prizes"
            :key="prize.id ?? index"
          >
            {{ prize.name }} — {{ getPrizeText(prize, names) }}
          </li>
        </ul>
      </div>
      <UiButton @click="close">
        {{ $t('common.ui.action.determine') }}
      </UiButton>
    </div>
  </UiModal>
</template>
