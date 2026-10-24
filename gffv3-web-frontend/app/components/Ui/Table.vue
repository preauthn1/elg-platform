<script setup lang="ts">
/**
 * C17 的資料表。版型量自設計稿 Invite-Friends 的三張表（佣金記錄、業績查詢、團隊管理），
 * 外觀完全一樣，所以只寫一份，其他要列表格的頁面（贏家榜等）也共用：
 *
 *   外框   358 寬、圓角 8、底 #F1F4F8、1pt #B4B4B4 內框
 *   表頭   Plus Jakarta Sans Bold 12 #7A8599，置中
 *   內容   Plus Jakarta Sans SemiBold 11 #1A1F36，置中
 *
 * 欄寬平均分配（table-fixed），長字串換行不撐破容器。
 */
withDefaults(defineProps<{
  columns: string[]
  rows: string[][]
  /** 合計列，粗體呈現；設計稿沒畫，但業績查詢本來就有 */
  footer?: string[] | null
  /**
   * 載入中就畫骨架列，不要顯示「查無資料」。
   * **要傳 `useSkeleton()` 算出來的值，不可以直接把請求的 loading 丟進來**
   * （理由見 `app/composables/useSkeleton.ts`）。
   */
  loading?: boolean
  /** 骨架畫幾列。5 是 bc.game 的預設值 */
  skeletonRows?: number
}>(), {
  footer: null,
  loading: false,
  skeletonRows: 5,
})
</script>

<template>
  <div class="overflow-hidden rounded-(--c17-radius-sm) border border-field-line bg-surface-2">
    <table class="w-full table-fixed border-collapse">
      <thead>
        <tr>
          <th
            v-for="column in columns"
            :key="column"
            class="px-(--c17-gap-1) py-(--c17-gap-2) text-center font-bold"
            :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-muted)' }"
          >
            {{ column }}
          </th>
        </tr>
      </thead>
      <tbody>
        <!--
          載入中的骨架照 bc.game 的 TableSkeleton：不另外畫一組線條，
          而是**讓這張表自己畫 N 列空資料**，每一格塞一顆骨架。
          欄寬、列高、框線都是真的那一套，換上資料時不會位移。
        -->
        <template v-if="loading">
          <tr
            v-for="index in skeletonRows"
            :key="`skeleton-${index}`"
            class="border-t border-line"
          >
            <td
              v-for="column in columns.length"
              :key="column"
              class="px-(--c17-gap-1) py-(--c17-gap-2)"
            >
              <UiSkeleton class="h-3 w-full rounded" />
            </td>
          </tr>
        </template>

        <template v-else>
          <tr
            v-for="(row, index) in rows"
            :key="index"
            class="border-t border-line"
          >
            <td
              v-for="(cell, cellIndex) in row"
              :key="cellIndex"
              class="px-(--c17-gap-1) py-(--c17-gap-2) text-center font-semibold break-words"
              :style="{ fontSize: 'var(--c17-text-note)', color: 'var(--c17-ink)' }"
            >
              <!-- 要在格子裡放圖等非文字內容時用 #cell，沒傳就是純文字 -->
              <slot
                name="cell"
                :value="cell"
                :row-index="index"
                :column-index="cellIndex"
              >
                {{ cell }}
              </slot>
            </td>
          </tr>
          <tr
            v-if="footer"
            class="border-t border-line"
          >
            <td
              v-for="(cell, cellIndex) in footer"
              :key="cellIndex"
              class="px-(--c17-gap-1) py-(--c17-gap-2) text-center font-extrabold"
              :style="{ fontSize: 'var(--c17-text-note)', color: 'var(--c17-ink)' }"
            >
              {{ cell }}
            </td>
          </tr>
          <tr v-if="rows.length === 0 && !footer">
            <td
              :colspan="columns.length"
              class="px-(--c17-gap-1) py-(--c17-gap-3) text-center font-semibold"
              :style="{ fontSize: 'var(--c17-text-note)', color: 'var(--c17-muted)' }"
            >
              {{ $t('common.ui.empty.noData') }}
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>
