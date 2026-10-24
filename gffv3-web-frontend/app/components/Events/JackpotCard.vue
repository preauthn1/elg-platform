<script setup lang="ts">
import type { LegacyEventRow } from '#shared/api/endpoints/events-core'
import { decFormat } from '#shared/utils/amount'
import {
  JACKPOT_DESC_KEY,
  JACKPOT_DRAW_LABEL,
  JACKPOT_ISSUES_PATH,
  JACKPOT_RESULT_URL,
  jackpotKind,
} from '~/utils/events-core'

/**
 * 抽獎類活動卡（舊皮 EventLotteryItem，type 4／5／6／7）。
 * 這一類沒有兌換鈕——獎是靠抽獎券開出來的，所以按鈕是「我的抽獎券」與「活動詳情」。
 */
const props = defineProps<{ event: LegacyEventRow }>()

const { t } = useI18n()

const kind = computed(() => jackpotKind(props.event.type))
const drawLabel = computed(() => {
  const label = JACKPOT_DRAW_LABEL[kind.value]
  return 'i18n' in label ? t(label.i18n) : label.text
})
const drawDate = computed(() => (props.event.prize_draw_date ?? '').slice(0, 10))
const description = computed(() => t(JACKPOT_DESC_KEY[kind.value], {
  point: decFormat(props.event.detail?.[0]?.point ?? 0, 'trim'),
}))
/** 48 小時內要領的提醒只有 W-Jackpot 系列有。 */
const showClaimDeadline = computed(() => kind.value !== 'luckyDraw')

function openResults() {
  if (import.meta.client) window.open(JACKPOT_RESULT_URL[kind.value], '_blank', 'noopener')
}
</script>

<template>
  <EventsLegacyCard :event="event">
    <template #content>
      <div class="flex flex-col gap-(--c17-gap-2)">
        <div class="flex items-center justify-between gap-(--c17-gap-2)">
          <span
            class="flex min-w-0 flex-col gap-[2px]"
            :style="{ fontSize: 'var(--c17-text-label)' }"
          >
            <span :style="{ color: 'var(--c17-primary)' }">{{ drawLabel }}</span>
            <span
              class="font-semibold"
              :style="{ color: 'var(--c17-ink)' }"
            >{{ drawDate }}</span>
          </span>
          <button
            type="button"
            class="flex h-8 shrink-0 items-center rounded-(--c17-field-radius) border border-accent px-(--c17-gap-3) font-bold"
            :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-primary)' }"
            @click="openResults"
          >
            {{ $t('events.luckyDraw.label.resultReference') }}
          </button>
        </div>

        <p
          class="whitespace-pre-line font-semibold"
          :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }"
        >
          {{ description }}
        </p>
        <p
          v-if="showClaimDeadline"
          class="whitespace-pre-line"
          :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-error)' }"
        >
          {{ $t('events.jackpot.text.claimDeadline') }}
        </p>
      </div>
    </template>

    <div class="flex gap-(--c17-gap-2)">
      <UiButton @click="navigateTo(JACKPOT_ISSUES_PATH[kind])">
        {{ $t('events.luckyDraw.label.myRaffleTicket') }}
      </UiButton>
      <UiButton
        variant="secondary"
        @click="navigateTo(`/events/description/${event.id}`)"
      >
        {{ $t('events.list.nav.eventDetails') }}
      </UiButton>
    </div>
  </EventsLegacyCard>
</template>
