<script setup lang="ts">
import type { HeroSlide } from '#shared/types/ui'
import { BANNER_PATH } from '#shared/api/endpoints/site'

/**
 * C17 首頁（設計稿 Home v3）。
 * 每個區塊的資料都來自 API，沒有寫死的清單；唯一寫死的是「FREE GAME 1+1」這個行銷標題。
 * LIVE CASINO 區塊與各區的統計數字（claimed／players online／live matches）本輪不做，
 * 後端沒有對應端點，等規格確認。
 * 首頁要放哪些區塊是這個皮的版面決定的，所以資料組合留在這裡；SEO 與進頁檢查在 pages/index.vue。
 */
const { fetchGamesByTag, fetchGameTypes } = useGames()
const { loadAdvertise } = useAdvertise()
const { listEvents, events } = useEvents()
const eventV2 = useEventV2()

// 上方輪播吃 path_id 5（舊專案註解寫「首頁廣告」）。原本錯拿 FULL，後端回空、hero 整塊不見。
const bannersAsync = useAsyncData('c17-home-banners', () => loadAdvertise(BANNER_PATH.HOME), {
  default: () => [],
})

const hotGamesAsync = useAsyncData('c17-home-hot-games', async () => {
  const res = await fetchGamesByTag('hot')
  return isApiOk(res) ? res.data : []
}, { default: () => [] })

const gameTypesAsync = useAsyncData('c17-home-game-types', async () => {
  const res = await fetchGameTypes()
  return isApiOk(res) ? res.data : []
}, { default: () => [] })

const eventsAsync = useAsyncData('c17-home-events', async () => {
  await listEvents()
  return events.value
}, { default: () => [] })

await Promise.all([bannersAsync, hotGamesAsync, gameTypesAsync, eventsAsync])

/**
 * advertise 的廣告轉成輪播要的最小形狀。轉在這裡而不是塞進元件：
 * 遊戲大廳那條橫幅是寫死在皮裡的，不是廣告，元件同時吃兩種來源就會長出兩條路。
 */
const { openBanner, isClickable } = useBannerNavigation()
const banners = computed<HeroSlide[]>(() =>
  (bannersAsync.data.value ?? [])
    .filter(item => item.image || item.image_pc)
    .map(item => ({
      key: item.id,
      image: item.image || item.image_pc || '',
      // advertise 是 looseObject，description 沒有在 schema 裡，型別是 {}，要自己收斂
      alt: typeof item.description === 'string' ? item.description : '',
      onSelect: isClickable(item) ? () => openBanner(item) : undefined,
    })),
)
const hotGames = hotGamesAsync.data
const gameTypes = gameTypesAsync.data
const promotions = eventsAsync.data
</script>

<template>
  <div class="flex flex-col gap-[var(--c17-gap-5)] pb-[var(--c17-gap-4)]">
    <!--
      垂直節奏照設計稿量到的值：**區塊之間 20**（遊戲列 345→錢包列 365、
      錢包列 425→分類格 445 都是 20），**區塊內標題→內容 10**（與磚格的 10 同一個節奏）。
      原本是 24 / 12，整頁偏鬆。
    -->
    <MessagesAnnouncementPopup />
    <MessagesPushPrompt />

    <C17HeroCarousel :items="banners" />

    <section
      v-if="hotGames.length > 0"
      class="flex flex-col gap-[var(--c17-tile-gap)]"
    >
      <!-- 行銷標題，後端沒有對應欄位，經確認先寫死 -->
      <C17SectionHeader
        title="FREE GAME 1+1"
        to="/game/tag/hot"
      />
      <C17GameRail :items="hotGames" />
    </section>

    <!-- 參加活動 V2 中：錢包列換成活動進度（舊專案 C13 BalanceInfo 的 eventBarVisible） -->
    <EventsV2AccountBar show-detail-link />
    <C17WalletBar v-if="!eventV2.joined.value" />

    <C17CategoryGrid :game-types="gameTypes" />

    <C17SportsWidget />

    <section
      v-if="promotions.length > 0"
      class="flex flex-col gap-[var(--c17-tile-gap)]"
    >
      <C17SectionHeader
        :title="$t('site.nav.nav.promotion')"
        to="/promotions"
      />
      <C17PromotionRail :items="promotions" />
    </section>
  </div>
</template>
