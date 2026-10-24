<script setup lang="ts">
import { decFormat, decIsPositive } from '#shared/utils/amount'
import { isOn } from '#shared/utils/site'

/**
 * 餘額膠囊 + 存款鈕。設計稿的頂部列（Home／Game-page）與遊戲進行頁（Game-playing）
 * 右側是**同一組**元件，所以只留這一份，兩邊都用它。
 *
 * 照設計稿（game-page 那張把這條 header 畫成向量，量得到精確值）：
 *   白底圓角 174×36 @ [200,8] r=8，陰影 2,2 模糊 1 #1a1f36/26%
 *   底色是**白色 80%**（--c17-header-pill），交付的 Group 58 合成圖量出來的
 *   （寬度用 min-width：正常餘額剛好是設計稿的 174，餘額很長時讓它往左長，
 *     不要為了對齊設計稿把金額截斷成看起來是別的數字）
 *   "MYR"   Plus Jakarta Sans Regular 12 #1565ff
 *   金額     Plus Jakarta Sans Bold 14 #1565ff，靠右
 *   存款鈕   36×36 r=8，**壓在白底的右端**（338..374 vs 白底 200..374），
 *           影子往左（-2,2）才不會壓在白底上
 *
 * 是白底膠囊 + 藍字 + 方形存款鈕，不是半透明灰膠囊、白字或圓形「＋」。
 */
const props = withDefaults(defineProps<{
  /**
   * 顯示哪一個餘額：
   * - main：主錢包 balance（首頁頂部列等一般頁面）
   * - vendor：遊戲商餘額 vendor_balance，給遊戲商頁（遊戲列表、APP 場館、聲明頁、遊戲頁）。
   *   照舊版 C13 Navbar 的 BalanceText：顯示的是 updateStateVendorBalance，**還沒有值（沒轉入過）就整顆不顯示**；
   *   單一錢包站轉入後主錢包是 0、錢都在遊戲商，顯示主錢包會看到 0。
   */
  source?: 'main' | 'vendor'
  /** 這個遊戲商的 giftcode。vendor 模式下 rolling_chip_visible 開、giftcode == 1、禮碼 > 0 才多顯示禮碼 */
  giftcode?: number | string | boolean | null
}>(), {
  source: 'main',
  giftcode: 0,
})

const { settings, flag } = useSite()
const wallet = useWalletStore()

const isVendor = computed(() => props.source === 'vendor')
const amount = computed(() => (isVendor.value ? wallet.vendorBalance : wallet.balance))
const visible = computed(() => !isVendor.value || wallet.vendorBalance !== null)
/** 主錢包與遊戲商餘額都標站台的 text_wallet_point（舊版遊戲商頁標「P」，C17 統一用站台單位） */
const currency = computed(() => settings.value?.text_wallet_point || '')
const balanceText = computed(() => decFormat(amount.value, 2))

const showRollingChip = computed(() =>
  isVendor.value && flag('rolling_chip_visible') && isOn(props.giftcode) && decIsPositive(wallet.rollingChip),
)
const rollingChipName = computed(() => settings.value?.text_rolling_chip || '')
const rollingChipText = computed(() => decFormat(wallet.rollingChip, 2))
</script>

<template>
  <div
    v-if="visible"
    class="flex items-center gap-(--c17-gap-2)"
  >
    <span
      v-if="showRollingChip"
      class="flex h-9 flex-col items-end justify-center rounded-[var(--c17-radius-sm)] px-[10px] leading-tight"
      :style="{ background: 'var(--c17-header-pill)', boxShadow: 'var(--c17-shadow-tile)', color: 'var(--c17-primary)' }"
    >
      <span
        class="max-w-[5rem] truncate"
        :style="{ fontSize: 'var(--c17-text-badge)' }"
      >{{ rollingChipName }}</span>
      <span
        class="whitespace-nowrap font-bold tabular-nums"
        :style="{ fontSize: 'var(--c17-text-link)' }"
      >{{ rollingChipText }}</span>
    </span>

    <div
      class="relative flex h-9 min-w-[174px] items-center justify-between rounded-[var(--c17-radius-sm)] pl-[10px] pr-[48px]"
      :style="{ background: 'var(--c17-header-pill)', boxShadow: 'var(--c17-shadow-tile)' }"
    >
      <span :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-primary)' }">{{ currency }}</span>
      <span
        class="ml-[var(--c17-gap-2)] truncate font-bold tabular-nums"
        :style="{ fontSize: 'var(--c17-text-body)', color: 'var(--c17-primary)' }"
      >{{ balanceText }}</span>
      <!--
      設計稿這顆鈕是 36×36 的 `fill IMAGE`，**「DEPOSIT」那行字烤在舊素材裡**。
      素材換成透明純 icon 之後字就不見了，所以要跟錢包那三顆磚一樣自己排回去：
      icon 在上、i18n 標籤在下。字級用 badge（8pt），36 寬才塞得下 14 個語系。
    -->
      <NuxtLink
        to="/topup"
        class="absolute right-0 top-0 flex size-9 flex-col items-center justify-center overflow-hidden rounded-[var(--c17-radius-sm)] bg-surface px-[1px]"
        :style="{ boxShadow: 'var(--c17-shadow-tile-left)' }"
        :aria-label="$t('deposit.entry.nav.deposit')"
      >
        <img
          src="/skins/c17/action/deposit.webp"
          alt=""
          class="size-[18px] shrink-0 object-contain"
        >
        <span
          class="line-clamp-1 w-full text-center font-bold uppercase"
          :style="{
            fontFamily: 'var(--c17-font-label)',
            fontSize: 'var(--c17-text-badge)',
            lineHeight: '1.1',
            color: 'var(--c17-ink)',
          }"
        >{{ $t('deposit.entry.nav.deposit') }}</span>
      </NuxtLink>
    </div>
  </div>
</template>
