<script setup lang="ts">
import type { VendorGameType } from '#shared/api/endpoints/games'
import { gameTypeLabelKey, rootTypeIds } from '#shared/utils/games'

/**
 * 遊戲分類磚。類型清單來自 vendor/game-types 的 root_type，順序走既有的 TYPE_TAB_ORDER。
 *
 * 版型依設計稿的素材比例決定，四欄格線：
 *   真人 2 欄 × 2 列（正方形）、老虎機與體育各 2 欄 × 1 列（2:1 橫的）、其餘 1 欄（正方形）。
 * 素材在 public/skins/c17/category/<typeId>.webp，沒有對應圖的類型（後台開了但設計稿沒畫）
 * 退回漸層底加類型名稱，不會破版也不會白底白字。
 */
const props = defineProps<{ gameTypes: VendorGameType[] }>()

/**
 * 設計稿的排列順序與每格佔的位置。順序是皮的決定，不跟著 API 的 TYPE_TAB_ORDER 走，
 * 這樣格線才會排成「真人大方塊在左、老虎機與體育疊在右」。
 */
const DESIGN_ORDER = [1, 2, 18, 3, 4, 5, 15] as const
const WIDE = new Set<number>([2, 18]) // 老虎機與體育是 2:1 橫的
const FEATURED = 1 // 真人是 2 欄 × 2 列的大方塊

/** 設計稿畫過、而且後台有開的類型——這幾個排成設計稿那個格線。 */
const types = computed(() => {
  const available = new Set(rootTypeIds(props.gameTypes))
  return DESIGN_ORDER.filter(id => available.has(id))
})

/**
 * **設計稿沒畫的類型不顯示。** 曾經另外排一列小膠囊把入口留著，但設計稿上沒有那一列，
 * 已依指示移除——後台要讓某個類型露出來，就把它排進上面的格線（方形或長形都選得到），
 * 不要再補一列設計稿以外的東西。
 *
 * 代價講清楚：不在 DESIGN_ORDER 裡的類型在首頁沒有入口，只能從選單或 /vendors 進去。
 */

function imageOf(typeId: number): string {
  return `/skins/c17/category/${typeId}.webp`
}

function to(typeId: number): string {
  return `/vendors?typeId=${typeId}`
}

/**
 * 只有設計稿畫過的類型才有素材。用清單判斷而不是等 img 的 error 事件：
 * SSR 送出的 HTML 在水合前就會觸發 404，那時監聽還沒掛上，破圖會留在畫面上。
 */
const HAS_ASSET = new Set<number>(DESIGN_ORDER)

/** 大方塊佔 2 欄 2 列，橫的佔 2 欄，其餘 1 欄。 */
function spanClass(typeId: number): string {
  if (typeId === FEATURED) return 'col-span-2 row-span-2'
  return WIDE.has(typeId) ? 'col-span-2' : ''
}

/**
 * 設計稿量到的尺寸：square 82×82、wide 174×82、featured 174×174，磚間距 10。
 * wide 不是 2:1——174/82 = 2.122，寫成 2/1 會高出 5px，下面每一列都跟著往下位移。
 */
function aspectClass(typeId: number): string {
  return WIDE.has(typeId) ? 'aspect-[174/82]' : 'aspect-square'
}

/**
 * 匯出的 PNG **把陰影烤進去了**，圖比磚本身大一圈。實測（讀 alpha 通道掃四邊）
 * 四張素材的外擴完全一致：左5 上3 右13 下15（@2x；換算 1x 是 2.5 / 1.5 / 6.5 / 7.5），
 * 扣掉之後的藝術區剛好等於設計尺寸的 @2x（174×82 → 348×164）。
 *
 * 所以圖要**往外放**，讓藝術區對齊格子、陰影落在磚之間的間距上。
 * **不要改成 CSS box-shadow**：那會和圖裡的陰影疊成兩層（踩過一次）。
 */
const BLEED = { left: 2.5, top: 1.5, right: 6.5, bottom: 7.5 }

/**
 * 用 left/top + width/height，**不要用四邊 inset**：img 是替換元素，
 * `width:auto` 會直接吃圖的固有寬度而把 right/bottom 當成沒設，圖就爆版了。
 */
function bleedStyle(typeId: number): Record<string, string> {
  const w = typeId === FEATURED || WIDE.has(typeId) ? 174 : 82
  const h = typeId === FEATURED ? 174 : 82
  return {
    left: `${(-BLEED.left / w) * 100}%`,
    top: `${(-example.invalid / h) * 100}%`,
    width: `${((w + BLEED.left + BLEED.right) / w) * 100}%`,
    height: `${((h + example.invalid + BLEED.bottom) / h) * 100}%`,
  }
}
</script>

<template>
  <section
    v-if="types.length > 0"
    class="grid grid-cols-4 gap-[var(--c17-tile-gap)]"
    :aria-label="$t('games.category.option.allGames')"
    :style="{ paddingInline: 'var(--c17-pad-x)' }"
  >
    <!--
      有素材的磚：圓角與陰影都烤在 PNG 裡，所以容器**不裁切、不畫陰影、不給底色**，
      圖用 bleedStyle 往外放（見上面 BLEED 的說明）。
      沒素材的磚才需要自己畫圓角、底色與陰影。
    -->
    <NuxtLink
      v-for="typeId in types"
      :key="typeId"
      :to="to(typeId)"
      class="relative"
      :class="[
        spanClass(typeId),
        aspectClass(typeId),
        HAS_ASSET.has(typeId) ? '' : 'overflow-hidden rounded-[var(--c17-radius-sm)]',
      ]"
      :style="HAS_ASSET.has(typeId)
        ? undefined
        : { background: 'var(--c17-card-bg)', boxShadow: 'var(--c17-shadow-category)' }"
    >
      <!-- 標題永遠在底層，圖載到就整塊蓋上去（素材本身已含類型名稱） -->
      <span
        class="absolute font-extrabold uppercase"
        :class="HAS_ASSET.has(typeId)
          ? 'left-1.5 top-1.5'
          : 'inset-0 grid place-items-center px-1.5 text-center leading-tight'"
        :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
      >
        {{ $t(gameTypeLabelKey(typeId)) }}
      </span>
      <img
        v-if="HAS_ASSET.has(typeId)"
        :src="imageOf(typeId)"
        alt=""
        class="absolute max-w-none"
        :style="bleedStyle(typeId)"
      >
    </NuxtLink>
  </section>
</template>
