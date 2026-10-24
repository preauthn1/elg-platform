<script setup lang="ts">
import { pickGameUrl } from '#shared/utils/games'

/**
 * SPORTS MATCH 區塊。內容不是自己刻的，是 SABA 的快速投注 widget，
 * 與舊前台 C13 大廳同一套（`_source2/src/components/common/QuickBetWidget.vue`）：
 * game/widget-url 取網址後用 iframe 嵌入。需登入、站台開 saba_widget_visible 才顯示。
 */
const SABA_VENDOR_ID = 44

/** 還沒收到建議尺寸前的比例，與舊專案的 `aspect-ratio: 9 / 5` 相同。 */
const DEFAULT_ASPECT = '9 / 5'
/** widget 回報的尺寸不可用時的高度，沿用舊專案 `.fixed-height` 的 200px。 */
const FALLBACK_HEIGHT = 200

const { flag } = useSite()
const { loggedIn } = useUserSession()
const { fetchWidgetUrl, isMobile } = useGameLauncher()

const src = ref('')
const height = ref(0)
const enabled = computed(() => loggedIn.value && flag('saba_widget_visible'))

/**
 * widget 的訊息格式是 `{ eventCode, ... }`，**不是 `{ height }`**。
 * 高度只會跟著 `S001`（內嵌組件建議尺寸）的 `size.height` 來，
 * 之前監聽 `data.height` 等於永遠收不到，iframe 就一直停在預設高度，
 * 底下多出一塊白的。事件代碼對照見舊專案的 QuickBetWidget。
 */
function onMessage(event: MessageEvent) {
  if (!src.value) return
  let origin = ''
  try {
    origin = new URL(src.value).origin
  }
  catch {
    return
  }
  if (event.origin !== origin) return

  const data = event.data as { eventCode?: string, size?: { height?: number } } | null
  if (!data?.eventCode) return

  // S001：建議尺寸；R501：拒絕訪問，widget 只剩一塊提示，用固定高度收掉
  if (data.eventCode === 'S001') {
    const next = Number(data.size?.height)
    height.value = Number.isFinite(next) && next > 0 ? next : FALLBACK_HEIGHT
    return
  }
  if (data.eventCode === 'R501') height.value = FALLBACK_HEIGHT
}

onMounted(async () => {
  if (!enabled.value) return
  const res = await fetchWidgetUrl(SABA_VENDOR_ID)
  if (isApiOk(res)) src.value = pickGameUrl(res.data, isMobile.value) ?? ''
  window.addEventListener('message', onMessage)
})

onBeforeUnmount(() => {
  if (import.meta.client) window.removeEventListener('message', onMessage)
})
</script>

<template>
  <ClientOnly>
    <section
      v-if="enabled && src"
      class="space-y-[var(--c17-tile-gap)]"
    >
      <C17SectionHeader
        :title="$t('games.category.title.sports')"
        to="/vendors?typeId=18"
      />
      <div
        class="mx-[var(--c17-pad-x)] overflow-hidden rounded-[var(--c17-radius)]"
        :style="{ background: 'var(--c17-surface)', boxShadow: 'var(--c17-shadow)' }"
      >
        <!--
          **scrolling="no"** 是必要的：iframe 比內容高或矮都不該長出自己的捲軸，
          那會變成頁面裡再套一層可捲動區域。

          還沒收到建議尺寸前用比例撐（9:5），不要寫死一個偏大的高度——
          寫死會在內容比它矮的時候，在卡片底部留一條白的。
        -->
        <iframe
          :src="src"
          :title="$t('games.category.title.sports')"
          class="block w-full border-0"
          :style="height > 0 ? { height: `${height}px` } : { aspectRatio: DEFAULT_ASPECT }"
          scrolling="no"
          loading="lazy"
        />
      </div>
    </section>
  </ClientOnly>
</template>
