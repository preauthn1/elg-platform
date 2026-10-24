<script setup lang="ts">
import { decFormat } from '#shared/utils/amount'

/**
 * 代理提領面板：總覽（withdrawal/record 或 withdrawals）、紀錄分頁、申請表單。
 * 模式分流與上限檢查都在 useAgentWithdrawal。
 */
const {
  itselfRebate,
  summary,
  history,
  historyPage,
  applyError,
  withdrawableLimit,
  canApply,
  fetchRecord,
  fetchHistory,
  apply,
} = useAgentWithdrawal()

const { t } = useI18n()
const tab = ref<'summary' | 'history'>('summary')
const showApply = ref(false)
const amount = ref('')
const alert = useAlert()

const summaryCells = computed(() => {
  const s = summary.value
  if (!s) return []
  return [
    { key: 'agent.center.label.account', value: s.username, show: true },
    { key: 'agent.withdrawal.label.pointTotal', value: decFormat(s.totalRewardPoints), show: true },
    { key: 'agent.withdrawal.msg.pointWithdrawn', value: decFormat(s.withdrawnPoints), show: true },
    { key: 'agent.withdrawal.label.pointWithdrawable', value: decFormat(s.withdrawablePoints), show: true },
    { key: 'agent.performance.label.todayTurnover', value: decFormat(s.flowAmount), show: itselfRebate.value },
    { key: 'agent.withdrawal.label.withdrawnRebate', value: decFormat(s.itselfWithdrawnPoints), show: itselfRebate.value },
    { key: 'agent.withdrawal.label.availableRebate', value: decFormat(s.itselfWithdrawablePoints), show: itselfRebate.value },
  ].filter(c => c.show)
})

const applyErrorText = computed(() => {
  switch (applyError.value) {
    case 'required':
    case 'min':
    case 'format':
      return t('common.amount.placeholder.amount')
    case 'exceed':
      return t('wallet.transfer.error.balanceInsufficient')
    default:
      return ''
  }
})

async function load() {
  await Promise.all([fetchRecord(), fetchHistory(1)])
}

function cancelApply() {
  showApply.value = false
  amount.value = ''
}

async function submitApply() {
  const res = await apply(amount.value)
  // 失敗的彈窗由 useApi 全域處理
  if (!res || !isApiOk(res)) return
  alert.show(t('common.ui.msg.applySuccess'), 'success')
  cancelApply()
}

const tabItems = computed(() => [
  { key: 'summary' as const, label: t('agent.withdrawal.nav.summary') },
  { key: 'history' as const, label: t('agent.withdrawal.nav.history') },
])

const historyColumns = computed(() => [
  t('common.time.label.time'),
  t('common.ui.label.orderNo'),
  t('common.amount.label.amount'),
  t('withdrawal.form.label.type'),
  t('common.ui.label.status'),
])
const historyRows = computed(() => history.value.map(row => [
  String(row.date),
  String(row.no),
  decFormat(row.amount),
  String(row.withdrawType ?? ''),
  String(row.status ?? ''),
]))

onMounted(load)
</script>

<template>
  <section class="flex flex-col gap-(--c17-gap-3)">
    <UiPillTabs
      v-model="tab"
      :items="tabItems"
    />

    <template v-if="tab === 'summary'">
      <!-- 總覽：同邀請好友頁四格人數的卡片（白底、圓角、panel 陰影），兩欄排 -->
      <ul
        v-if="summary"
        class="grid grid-cols-2 gap-(--c17-gap-3)"
      >
        <li
          v-for="cell in summaryCells"
          :key="cell.key"
          class="flex flex-col gap-(--c17-gap-1) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3)"
          :style="{ boxShadow: 'var(--c17-shadow-panel)' }"
        >
          <span
            class="font-semibold"
            :style="{ fontSize: 'var(--c17-text-note)', color: 'var(--c17-muted)' }"
          >{{ $t(cell.key) }}</span>
          <b
            class="truncate font-extrabold tabular-nums"
            :style="{ fontSize: 'var(--c17-text-body)', color: 'var(--c17-ink)' }"
          >{{ cell.value }}</b>
        </li>
      </ul>

      <UiButton
        v-if="canApply && !showApply"
        @click="showApply = true"
      >
        {{ $t('agent.withdrawal.action.apply') }}
      </UiButton>

      <form
        v-if="canApply && showApply"
        class="flex flex-col gap-(--c17-gap-3) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3)"
        :style="{ boxShadow: 'var(--c17-shadow-panel)' }"
        @submit.prevent="submitApply"
      >
        <h3
          class="font-bold"
          :style="{ fontSize: 'var(--c17-text-body)', color: 'var(--c17-ink)' }"
        >
          {{ $t('agent.withdrawal.title.apply') }}
        </h3>
        <UiTextField
          :model-value="decFormat(withdrawableLimit)"
          :label="$t('agent.withdrawal.label.pointRemaining')"
          disabled
        />
        <UiTextField
          v-model="amount"
          :label="$t('agent.withdrawal.label.applyAmount')"
          inputmode="decimal"
          :maxlength="12"
          :error="applyErrorText"
          :show-error="!!applyErrorText"
        />
        <div class="grid grid-cols-2 gap-(--c17-gap-3)">
          <UiButton
            variant="secondary"
            @click="cancelApply"
          >
            {{ $t('common.ui.action.cancel') }}
          </UiButton>
          <UiButton type="submit">
            {{ $t('common.ui.action.submit') }}
          </UiButton>
        </div>
      </form>
    </template>

    <template v-else>
      <div class="overflow-x-auto">
        <UiTable
          :columns="historyColumns"
          :rows="historyRows"
          class="min-w-[450px]"
        />
      </div>
      <AgentPagination
        :page="historyPage"
        @change="fetchHistory($event, historyPage.perPage)"
      />
    </template>
  </section>
</template>
