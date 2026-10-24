<script setup lang="ts">
import { decFormat } from '#shared/utils/amount'
import type { AmountLike } from '#shared/utils/amount'

/**
 * 錢包面板：底部分頁列中間那顆圓鈕打開的就是這個，不再直接跳 /wallet。
 *
 * 版面照舊前台 C14 的 `Components/Modal/WalletModal.vue`：
 *   標題「主錢包」＋重新整理 → 餘額卡（主錢包／遊戲錢包／流水）→ 充值、提款、轉帳、歷史紀錄
 * 各項的顯示條件也照舊：轉帳與遊戲錢包只在 is_multiple_wallet 開啟時出現、
 * 提款吃 withdrawal_button_visible、流水吃 rolling_chip_visible。
 * 外觀走 C17（白卡、圓角 8、--c17-shadow-panel），不是 C14 的深色。
 */
const emit = defineEmits<{ close: [] }>()

const { t } = useI18n()
const { flag, settings } = useSite()
const wallet = useWalletStore()
const { refreshBalance } = useWallet()

const refreshing = ref(false)

const isMultipleWallet = computed(() => flag('is_multiple_wallet'))
const rollingChipVisible = computed(() => flag('rolling_chip_visible'))
const withdrawalVisible = computed(() => flag('withdrawal_button_visible'))

const pointName = computed(() => settings.value?.text_wallet_point || t('wallet.overview.label.mainWallet'))
const rollingChipName = computed(() => settings.value?.text_rolling_chip || t('common.amount.label.rollingChip'))

function display(value: AmountLike): string {
  return value === null || value === undefined ? '—' : decFormat(value, 2)
}

/** 主錢包一定有；其餘兩條看站台旗標。 */
const cards = computed(() => {
  const rows = [{ key: 'main', label: pointName.value, value: display(wallet.balance) }]
  if (isMultipleWallet.value) {
    rows.push({ key: 'game', label: t('wallet.overview.label.gameWallet'), value: display(wallet.gameWalletBalance) })
  }
  if (rollingChipVisible.value) {
    rows.push({ key: 'rolling', label: rollingChipName.value, value: display(wallet.rollingChip) })
  }
  return rows
})

const actions = computed(() => [
  { key: 'deposit', to: '/topup', icon: 'deposit', label: t('deposit.entry.nav.deposit') },
  ...(withdrawalVisible.value
    ? [{ key: 'withdraw', to: '/withdrawal', icon: 'withdraw', label: t('withdrawal.common.label.withdrawal') }]
    : []),
  ...(isMultipleWallet.value
    ? [{ key: 'transfer', to: '/wallet/transfer', icon: 'transfer', label: t('wallet.transfer.title.transfer') }]
    : []),
  // 這一格指的是交易紀錄，不是錢包明細；錢包明細的入口留在 /wallet 頁上
  { key: 'history', to: '/record/transaction', icon: 'transaction-record', label: t('records.transaction.title.record') },
])

async function onRefresh() {
  if (refreshing.value) return
  refreshing.value = true
  try {
    await refreshBalance()
  }
  finally {
    refreshing.value = false
  }
}

async function go(to: string) {
  emit('close')
  await navigateTo(to)
}

onMounted(() => {
  if (wallet.balance === null) void refreshBalance()
})
</script>

<template>
  <UiModal
    :title="$t('wallet.overview.label.mainWallet')"
    @close="emit('close')"
  >
    <template #title-action>
      <button
        type="button"
        class="shrink-0 disabled:opacity-40"
        :disabled="refreshing"
        :aria-label="$t('auth.captcha.action.refresh')"
        @click="onRefresh"
      >
        <img
          src="/skins/c17/profile/refresh.webp"
          alt=""
          class="size-[22px] object-contain"
          :class="refreshing ? 'animate-spin' : ''"
        >
      </button>
    </template>

    <div class="flex flex-col gap-(--c17-gap-3)">
      <div
        v-for="card in cards"
        :key="card.key"
        class="rounded-(--c17-radius-sm) px-(--c17-gap-3) py-(--c17-gap-2) text-center"
        :style="{ background: 'var(--c17-wallet-bg)', boxShadow: 'var(--c17-shadow-card)' }"
      >
        <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }">
          {{ card.label }}
        </p>
        <p
          class="font-bold tabular-nums"
          :style="{ fontSize: 'var(--c17-text-lg)', color: 'var(--c17-primary)' }"
        >
          {{ card.value }}
        </p>
      </div>

      <div class="grid grid-cols-3 gap-x-(--c17-gap-3) gap-y-(--c17-tile-gap)">
        <button
          v-for="action in actions"
          :key="action.key"
          type="button"
          class="flex h-[60px] flex-col items-center justify-center gap-px rounded-(--c17-radius-sm) px-1"
          :style="{ background: 'var(--c17-profile-tile-bg)', boxShadow: 'var(--c17-shadow-panel)' }"
          @click="go(action.to)"
        >
          <img
            :src="`/skins/c17/profile/${action.icon}.webp`"
            alt=""
            class="size-[26px] shrink-0 object-contain"
          >
          <span
            class="w-full truncate text-center font-semibold"
            :style="{ fontSize: 'var(--c17-text-note)', color: 'var(--c17-ink)' }"
          >
            {{ action.label }}
          </span>
        </button>
      </div>
    </div>
  </UiModal>
</template>
