<script setup lang="ts">
/**
 * 內頁的深色標題列（設計稿 Wallet-page 量到的）：
 *   390×52，底 #1565FF→#1A1F36 260°（= --c17-header-bg），投影 #7A859933 3/3/6
 *   返回鍵 8×12 @16,21、標題 PJS SemiBold 12 #F1F4F8 @36,19
 *   右邊接餘額膠囊 174×36 @200,8（= C17BalancePill）
 *
 * 首頁那條是 C17AppHeader（logo + 膠囊），這條是有返回鍵與標題的內頁版；
 * 用這條的頁面要設 `hideAppHeader`，否則會出現兩條列。
 */
withDefaults(defineProps<{
  title: string
  /** 返回時沒有上一頁可退的話要去哪 */
  fallback?: string
  /** 右側是否顯示餘額膠囊 */
  balance?: boolean
  /** 膠囊顯示主錢包還是遊戲商餘額，見 C17BalancePill 的 source */
  balanceSource?: 'main' | 'vendor'
  /** vendor 模式下這個遊戲商的 giftcode（決定要不要多顯示禮碼） */
  giftcode?: number | string | boolean | null
  /** 分步驟的頁面用：回傳 true 表示已退回上一步，不離開頁面 */
  stepBack?: () => boolean
}>(), {
  fallback: '/',
  balance: true,
  balanceSource: 'main',
  giftcode: 0,
})
</script>

<template>
  <header
    class="flex h-13 items-center gap-(--c17-gap-3) px-(--c17-pad-x)"
    :style="{ background: 'var(--c17-header-bg)', boxShadow: 'var(--c17-shadow-card)' }"
  >
    <button
      type="button"
      class="shrink-0"
      :aria-label="$t('common.ui.action.back')"
      @click="stepBack?.() || backOr(fallback)"
    >
      <svg
        viewBox="0 0 8 12"
        class="h-3 w-2"
        fill="currentColor"
        :style="{ color: 'var(--c17-primary-ink)' }"
        aria-hidden="true"
      >
        <path d="M6.1 0 7.5 1.4 2.9 6l4.6 4.6L6.1 12 .1 6z" />
      </svg>
    </button>

    <h1
      class="min-w-0 flex-1 truncate font-semibold"
      :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-primary-ink)' }"
    >
      {{ title }}
    </h1>

    <C17BalancePill
      v-if="balance"
      class="shrink-0"
      :source="balanceSource"
      :giftcode="giftcode"
    />
  </header>
</template>
