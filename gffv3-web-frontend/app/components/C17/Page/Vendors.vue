<script setup lang="ts">
import {
  gameTypeLabelKey,
  isAppVendor,
  isVendorOpen,
  vendorMaintenanceUntil,
  vendorStatusI18nKey,
} from '#shared/utils/games'
import { isOn } from '#shared/utils/site'
import type { HeroSlide } from '#shared/types/ui'

/**
 * C17 遊戲大廳（設計稿 Game-page）：類型橫幅 → 分類列 → 3 欄廠商卡。
 *
 * 「全部／熱門／最新」那組 tag 分頁**不放這頁**——設計稿沒畫，舊專案的
 * `C13/C16 Page/Vendors.vue` 也只開 typeFilter，tag 列是 `/lobby` 的東西。
 * `?tag=` 照吃，只是不多畫一列。
 *
 * 選了類型後類型列**留著**並標出選中那顆（舊專案是收起來），不然換不了類型。
 * 篩選、抓資料、點遊戲商的流程在 useVendorLobby。
 */
const {
  typeId,
  selectType,
  status,
  typeTabs,
  vendors,
  prizeReminder,
  clickVendor,
  openPendingVendor,
} = await useVendorLobby()

/** 選中的類型磚捲到分類列中間；只動這一列的 scrollLeft，不帶動整頁捲動。 */
const typeRow = ref<HTMLElement | null>(null)
function revealActiveType() {
  const row = typeRow.value
  const active = row?.querySelector<HTMLElement>('[data-active]')
  if (!row || !active) return
  const rowRect = row.getBoundingClientRect()
  const tileRect = active.getBoundingClientRect()
  row.scrollLeft += tileRect.left - rowRect.left - (rowRect.width - tileRect.width) / 2
}
onMounted(revealActiveType)
watch(typeId, () => nextTick(revealActiveType))

/** 類型橫幅：素材路徑與「哪些類型有圖」的判斷收在 app/utils/lobbyBanner.ts，與遊戲列表頁共用。 */
const typeBanners = computed<HeroSlide[]>(() => lobbyBannerSlides(typeId.value))

/**
 * 分類磚素材：70×70（存 @2x）。圓角、淺藍底、類型名稱全部烤在圖裡，
 * **沒有**陰影，所以有素材的磚不要再給 background 或 box-shadow。
 * 名稱只有英文，與首頁大磚同一個多語系缺口。
 *
 * 德州撲克（3）沒有圖，退回文字磚——不混用首頁那套寬版行銷圖，同一列兩種風格很難看。
 */
const DESIGN_TILE_ORDER = [2, 1, 18, 4, 5, 15]
const TILE_ASSET = new Set(DESIGN_TILE_ORDER)
/**
 * 有素材的先照設計稿那一列排，其餘接在後面。不跟 TYPE_TAB_ORDER 走：
 * 那個順序會把沒素材的類型插在 CASINO 與 SPORTS 中間，設計稿那一列就斷了。
 */
const tileTypes = computed(() => {
  const available = typeTabs.value
  return [
    ...DESIGN_TILE_ORDER.filter(id => available.includes(id)),
    ...available.filter(id => !TILE_ASSET.has(id)),
  ]
})
function tileImage(id: number): string {
  return `/skins/c17/lobby/${id}.webp`
}
</script>

<template>
  <div class="flex flex-col pb-[var(--c17-gap-4)]">
    <!-- 區塊間距標在各自的子元素上：設計稿兩段差值不同（10 與 16），不是統一的 gap -->
    <!-- 類型橫幅：比例 39:14，滿版不留左右白 -->
    <C17HeroCarousel :items="typeBanners" />

    <!--
      分類列：70×70、圓角 5、可橫向捲動。

      **選取框一定要 `outline-offset: -2px` 往內畫。** 這一列是 `overflow-x: auto`，
      CSS 會把另一軸的 `visible` 連帶算成 `auto`，整列變成裁切容器；列高又等於磚高，
      畫在 border box 外面的 outline 上下各 2px 會被裁掉，看起來就是「藍框缺上下」。
    -->
    <div
      ref="typeRow"
      class="mt-[var(--c17-tile-gap)] flex gap-[var(--c17-tile-gap)] overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
      :style="{ paddingInline: 'var(--c17-pad-x)' }"
    >
      <button
        type="button"
        :data-active="typeId === null || undefined"
        class="grid size-[70px] shrink-0 place-items-center rounded-[5px] px-1 text-center font-extrabold uppercase"
        :style="{
          background: 'var(--c17-tile-bg)',
          fontSize: 'var(--c17-text-badge)',
          color: 'var(--c17-ink)',
          outline: typeId === null ? '2px solid var(--c17-primary)' : undefined,
          outlineOffset: '-2px',
        }"
        @click="selectType(null)"
      >
        {{ $t('games.category.option.allGames') }}
      </button>
      <!-- 有素材的磚只靠 CSS 給尺寸與選取外框；沒素材的退回文字磚，入口不會消失 -->
      <button
        v-for="id in tileTypes"
        :key="id"
        type="button"
        :data-active="typeId === id || undefined"
        class="relative size-[70px] shrink-0 overflow-hidden rounded-[5px]"
        :class="TILE_ASSET.has(id) ? '' : 'grid place-items-center px-1 text-center font-extrabold uppercase leading-tight'"
        :style="TILE_ASSET.has(id)
          ? { outline: typeId === id ? '2px solid var(--c17-primary)' : undefined, outlineOffset: '-2px' }
          : {
            background: 'var(--c17-tile-bg)',
            fontSize: 'var(--c17-text-badge)',
            color: 'var(--c17-ink)',
            outline: typeId === id ? '2px solid var(--c17-primary)' : undefined,
            outlineOffset: '-2px',
          }"
        :aria-label="$t(gameTypeLabelKey(id))"
        @click="selectType(id)"
      >
        <img
          v-if="TILE_ASSET.has(id)"
          :src="tileImage(id)"
          alt=""
          class="absolute inset-0 size-full object-cover"
        >
        <template v-else>
          {{ $t(gameTypeLabelKey(id)) }}
        </template>
      </button>
    </div>

    <!-- 這頁是 fullBleed，外殼不給左右內距，所以提醒卡自己補上 -->
    <div :style="{ paddingInline: 'var(--c17-pad-x)' }">
      <EventsPrizeReminder
        ref="prizeReminder"
        manual
        @ready="openPendingVendor"
      />
    </div>

    <p
      v-if="status === 'pending'"
      class="mt-[var(--c17-gap-4)] text-center text-sm text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>
    <p
      v-else-if="vendors.length === 0"
      class="mt-[var(--c17-gap-4)] text-center text-sm text-muted"
    >
      {{ $t('common.ui.empty.noData') }}
    </p>

    <!--
      廠商卡：3 欄，**每張卡都是 109:138**，不因為圖的比例而一高一低。

      **卡面就是 `vendor.image`，下面不另外排名稱**——廠商名烤在圖裡
      （舊專案 `VendorItem.vue` 的 `.vendor-name` 也是註解掉的）。

      卡的尺寸固定，**圖用 `object-contain` 縮進去**：廠商圖的長寬比各家不同
      （有直式人物圖、也有純橫式 logo），contain 讓兩種都完整顯示，直式剛好填滿、
      橫式置中留白。**不要用 `object-cover`**，那會把「Sexy Slot」裁成「Sex Slo」；
      也不要讓高度跟著圖跑，那樣同一列會一高一低。

      **淺藍底只給沒有圖的那種**：廠商圖自己都帶背景，再墊一層底色只會在
      橫式 logo 的上下露出一塊跟圖不搭的藍。沒有圖時才用淺藍底＋置中的廠商名，
      那張卡才不會是一塊空白。停用是 `filter: grayscale(1)` 不是調透明度。
      **不加陰影**：md 沒寫，算圖取樣卡片右側是頁面底色。
    -->
    <ul
      v-else
      class="mt-[var(--c17-gap-4)] grid grid-cols-3 gap-x-[var(--c17-gap-4)] gap-y-[var(--c17-gap-3)]"
      :style="{ paddingInline: 'var(--c17-pad-x)' }"
    >
      <li
        v-for="vendor in vendors"
        :key="`${vendor.id}-${vendor.item_type}-${vendor.type_id ?? ''}`"
        class="relative"
      >
        <button
          type="button"
          class="relative block aspect-[109/138] w-full overflow-hidden rounded-[var(--c17-radius-sm)]"
          :class="isVendorOpen(vendor) ? '' : 'grayscale'"
          :style="vendor.image ? undefined : { background: 'var(--c17-card-bg)' }"
          :disabled="!isVendorOpen(vendor)"
          :aria-label="vendor.name ?? undefined"
          @click="clickVendor(vendor)"
        >
          <img
            v-if="vendor.image"
            :src="vendor.image"
            alt=""
            class="absolute inset-0 size-full object-contain"
            loading="lazy"
          >
          <span
            v-else
            class="absolute inset-0 grid place-items-center px-[var(--c17-gap-1)] text-center font-bold leading-tight text-ink"
            :style="{ fontSize: 'var(--c17-text-label)' }"
          >{{ vendor.name }}</span>

          <!-- 維護／即將推出：舊專案是壓在卡片底部的色條，不是浮在最上面 -->
          <span
            v-if="vendorStatusI18nKey(vendor.status)"
            class="absolute inset-x-0 bottom-0 truncate px-1 py-[2px] text-center font-bold"
            :style="{
              fontSize: 'var(--c17-text-badge)',
              background: 'var(--c17-error)',
              color: 'var(--c17-primary-ink)',
            }"
          >
            {{ $t(vendorStatusI18nKey(vendor.status)!) }}<template v-if="vendorMaintenanceUntil(vendor)">&nbsp;{{ vendorMaintenanceUntil(vendor) }}</template>
          </span>
        </button>

        <!-- 角標照舊版 VendorItem：活動左上、APP 右下，會凸出卡片外，所以放在 button（overflow-hidden）外面 -->
        <img
          v-if="isOn(vendor.is_event)"
          src="/skins/c17/lobby/tag-event.webp"
          alt=""
          class="pointer-events-none absolute left-[5%] top-[-8%] h-1/4 w-[30%] object-contain"
          :class="isVendorOpen(vendor) ? '' : 'grayscale'"
        >
        <img
          v-if="isAppVendor(vendor)"
          src="/skins/c17/lobby/tag-app.webp"
          alt=""
          class="pointer-events-none absolute bottom-[-7%] right-0 h-1/4 w-[30%] object-contain"
        >
      </li>
    </ul>
  </div>
</template>
