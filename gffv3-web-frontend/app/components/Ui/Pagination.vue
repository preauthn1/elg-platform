<script setup lang="ts">
/**
 * 上一步／下一步分頁。設計稿沒有畫這一塊，沿用 C17 小按鈕的語彙：
 * 高 32、圓角 8、白底、1px 未啟用邊框，停用時降透明度。
 *
 * 訊息、遊戲列表、搜尋共用同一支；`total` 有給才顯示「總計 N」，
 * 後端沒回總筆數的清單就不要硬湊一個數字出來。
 */
const page = defineModel<number>('page', { required: true })
const props = withDefaults(defineProps<{
  pages: number
  /** 總筆數；null 就不顯示 */
  total?: number | null
}>(), { total: null })

function go(next: number) {
  if (next < 1 || next > props.pages) return
  page.value = next
}
</script>

<template>
  <nav
    v-if="pages > 1"
    class="flex items-center justify-center gap-(--c17-gap-3)"
    :style="{ fontSize: 'var(--c17-text-link)' }"
  >
    <button
      type="button"
      class="h-8 rounded-(--c17-field-radius) border border-field-line bg-surface px-(--c17-gap-3) font-bold disabled:opacity-40"
      :style="{ color: 'var(--c17-primary)' }"
      :disabled="page <= 1"
      @click="go(page - 1)"
    >
      {{ $t('common.pagination.action.prev') }}
    </button>

    <span
      class="font-semibold tabular-nums"
      :style="{ color: 'var(--c17-ink)' }"
    >{{ page }} / {{ pages }}</span>

    <button
      type="button"
      class="h-8 rounded-(--c17-field-radius) border border-field-line bg-surface px-(--c17-gap-3) font-bold disabled:opacity-40"
      :style="{ color: 'var(--c17-primary)' }"
      :disabled="page >= pages"
      @click="go(page + 1)"
    >
      {{ $t('common.ui.action.next') }}
    </button>

    <span
      v-if="total !== null"
      class="tabular-nums"
      :style="{ color: 'var(--c17-muted)' }"
    >{{ $t('common.pagination.label.total') }} {{ total }}</span>
  </nav>
</template>
