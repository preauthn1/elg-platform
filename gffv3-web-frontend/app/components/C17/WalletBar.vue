<script setup lang="ts">
import { decFormat } from '#shared/utils/amount'

/**
 * 首頁錢包列：餘額加三個入口。
 * 客服暫時導 /cs（客服是另一個專案，之後併進來再換成正式入口）。
 * 分享導到邀請朋友頁（設計稿 Invite-Friends），不是只有 QR 的那一頁。
 *
 * 素材只有純圖示，三個標籤都走 i18n。**挑 key 的條件是 14 個語系都有值**，
 * 少一個語系就會露出英文——例如 agent.promote.action.share 只有 en / zh-tw，
 * 所以分享用 events.invite.action.inviteFriends。
 * tests/unit/i18n-entry-labels.spec.ts 擋著不讓語系不齊的 key 再進來。
 */
const { settings } = useSite()
const { loggedIn } = useUserSession()
const wallet = useWalletStore()
const { refreshBalance } = useWallet()

const currency = computed(() => settings.value?.text_wallet_point || '')
const balanceText = computed(() => decFormat(wallet.balance, 2))

const actions = [
  { key: 'deposit', to: '/topup', i18n: 'deposit.entry.nav.deposit' },
  { key: 'livechat', to: '/cs', i18n: 'site.cs.nav.chat', aria: 'site.cs.nav.customerService' },
  { key: 'share', to: '/agent', i18n: 'events.invite.action.inviteFriends' },
]

/**
 * 每次掛上都重抓（照舊版 C13 BalanceInfo 的 created）：從遊戲商回到首頁時 store 裡可能還是
 * 轉入後的 0（轉入失敗、後端之後才退回），只在 balance 為 null 才抓會一直停在舊值。
 */
onMounted(() => {
  if (loggedIn.value) void refreshBalance()
})
</script>

<template>
  <section
    v-if="loggedIn"
    class="mx-[var(--c17-pad-x)] flex items-center gap-[var(--c17-gap-2)] rounded-[var(--c17-radius-lg)]"
    :style="{
      padding: 'var(--c17-wallet-pad)',
      background: 'var(--c17-wallet-bg)',
      boxShadow: 'var(--c17-shadow-card)',
    }"
  >
    <!--
      餘額這一欄不吃滿剩餘空間，最少佔約 46%；金額較長時跟著內容加寬。
      幣別放在標籤那一行（「錢包餘額 · W-Point(MYR)」），白底只放金額：
      設計稿的幣別在白底裡，但站台幣別可能很長，跟金額擠同一行不是被截斷就是換成兩行，
      分開放才能兩者都完整顯示又維持一行。幣別與金額都不截斷。
      左欄也不加 gap：標籤 + 白底要塞進 60 高的面板，多一個 gap 會把面板撐成 64。
    -->
    <div class="mr-auto flex min-w-[46%] flex-[0_1_auto] flex-col">
      <p
        class="flex items-baseline gap-1 whitespace-nowrap"
        :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }"
      >
        {{ $t('wallet.overview.label.balance') }}
        <span
          v-if="currency"
          :style="{ color: 'var(--c17-primary)' }"
        >· {{ currency }}</span>
      </p>
      <p
        class="whitespace-nowrap rounded-[var(--c17-radius-sm)] bg-surface px-[var(--c17-gap-2)] py-[2px] text-right font-bold tabular-nums"
        :style="{ fontSize: 'var(--c17-text-body)', color: 'var(--c17-primary)', boxShadow: 'var(--c17-shadow-tile)' }"
      >
        {{ balanceText }}
      </p>
    </div>

    <!--
      入口磚照設計稿：**40 寬、彼此間距 12**，icon 在上、字在下，整組靠右。
      不要改成 flex-1 平分剩餘空間（磚會被撐成 54 寬，跟設計稿差很多）。
      素材只剩純 icon（透明底），白底、圓角與陰影是這裡畫的。

      字級用 badge（8pt）不是 label（10pt）：磚只有 40 寬，10pt 的 "Invite Friends"
      會變三行把磚撐高。標籤三個 class 都是必要的：w-full（line-clamp 沒撐滿寬度會被裁掉）、
      line-clamp-2（語系長度差很多，收兩行才不會高低不齊）、
      wrap-anywhere（緬甸文沒有空白可斷行，不加會衝出磚外）。
    -->
    <div class="flex shrink-0 items-start gap-[var(--c17-gap-3)]">
      <NuxtLink
        v-for="action in actions"
        :key="action.key"
        :to="action.to"
        class="flex size-10 flex-col items-center justify-center gap-[1px] overflow-hidden rounded-[var(--c17-radius-sm)] px-[2px] py-[1px]"
        :style="{ background: 'var(--c17-tile-bg)', boxShadow: 'var(--c17-shadow-tile)' }"
        :aria-label="$t(action.aria ?? action.i18n)"
      >
        <!--
          icon 要包一層固定尺寸的盒子：C17WalletActionIcon 的根元素是 <img class="size-full">，
          直接傳 size-5 會被它自己的 size-full 蓋掉，icon 撐滿整塊磚而且三張長寬比不同會高低不齊。
        -->
        <span class="grid size-5 shrink-0 place-items-center">
          <C17WalletActionIcon :name="action.key" />
        </span>
        <span
          class="line-clamp-2 w-full wrap-anywhere text-center font-bold uppercase"
          :style="{ fontFamily: 'var(--c17-font-label)', fontSize: 'var(--c17-text-badge)', lineHeight: '1.1', color: 'var(--c17-ink)' }"
        >{{ $t(action.i18n) }}</span>
      </NuxtLink>
    </div>
  </section>
</template>
