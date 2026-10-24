<script setup lang="ts">
/**
 * C17 版面外殼：可關閉的下載列、帶餘額的漸層頂部列、內容、固定底部分頁。
 * 內容底部留出分頁列的高度加安全區，避免最後一列被蓋住。
 *
 * **全站只有這一個版面，不要開第二個 layout。** Nuxt 在兩個 layout 之間切頁會把整個外殼
 * 卸載重建，頂部列每次重新掛載就重打一次餘額（F12 會看到 member/transfer 一直重複）。
 *
 * 左右留白由外殼給；已照設計稿刻好、要滿版的頁面（首頁輪播、遊戲橫列）
 * 用 definePageMeta({ fullBleed: true }) 關掉，自己控制留白。
 */
const route = useRoute()
const { error } = useSite()

const padded = computed(() => route.meta.fullBleed !== true)

/**
 * 下載列**每一頁都掛**，由使用者自己關掉為止（關閉狀態在 DownloadBar 裡管）。
 *
 * 帶餘額的漸層頂部列則只掛在設計稿有畫的地方：Home v3、Promo、VIP 最上面都是下載列、
 * 沒有餘額列（首頁的餘額載體是頁面中段那條黃綠 WalletBar），只有 Game-page 畫了餘額列。
 * 要關掉的頁面自己標 `definePageMeta({ hideAppHeader: true })`，不要在這裡列路徑表。
 */
const showAppHeader = computed(() => route.meta.hideAppHeader !== true)

/**
 * 主選單抽屜：蓋在當前頁面上，不是路由。開關由底部分頁列的 Menu 切。
 * 第一次開才掛進 DOM，之後**留著**（只切 open）：用 v-if 跟著 open 切會每開一次重建一次，
 * 資料請求重跑、清單閃一下、關閉也沒有滑出動畫。
 */
const { mounted: menuMounted, show: showMenu } = useMenuDrawer()

/**
 * /menu 舊連結轉過來會帶 ?menu=open。只在客戶端處理：先把 query 清掉再開，
 * 不然 MenuDrawer 裡「換頁就關」的 watcher 會在清 query 時把剛開的抽屜關掉。
 */
const router = useRouter()
async function openMenuFromQuery() {
  if (route.query.menu !== 'open') return
  const query = { ...route.query }
  delete query.menu
  await router.replace({ path: route.path, query })
  showMenu()
}
onMounted(openMenuFromQuery)
watch(() => route.query.menu, () => {
  if (import.meta.client) void openMenuFromQuery()
})

/**
 * 換頁時的載入態，照 bc.game：他們是純 SPA，切路由時畫面空的就置中放一顆轉圈；
 * 內容還在的時候則把它壓暗（`.mx-refresh-dim`，0.42）。我們是 SSR，舊頁會一直留在畫面上，
 * 所以兩件事都做——壓暗舊內容 ＋ 疊一顆轉圈。
 *
 * **延遲與最短顯示時間沿用 useSkeleton**，不要再寫第二套：切得快的頁面閃一下比沒有更吵，
 * 這一點骨架與轉圈是同一個問題。
 */
const navigating = ref(false)
const nuxtApp = useNuxtApp()
nuxtApp.hook('page:loading:start', () => {
  navigating.value = true
})
nuxtApp.hook('page:loading:end', () => {
  navigating.value = false
})
const showSpinner = useSkeleton(navigating)
</script>

<template>
  <div
    class="min-h-dvh"
    :style="{
      background: 'var(--c17-bg)',
      color: 'var(--c17-ink)',
      fontFamily: 'var(--c17-font)',
    }"
  >
    <C17DownloadBar />
    <C17AppHeader v-if="showAppHeader" />

    <!-- Host 解析不到站台時把原因顯示出來，不要讓整頁空白 -->
    <div
      v-if="error"
      class="bg-warning-soft px-(--c17-pad-x) py-2 text-center text-sm text-warning"
    >
      {{ error.host }}：{{ error.message }}
    </div>

    <main
      class="wf-dim-transition mx-auto w-full max-w-shell"
      :class="[padded ? 'px-(--c17-pad-x) py-(--c17-gap-4)' : '', showSpinner ? 'wf-dim' : '']"
      :style="{ paddingBottom: `calc(var(--c17-nav-h) + var(--c17-gap-6) + env(safe-area-inset-bottom))` }"
    >
      <slot />
    </main>

    <!-- 轉圈疊在壓暗的舊內容上；pointer-events-none 讓它不擋住底下的捲動 -->
    <div
      v-if="showSpinner"
      class="pointer-events-none fixed inset-0 z-40 flex items-center justify-center"
      :style="{ color: 'var(--c17-primary)' }"
    >
      <UiSpinner :size="28" />
    </div>

    <C17BottomNav />
    <C17MenuDrawer v-if="menuMounted" />

    <!-- API 錯誤一律用彈窗呈現，掛在外殼上，頁面不用各自宣告（見 useAlert） -->
    <UiAlert />
  </div>
</template>
