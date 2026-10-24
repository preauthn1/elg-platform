<script setup lang="ts">
import type { GameListItem } from '#shared/api/endpoints/games'
import { gameTypeLabelKey, isGamePlayable, showsVendorBalanceInHeader } from '#shared/utils/games'

/**
 * C17 遊戲列表：頂部是有返回鍵的 C17PageHeader（標題 = 廠商名稱），下面類型橫幅、類型分頁、3 欄遊戲。
 * 篩選、分頁、轉入場館在 useVendorGameList，進遊戲在 useGamePlay。
 */
const { t } = useI18n()
const {
  numericVendorId,
  queryTypeId,
  typeId,
  page,
  status,
  pagination,
  vendorName,
  giftcode,
  title,
  typeTabs,
  visibleGames,
  syncWallet,
  selectType,
} = await useVendorGameList()
const { notice, vpowerGame, play } = useGamePlay<GameListItem>()

/** 類型橫幅：素材與「哪些類型有圖」的判斷跟廠商大廳共用 app/utils/lobbyBanner.ts。 */
const typeBanners = computed(() => lobbyBannerSlides(typeId.value))

/** 骨架的出現時機由 useSkeleton 決定，不可以直接綁 loading（見該 composable 的說明） */
const showSkeleton = useSkeleton(() => status.value === 'pending')

const headerTitle = computed(() => vendorName.value || t(title.value || 'games.lobby.title.gameList'))
const typeTabItems = computed(() => [
  { key: null, label: t('games.category.option.allGames') },
  ...typeTabs.value.map(id => ({ key: id, label: t(gameTypeLabelKey(id)) })),
])

/** 三欄 × 三列：手機首屏看得到的遊戲圖，不延遲下載 */
const FIRST_SCREEN_TILES = 9
</script>

<template>
  <div>
    <!-- 照舊版 Navbar：遊戲商頁顯示遊戲商餘額（＋禮碼）；場館 4 不顯示 -->
    <C17PageHeader
      :title="headerTitle"
      fallback="/vendors"
      :balance="showsVendorBalanceInHeader(numericVendorId)"
      balance-source="vendor"
      :giftcode="giftcode"
    />

    <!-- 類型橫幅：與廠商大廳同一張（設計稿 Game-page 最上面那條 390×140，比例 39:14），滿版 -->
    <C17HeroCarousel
      v-if="typeBanners.length"
      :items="typeBanners"
    />

    <div class="space-y-4 px-(--c17-pad-x) py-(--c17-gap-4)">
      <span
        v-if="giftcode === 1"
        class="inline-block rounded bg-page px-2 py-0.5 text-xs"
      >{{ $t('games.vendor.label.rollingGames') }}</span>

      <EventsPrizeReminder @ready="syncWallet" />

      <UiPillTabs
        v-if="queryTypeId === null && typeTabs.length"
        :model-value="typeId"
        :items="typeTabItems"
        @update:model-value="selectType"
      />

      <p
        v-if="notice === 'popup'"
        class="text-sm text-warning"
      >
        {{ $t('games.launch.hint.newWindow') }}
      </p>

      <GamesVpowerEntryModal
        v-if="vpowerGame"
        :game="vpowerGame"
        @close="vpowerGame = null"
        @blocked="vpowerGame = null; notice = 'popup'"
      />

      <!-- 載入中用骨架，不要只印「載入中」：列數與欄數對齊實際的 3 欄格線，換成真資料時高度不跳 -->
      <div
        v-if="showSkeleton"
        class="grid grid-cols-3 gap-(--c17-tile-gap)"
      >
        <UiSkeleton
          v-for="i in 9"
          :key="i"
          class="aspect-square rounded-(--c17-radius-sm)"
        />
      </div>
      <p
        v-else-if="visibleGames.length === 0"
        class="text-muted"
      >
        {{ $t('common.ui.empty.noData') }}
      </p>

      <!--
      遊戲磚照 C17 的做法：方形縮圖 + 圓角 8 + 分類磚同一顆陰影，名稱排在圖下面，
      沒有白色外框卡。
      維護中改成蓋在縮圖上的遮罩，不要另外多一行字把每個磚的高度撐得不一樣。
    -->
      <ul
        v-else
        class="grid grid-cols-3 gap-[var(--c17-tile-gap)]"
      >
        <li
          v-for="(game, index) in visibleGames"
          :key="game.id"
          class="min-w-0"
        >
          <button
            type="button"
            class="relative block w-full disabled:opacity-50"
            :disabled="!isGamePlayable(game)"
            @click="play(game)"
          >
            <GamesTileImage
              v-if="game.image"
              :src="game.image"
              :alt="game.name ?? ''"
              :eager="index < FIRST_SCREEN_TILES"
            />
            <span
              v-else
              class="block aspect-square w-full rounded-[var(--c17-radius-sm)]"
              :style="{ background: 'var(--c17-tile-bg)', boxShadow: 'var(--c17-shadow-category)' }"
            />

            <span
              v-if="game.is_maintenance === 1"
              class="absolute inset-0 grid place-items-center rounded-[var(--c17-radius-sm)] px-1 text-center leading-tight"
              :style="{
                background: 'color-mix(in srgb, var(--c17-ink) 62%, transparent)',
                color: 'var(--c17-primary-ink)',
                fontSize: 'var(--c17-text-badge)',
              }"
            >
              {{ $t('common.ui.label.maintenance') }}
              <template v-if="game.maintenance_end_time">· {{ game.maintenance_end_time }}</template>
            </span>
          </button>

          <p
            class="mt-1 truncate font-semibold"
            :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
          >
            {{ game.name }}
          </p>
          <p
            class="truncate"
            :style="{ fontSize: 'var(--c17-text-badge)', color: 'var(--c17-muted)' }"
          >
            {{ game.vendor_name }}
          </p>
        </li>
      </ul>

      <UiPagination
        v-if="pagination"
        v-model:page="page"
        :pages="pagination.pages"
        :total="pagination.total"
      />
    </div>
  </div>
</template>
