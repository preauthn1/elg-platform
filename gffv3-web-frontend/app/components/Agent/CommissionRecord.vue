<script setup lang="ts">
import { decFormat } from '#shared/utils/amount'

/**
 * 設計稿 Invite-Friends 的「MY COMMISSION RECORD」：日期／金額／狀態。
 * 資料與 /agent/collection-record 同一支（依代理模式打 withdrawal/history 或 withdrawals/histories）。
 */
const { history, historyPage, fetchHistory } = useAgentWithdrawal()
const { t } = useI18n()

const loading = ref(true)
/** 骨架的出現時機由 useSkeleton 決定，不可以直接綁 loading（見該 composable 的說明） */
const showSkeleton = useSkeleton(loading)

const columns = computed(() => [
  t('common.time.label.date'),
  t('common.amount.label.amount'),
  t('common.ui.label.status'),
])
const rows = computed(() => history.value.map(row => [row.date, decFormat(row.amount), String(row.status)]))

onMounted(async () => {
  try {
    await fetchHistory(1, historyPage.value.perPage)
  }
  finally {
    loading.value = false
  }
})
</script>

<template>
  <section class="flex flex-col gap-(--c17-gap-2)">
    <h2
      class="font-extrabold uppercase"
      :style="{
        fontFamily: 'var(--c17-font-display)',
        fontSize: 'var(--c17-text-title)',
        color: 'var(--c17-ink)',
      }"
    >
      {{ $t('agent.commission.title.myRecord') }}
    </h2>
    <UiTable
      :columns="columns"
      :rows="rows"
      :loading="showSkeleton"
    />
  </section>
</template>
