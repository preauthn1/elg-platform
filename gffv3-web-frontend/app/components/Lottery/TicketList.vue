<script setup lang="ts">
import {
  lotteryPagePath,
  lotteryRowNumber,
  lotteryShowPagination,
  lotteryTicketClaimable,
  lotteryTicketLabel,
  lotteryTicketShowLevelIcon,
  type LotteryVariant,
} from '~/utils/lottery'

const props = defineProps<{ variant: LotteryVariant, issueId: number }>()

const lottery = useLottery(props.variant)
const { tickets, loading, page, perPage, total, pages, resultNumber } = lottery

const showPagination = computed(() => lotteryShowPagination(total.value, perPage))

function goPage(next: number) {
  if (next < 1 || (pages.value > 0 && next > pages.value)) return
  void lottery.fetchTickets(props.issueId, next)
}

onMounted(() => {
  void lottery.fetchTickets(props.issueId, 1)
})
</script>

<template>
  <section class="space-y-4">
    <NuxtLink
      :to="lotteryPagePath(variant, 'detail', issueId)"
      class="text-sm text-accent"
    >
      ← {{ $t('common.ui.nav.details') }}
    </NuxtLink>

    <h1 class="text-xl font-semibold">
      {{ $t('events.luckyDraw.label.myRaffleTicket') }}
    </h1>
    <p
      v-if="resultNumber"
      class="text-sm"
    >
      {{ $t('events.luckyDraw.label.winningNumber') }}: <span class="font-mono">{{ resultNumber }}</span>
    </p>

    <p
      v-if="loading"
      class="text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>

    <table
      v-if="tickets.length"
      class="w-full border-collapse text-sm"
    >
      <tbody>
        <tr
          v-for="(ticket, index) in tickets"
          :key="ticket.ticket_id"
          class="border-b border-line"
        >
          <td class="w-10 py-2 text-muted">
            {{ lotteryRowNumber(page, perPage, index) }}
          </td>
          <td class="py-2 font-mono">
            {{ ticket.ticket_number }}
          </td>
          <td class="py-2 text-right">
            <button
              type="button"
              class="rounded bg-accent px-3 py-1 text-inverse disabled:bg-muted disabled:opacity-60"
              :disabled="!lotteryTicketClaimable(ticket)"
              @click="lottery.receive(issueId, ticket.ticket_id)"
            >
              <span
                v-if="lotteryTicketShowLevelIcon(ticket)"
                class="mr-1 rounded bg-inverse/20 px-1 text-xs"
              >
                Lv{{ ticket.reward_level }}
              </span>
              {{ lotteryTicketLabel(ticket) }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>
    <p
      v-else-if="!loading"
      class="text-muted"
    >
      {{ $t('events.luckyDraw.empty.ticket') }}
    </p>

    <nav
      v-if="showPagination"
      class="flex items-center justify-center gap-4 text-sm"
    >
      <button
        type="button"
        class="rounded border border-line px-3 py-1 disabled:opacity-40"
        :disabled="page <= 1"
        @click="goPage(page - 1)"
      >
        {{ $t('common.pagination.action.previousPage') }}
      </button>
      <span>{{ page }} / {{ pages }}</span>
      <button
        type="button"
        class="rounded border border-line px-3 py-1 disabled:opacity-40"
        :disabled="page >= pages"
        @click="goPage(page + 1)"
      >
        {{ $t('common.pagination.nav.nextPage') }}
      </button>
    </nav>
  </section>
</template>
