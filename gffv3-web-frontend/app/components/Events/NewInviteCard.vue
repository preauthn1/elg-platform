<script setup lang="ts">
import type { LegacyEventRow, NewInviteEvent } from '#shared/api/endpoints/events-core'
import { newInviteButtonState } from '~/utils/events-core'

const props = defineProps<{ event: LegacyEventRow }>()

const { fetchNewInvite, receiveNewInvite } = useLegacyEvents()
const invite = ref<NewInviteEvent | null>(null)
const busy = ref(false)

async function load() {
  const res = await fetchNewInvite(props.event.id)
  if (isApiOk(res)) invite.value = res.data
}

async function receive(prizeContentId: number | null | undefined) {
  if (prizeContentId == null || busy.value) return
  busy.value = true
  try {
    const res = await receiveNewInvite(prizeContentId)
    if (isApiOk(res)) await load()
  }
  finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <EventsLegacyCard :event="event">
    <div class="flex w-full flex-col gap-(--c17-gap-2)">
      <p
        v-if="invite?.code"
        :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
      >
        {{ $t('member.referralCode.label.code') }}:
        <span class="font-mono font-bold">{{ invite.code }}</span>
      </p>
      <ul class="flex flex-col gap-(--c17-gap-2)">
        <li
          v-for="(prize, index) in invite?.prize_contents ?? []"
          :key="prize.prize_content_id ?? index"
          class="flex items-center justify-between gap-(--c17-gap-2) rounded-(--c17-field-radius) border border-field-line px-(--c17-gap-3) py-(--c17-gap-2)"
          :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
        >
          <span class="min-w-0">
            {{ prize.name }}
            <span :style="{ color: 'var(--c17-muted)' }">{{ prize.text }}</span>
          </span>
          <button
            type="button"
            class="flex h-8 shrink-0 items-center rounded-(--c17-field-radius) border border-accent px-(--c17-gap-3) font-bold disabled:border-disabled disabled:text-disabled"
            :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-primary)' }"
            :disabled="!newInviteButtonState(prize.show_exchange).enabled || busy"
            @click="receive(prize.prize_content_id)"
          >
            {{ $t(newInviteButtonState(prize.show_exchange).textKey) }}
          </button>
        </li>
      </ul>
    </div>
  </EventsLegacyCard>
</template>
