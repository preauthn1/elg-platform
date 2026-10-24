<script setup lang="ts">
import type { ApiEnvelope } from '#shared/types/api'
import { decFormat } from '#shared/utils/amount'
import type { AmountLike } from '#shared/utils/amount'

/**
 * 錢包總覽。設計稿沒有這一張，所以沿用 C17 既有的語彙：
 * 深色標題列（C17PageHeader）＋ 螢光錢包卡（--c17-wallet-bg）＋ 白色方磚入口，
 * 與個人資料頁的錢包卡同一套，不要另外發明樣式。
 */

const { t } = useI18n()
const { flag, settings } = useSite()
const wallet = useWalletStore()
const { refreshBalance, transferAll, transferBackRecent } = useWallet()

const busy = ref(false)
const confirmRefresh = ref(false)

const isMultipleWallet = computed(() => flag('is_multiple_wallet'))
const rollingChipVisible = computed(() => flag('rolling_chip_visible'))
const couponVisible = computed(() => flag('coupon_code_visible'))
const pointName = computed(() => settings.value?.text_wallet_point || '')
const rollingChipName = computed(() => settings.value?.text_rolling_chip || '')

function display(value: AmountLike): string {
  return value === null || value === undefined ? '—' : decFormat(value, 2)
}

/** 依站台旗標決定要列幾條餘額；名稱優先用站台自訂的稱呼。 */
const balanceRows = computed(() => {
  const rows = [{
    key: 'main',
    label: pointName.value || t('wallet.overview.label.mainWallet'),
    value: display(wallet.balance),
  }]
  if (isMultipleWallet.value) {
    rows.push({ key: 'game', label: t('wallet.overview.label.gameWallet'), value: display(wallet.gameWalletBalance) })
  }
  if (rollingChipVisible.value) {
    rows.push({
      key: 'rolling',
      label: rollingChipName.value || t('common.amount.label.rollingChip'),
      value: display(wallet.rollingChip),
    })
  }
  return rows
})

/** 入口磚沿用個人資料頁那組圖示，沒有專屬素材就挑語意最近的。 */
const entries = computed(() => [
  ...(isMultipleWallet.value
    ? [{ key: 'transfer', to: '/wallet/transfer', icon: 'transfer', label: t('wallet.transfer.title.transfer') }]
    : []),
  { key: 'records', to: '/wallet/records', icon: 'transaction-record', label: t('wallet.history.title.record') },
  ...(couponVisible.value
    ? [{ key: 'coupon', to: '/wallet/coupon', icon: 'code-redeem', label: t('wallet.exchange.title.coupon') }]
    : []),
])

async function run(action: () => Promise<ApiEnvelope<unknown>>) {
  busy.value = true
  try {
    await action()
  }
  finally {
    busy.value = false
  }
}

async function onConfirmRefresh() {
  confirmRefresh.value = false
  await run(transferAll)
}

onMounted(() => {
  void run(refreshBalance)
})
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('wallet.overview.title.wallet')"
      fallback="/member"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-tile-gap) px-(--c17-pad-x) py-(--c17-gap-4)">
      <!-- 餘額卡：與個人資料頁的錢包卡同一顆底 -->
      <section
        class="rounded-(--c17-radius-sm) p-(--c17-gap-3)"
        :style="{ background: 'var(--c17-wallet-bg)', boxShadow: 'var(--c17-shadow-card)' }"
      >
        <dl class="flex flex-col gap-(--c17-gap-2)">
          <div
            v-for="row in balanceRows"
            :key="row.key"
            class="flex h-7 items-center gap-(--c17-gap-2) rounded-(--c17-radius-sm) px-5"
            :style="{ background: 'var(--c17-profile-pill-bg)', boxShadow: 'var(--c17-shadow-panel)' }"
          >
            <dt :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }">
              {{ row.label }}
            </dt>
            <dd
              class="ml-auto truncate font-bold tabular-nums"
              :style="{ fontSize: 'var(--c17-text-title)', color: 'var(--c17-primary)' }"
            >
              {{ row.value }}
            </dd>
          </div>
        </dl>

        <div class="mt-(--c17-gap-3) grid grid-cols-2 gap-x-(--c17-gap-3)">
          <UiButton
            :disabled="busy || wallet.transferring"
            @click="confirmRefresh = true"
          >
            {{ $t('wallet.transfer.action.transferAll') }}
          </UiButton>
          <UiButton
            variant="secondary"
            :disabled="busy || wallet.transferring"
            @click="run(transferBackRecent)"
          >
            {{ $t('wallet.transfer.action.reorganize') }}
          </UiButton>
        </div>
      </section>

      <!-- 功能入口：白色方磚，與個人資料頁的功能格同一套 -->
      <ul class="grid grid-cols-4 gap-x-(--c17-gap-3) gap-y-[14px]">
        <li
          v-for="entry in entries"
          :key="entry.key"
        >
          <NuxtLink
            :to="entry.to"
            class="flex aspect-square flex-col items-center justify-center gap-1 rounded-(--c17-radius-sm) px-1"
            :style="{ background: 'var(--c17-surface)', boxShadow: 'var(--c17-shadow-panel)' }"
          >
            <img
              :src="`/skins/c17/profile/${entry.icon}.webp`"
              alt=""
              class="size-[26px] shrink-0 object-contain"
            >
            <span
              class="line-clamp-2 h-[26px] w-full text-center font-semibold leading-[13px]"
              :style="{ fontSize: 'var(--c17-text-note)', color: 'var(--c17-ink)' }"
            >
              {{ entry.label }}
            </span>
          </NuxtLink>
        </li>
      </ul>
    </div>

    <UiModal
      v-if="confirmRefresh"
      :title="$t('common.ui.title.prompt')"
      @close="confirmRefresh = false"
    >
      <div class="flex flex-col gap-(--c17-gap-4)">
        <p :style="{ fontSize: 'var(--c17-text-body)', color: 'var(--c17-ink)' }">
          {{ $t('wallet.transfer.hint.balanceRecall') }}
        </p>
        <div class="flex gap-(--c17-gap-3)">
          <UiButton
            variant="secondary"
            @click="confirmRefresh = false"
          >
            {{ $t('common.ui.action.cancel') }}
          </UiButton>
          <UiButton @click="onConfirmRefresh">
            {{ $t('common.ui.action.confirm') }}
          </UiButton>
        </div>
      </div>
    </UiModal>
  </div>
</template>
