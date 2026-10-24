<script setup lang="ts">
/**
 * 轉圈載入指示。尺寸與轉速照 bc.game：20×20（他們的 `.loading` 是 1.25rem）、
 * 1s linear 無限轉，淡的整圈加一段亮的弧。
 *
 * **和骨架的分工**：骨架用在「已經知道內容長什麼形狀」的地方（清單、表格、卡片），
 * spinner 用在「連形狀都還不知道」的地方——整頁切換、送出表單。bc.game 也是這樣分的：
 * 換路由時是一顆置中的圈，區塊重抓才是骨架。
 *
 * 顏色吃 `currentColor`，由呼叫端的文字色決定，所以不綁任何一張皮。
 */
withDefaults(defineProps<{
  /** 邊長（px）。設計稿沒有這個元件，20 是 bc.game 的值 */
  size?: number
}>(), { size: 20 })
</script>

<template>
  <svg
    class="wf-spin shrink-0"
    :width="size"
    :height="size"
    viewBox="0 0 20 20"
    fill="none"
    role="status"
    :aria-label="$t('common.ui.label.loading')"
  >
    <circle
      cx="10"
      cy="10"
      r="8"
      stroke="currentColor"
      stroke-width="2.5"
      stroke-opacity="0.2"
    />
    <path
      d="M10 2a8 8 0 0 1 8 8"
      stroke="currentColor"
      stroke-width="2.5"
      stroke-linecap="round"
    />
  </svg>
</template>
