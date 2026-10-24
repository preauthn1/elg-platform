<script setup lang="ts">
/**
 * 載入骨架。設計稿沒有畫載入狀態，但只要畫面內容依賴非同步資料就該有，
 * 不要只留空白、也不要自己刻轉圈。
 *
 * **這支只管長相，不管時機。** 什麼時候該出現由 `useSkeleton()` 決定
 * （理由見那支的說明）：
 *
 * ```vue
 * const showSkeleton = useSkeleton(() => status === 'pending')
 * <UiSkeleton v-if="showSkeleton" :rows="6" />
 * ```
 *
 * 兩種用法：
 *   `<UiSkeleton class="aspect-square rounded-lg" />`  一塊，尺寸圓角由 class 決定
 *   `<UiSkeleton :rows="6" />`                          6 條文字列，最後一條短的
 *
 * 形狀不合就用第一種自己排，例如三欄磚：
 *
 * ```vue
 * <div class="grid grid-cols-3 gap-2.5">
 *   <UiSkeleton v-for="i in 9" :key="i" class="aspect-square rounded-lg" />
 * </div>
 * ```
 *
 * 長相照 bc.game：兩層灰慢慢呼吸，不是掃光（規則與理由在 `app/assets/css/main.css`）。
 *
 * **不綁任何一張皮**：兩層底色走 `--wf-skeleton-*`，間距圓角用 Tailwind 自己的尺標，
 * 沒有 `--c17-*`，所以其他皮搬進來可以照用。
 */
const props = withDefaults(defineProps<{
  /** 幾條文字列。設成與實際內容接近的數量，換成真資料時高度才不會跳；1 是單獨一塊 */
  rows?: number
}>(), { rows: 1 })

const lines = computed(() => Math.max(1, Math.floor(props.rows)))
</script>

<template>
  <span
    v-if="lines === 1"
    class="wf-skeleton block"
    role="status"
    aria-busy="true"
    :aria-label="$t('common.ui.label.loading')"
  />

  <span
    v-else
    class="flex w-full flex-col gap-2"
    role="status"
    aria-busy="true"
    :aria-label="$t('common.ui.label.loading')"
  >
    <span
      v-for="i in lines"
      :key="i"
      class="wf-skeleton block h-3 rounded"
      :class="i === lines ? 'w-3/5' : 'w-full'"
    />
  </span>
</template>
