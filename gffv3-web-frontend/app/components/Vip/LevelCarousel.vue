<script setup lang="ts">
import type { VipContent } from '#shared/api/endpoints/vip'
import type { DisplayValue } from '#shared/utils/vip'
import { describeTargetAmount } from '#shared/utils/vip'

/**
 * 設計稿 VIP-open-all-VIP：一次只看一個等級，左右箭頭翻頁。
 * 上面是等級徽章與升級門檻，下面是該等級的權益逐條列出。
 *
 * 權益那一段交給 VipBenefitRows，不在這裡再組一份——兩邊列的是同一組東西，
 * 只有這裡多了翻頁。徽章圖用後端給的 content.image；沒有就退回純色圓形加等級數字。
 */
const props = defineProps<{
  /** 已依 level 升冪排序的 vip/contents */
  contents: VipContent[]
  /** 進來時先停在哪一級（通常是會員目前等級） */
  initialLevel?: number
}>()

const index = ref(0)

/** 進來時對到會員目前等級；找不到就停在第一級。 */
watchEffect(() => {
  if (props.initialLevel == null) return
  const at = props.contents.findIndex(c => Number(c.level) === Number(props.initialLevel))
  if (at >= 0) index.value = at
})

const current = computed<VipContent | null>(() => props.contents[index.value] ?? null)
const canPrev = computed(() => index.value > 0)
const canNext = computed(() => index.value < props.contents.length - 1)

const { t } = useI18n()

/** describe* 回的是 { i18n } 或 { text }，翻法與 VipLevelTable 一致。 */
function show(value: DisplayValue): string {
  return 'i18n' in value ? t(value.i18n) : value.text
}
</script>

<template>
  <div
    v-if="current"
    class="space-y-(--c17-gap-4)"
  >
    <!--
      等級切換。設計稿（VIP-open-all-VIP）量到的：

        卡片    358×600 @ X16 Y45
        徽章    80×80   @ X155 Y117   置中
        左箭頭  39×40   @ X36  Y143   距卡片左緣 20
        右箭頭  39×40   @ X316 Y143   距卡片右緣 19

      所以箭頭是**貼著卡片兩側**，不是夾在徽章旁邊——用 justify-between 把三者推開，
      不要用 gap 讓它們擠在中間。

      鈕本身是琥珀金圓鈕（上 #d7ab13 → 下 #a98201）＋**粗實心白箭頭**＋淡投影；
      箭頭是填滿的多邊形，不是細線條的 stroke。
    -->
    <div class="flex items-center justify-between">
      <button
        type="button"
        class="flex size-10 shrink-0 items-center justify-center rounded-full disabled:opacity-30"
        :style="{
          background: 'var(--c17-vip-nav-bg)',
          color: 'var(--c17-vip-nav-ink)',
          boxShadow: 'var(--c17-vip-nav-shadow)',
        }"
        :disabled="!canPrev"
        :aria-label="$t('common.pagination.action.previousPage')"
        @click="index -= 1"
      >
        <svg
          viewBox="0 0 24 24"
          class="size-[16px]"
          fill="currentColor"
          aria-hidden="true"
        >
          <path d="M18 3.5 L12.8 3.5 L4 12 L12.8 20.5 L18 20.5 L9.2 12 Z" />
        </svg>
      </button>

      <div class="flex flex-col items-center gap-1">
        <img
          v-if="current.image"
          :src="current.image"
          alt=""
          class="size-20 object-contain"
        >
        <span
          v-else
          class="flex size-20 items-center justify-center rounded-full text-lg font-extrabold text-ink"
          :style="{ backgroundColor: 'var(--c17-accent)' }"
        >VIP {{ current.level }}</span>
        <!-- 徽章下面那行：設計稿是 Inter ExtraBold 14、置中、**紅棕 #A85336**，不是預設的深色 -->
        <p
          class="font-extrabold"
          :style="{
            fontFamily: 'var(--c17-font-display)',
            fontSize: 'var(--c17-text-body)',
            color: 'var(--c17-vip-level-ink)',
          }"
        >
          VIP {{ current.level }}
        </p>
        <p
          v-if="current.name"
          class="text-(length:--c17-text-label) text-muted"
        >
          {{ current.name }}
        </p>
      </div>

      <button
        type="button"
        class="flex size-10 shrink-0 items-center justify-center rounded-full disabled:opacity-30"
        :style="{
          background: 'var(--c17-vip-nav-bg)',
          color: 'var(--c17-vip-nav-ink)',
          boxShadow: 'var(--c17-vip-nav-shadow)',
        }"
        :disabled="!canNext"
        :aria-label="$t('common.pagination.nav.nextPage')"
        @click="index += 1"
      >
        <svg
          viewBox="0 0 24 24"
          class="size-[16px]"
          fill="currentColor"
          aria-hidden="true"
        >
          <path d="M6 3.5 L11.2 3.5 L20 12 L11.2 20.5 L6 20.5 L14.8 12 Z" />
        </svg>
      </button>
    </div>

    <!--
      升級門檻：設計稿是**兩行置中**，不是「標籤：值」擠成一行。
      標籤 PJS SemiBold 12、值 PJS SemiBold 14，兩者都是 #1A1F36。
    -->
    <div class="text-center">
      <p
        class="flex items-center justify-center gap-(--c17-gap-2)"
        :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
      >
        <!-- 設計稿這一顆比列表裡的小：22×22，圓角 4 -->
        <img
          src="/skins/c17/vip/upgrade.webp"
          alt=""
          class="size-[22px] shrink-0 rounded-(--c17-radius-xs) object-contain"
        >
        {{ $t('vip.condition.label.upgradeRequirement') }}
      </p>
      <p
        class="font-semibold"
        :style="{ fontSize: 'var(--c17-text-body)', color: 'var(--c17-ink)' }"
      >
        {{ show(describeTargetAmount(current)) }}
      </p>
    </div>

    <!-- 設計稿在門檻與權益之間有一條螢光綠細線 -->
    <span
      class="block h-px"
      :style="{ background: 'var(--c17-accent)' }"
      aria-hidden="true"
    />

    <!--
      權益列直接用 VipBenefitRows：設計稿 VIP 與 VIP_All-Benefits_pop out 列的是同一組，
      不必再寫一份。晉級門檻在這裡已經用置中兩行呈現過，所以關掉那一列避免重複。

      每日提款次數／每日提款上限不在設計稿的彈窗裡，依設計稿不列；
      那些資訊在 VIP 頁的等級表（VipLevelTable）仍然看得到。
    -->
    <VipBenefitRows
      :content="current"
      :padded="false"
      :show-upgrade="false"
    />
  </div>

  <p
    v-else
    class="py-(--c17-gap-6) text-center text-muted"
  >
    {{ $t('common.ui.empty.noData') }}
  </p>
</template>
