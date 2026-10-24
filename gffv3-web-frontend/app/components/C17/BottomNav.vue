<script setup lang="ts">
/**
 * C17 底部分頁列。五個固定入口，中間錢包是凸起的圓鈕。
 *
 * 設計稿 game-page 那張把整條列畫成向量，所以數值是量到的、不是猜的：
 *   白色列 390×56 @ y=788，**圓角 8**（上兩角），陰影 -1,-1 模糊 1.5 #1a1f36/20%
 *   圖示 28×28 @ y=796（x=32/109/261/338）
 *   標籤 Inter Medium 11/1.5 #1a1f36（不是 10pt SemiBold 灰字），圖示下方 11
 *   錢包圓鈕 70×70 @ [160,774]，往上凸出白色列 14
 * 陰影只有量到的那一層，不要再疊第二層大範圍陰影。
 * 路由是結構不是資料，所以這五項寫在這裡；未讀數字來自訊息領域。
 *
 * 高度由內容撐開再加安全區，不寫死列高，避免文字被切在畫面外。
 * 錢包那顆的素材本身已經含 WALLET 字樣，所以不另外配文字。
 */
// 動態元件要傳**元件本身**，不能傳 'NuxtLink' 字串——字串不會被解析，
// 會原樣渲染成 <nuxtlink> 自訂元素，沒有 href 也點不動。
import { NuxtLink } from '#components'
import { isMessagesRoute } from '#shared/utils/messages'

const route = useRoute()
const messages = useMessagesStore()
const menu = useMenuDrawer()
const { loggedIn } = useUserSession()

interface Tab {
  key: string
  /** 沒有 to 的是開關（目前只有 Menu：打開蓋在當前頁的抽屜，不換路由） */
  to?: string
  i18n: string
  /** 中間凸起的那顆，素材已含文字 */
  fab?: boolean
  badge?: () => number
}

/** 中間那顆錢包開面板，不換路由（照舊前台 C14 的 WalletModal）。 */
const walletOpen = ref(false)

const tabs: Tab[] = [
  { key: 'games', to: '/', i18n: 'site.nav.nav.games' },
  { key: 'promo', to: '/promotions', i18n: 'site.nav.nav.promotion' },
  { key: 'wallet', i18n: 'wallet.overview.title.wallet', fab: true },
  { key: 'profile', to: '/member', i18n: 'site.nav.nav.profile' },
  // 通知中心的入口（站內信）在選單抽屜裡，未讀數就掛在選單這顆
  { key: 'menu', i18n: 'site.nav.nav.menu', badge: () => messages.totalUnread },
]

/**
 * 照舊版底部列：每換一頁重抓三個未讀數；在通知中心內不抓（各分頁進入時自行歸零）。
 * 只在瀏覽器跑，SSR 不打。
 */
const { refreshUnread } = useMessages()
watch([() => route.path, loggedIn], ([path, isLoggedIn]) => {
  if (import.meta.client && isLoggedIn && !isMessagesRoute(path)) void refreshUnread()
}, { immediate: true })

function isActive(tab: Tab): boolean {
  if (tab.key === 'wallet') return walletOpen.value
  if (!tab.to) return menu.open.value
  return tab.to === '/' ? route.path === '/' : route.path.startsWith(tab.to)
}

/**
 * 有 to 的交給 NuxtLink；其餘兩個（錢包、Menu）各自開自己的浮層。
 *
 * 錢包面板一開就會打餘額，未登入時那支會回 9998 把人踢到登入頁——面板閃一下又消失。
 * 所以未登入直接導登入頁，跟原本連到 /wallet（requiresAuth）時的行為一致。
 */
function onTab(tab: Tab) {
  if (tab.key === 'wallet') {
    if (!loggedIn.value) {
      void navigateTo({ path: '/login', query: { redirect: route.fullPath } })
      return
    }
    walletOpen.value = !walletOpen.value
  }
  else if (!tab.to) menu.toggle()
}
</script>

<template>
  <nav
    class="fixed inset-x-0 bottom-0 z-30"
    :style="{
      background: 'var(--c17-surface)',
      borderTopLeftRadius: 'var(--c17-radius-sm)',
      borderTopRightRadius: 'var(--c17-radius-sm)',
      boxShadow: 'var(--c17-shadow-up)',
      paddingBottom: 'env(safe-area-inset-bottom)',
    }"
    :aria-label="$t('site.nav.nav.menu')"
  >
    <!-- 列高固定 56，錢包那格改成底部對齊，70 的圓鈕就自然往上溢出 14，
         其餘四格垂直置中。不用位移，才不會把整列撐高。 -->
    <ul
      class="mx-auto grid max-w-shell grid-cols-5 items-center"
      :style="{ height: 'var(--c17-nav-h)' }"
    >
      <li
        v-for="tab in tabs"
        :key="tab.key"
        class="relative flex h-full justify-center"
      >
        <component
          :is="tab.to ? NuxtLink : 'button'"
          :to="tab.to"
          :type="tab.to ? undefined : 'button'"
          class="flex flex-col items-center justify-center gap-[var(--c17-gap-2)]"
          :class="tab.fab ? 'absolute bottom-0 left-1/2 -translate-x-1/2' : 'relative'"
          :aria-current="isActive(tab) && tab.to ? 'page' : undefined"
          :aria-expanded="tab.to ? undefined : isActive(tab)"
          @click="onTab(tab)"
        >
          <span
            class="grid shrink-0 place-items-center"
            :style="tab.fab
              ? { width: 'var(--c17-nav-fab)', height: 'var(--c17-nav-fab)' }
              : { width: 'var(--c17-nav-icon)', height: 'var(--c17-nav-icon)' }"
          >
            <C17NavIcon
              :name="tab.key"
              :fab="tab.fab"
            />
          </span>

          <span
            v-if="tab.badge && tab.badge() > 0"
            class="absolute -top-1 right-0 grid min-w-4 place-items-center rounded-full px-1 font-bold"
            :style="{ background: 'var(--c17-badge)', color: 'var(--c17-badge-ink)', fontSize: 'var(--c17-text-badge)' }"
          >
            {{ tab.badge() > 99 ? '99+' : tab.badge() }}
          </span>

          <!-- 錢包的素材已含文字，其餘四個才配標籤 -->
          <span
            v-if="!tab.fab"
            class="whitespace-nowrap font-medium leading-none"
            :style="{
              fontFamily: 'var(--c17-font-display)',
              fontSize: 'var(--c17-text-note)',
              color: isActive(tab) ? 'var(--c17-primary)' : 'var(--c17-ink)',
            }"
          >
            {{ $t(tab.i18n) }}
          </span>
        </component>
      </li>
    </ul>

    <C17WalletPanel
      v-if="walletOpen"
      @close="walletOpen = false"
    />
  </nav>
</template>
