<script setup lang="ts">
/**
 * 邀請朋友（設計稿 Invite-Friends，第 25 張畫板）。
 *
 * 設計稿把舊的分頁式代理中心攤成一頁往下捲：
 *   banner ＋ 推薦連結 → 分享列 → 本期佣金 → 四格人數 → 佣金記錄 → 業績查詢 → 團隊管理
 *
 * 設計稿沒有畫「我的推廣／代理提領／代理規則」這幾個入口，但那三頁只有這裡連得到，
 * 拿掉等於整個功能消失，所以收在最後一張入口卡。卡片沿用同頁表格的框線與底色，
 * 每列「標題＋右箭頭」，箭頭是頂部列返回鍵的同一個圖形翻過來。
 */

const { t } = useI18n()
const { isThree } = useAgentMode()

const entries = computed(() => [
  { to: '/agent/promote', label: t(isThree.value ? 'agent.center.label.myRebate' : 'agent.center.label.myPromote') },
  { to: '/agent/withdrawal', label: t('agent.center.label.myWithdrawal') },
  { to: isThree.value ? '/agent/rebate' : '/agent/rules', label: t('agent.rules.title.regulation') },
])
</script>

<template>
  <div class="flex flex-col gap-(--c17-gap-4) px-(--c17-pad-x) py-(--c17-gap-3)">
    <AgentTeamSummary />
    <AgentCommissionRecord />
    <AgentTeamPerformance />
    <AgentTeamManagement />

    <nav class="overflow-hidden rounded-(--c17-radius-sm) border border-field-line bg-surface-2">
      <NuxtLink
        v-for="(entry, index) in entries"
        :key="entry.to"
        :to="entry.to"
        class="flex h-11 items-center justify-between px-(--c17-gap-3) font-bold"
        :class="index > 0 ? 'border-t border-line' : ''"
        :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
      >
        {{ entry.label }}
        <svg
          viewBox="0 0 8 12"
          class="h-3 w-2 -scale-x-100"
          fill="currentColor"
          :style="{ color: 'var(--c17-primary)' }"
          aria-hidden="true"
        >
          <path d="M6.1 0 7.5 1.4 2.9 6l4.6 4.6L6.1 12 .1 6z" />
        </svg>
      </NuxtLink>
    </nav>
  </div>
</template>
