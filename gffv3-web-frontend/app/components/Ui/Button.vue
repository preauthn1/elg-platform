<script setup lang="ts">
/**
 * 主要按鈕。三種狀態對齊 XD 的 Form components：
 *   未啟用 灰底 #7a8599 ／ 一般 藍底 #1565ff ／ hover 深藍 #13438b
 * 字是 Plus Jakarta Sans ExtraBold 全大寫（--c17-font-label）。
 */
withDefaults(defineProps<{
  type?: 'button' | 'submit' | 'reset'
  disabled?: boolean
  loading?: boolean
  /** 次要樣式：白底藍框，用在成對的取消／上一步 */
  variant?: 'primary' | 'secondary'
  block?: boolean
}>(), {
  type: 'button',
  variant: 'primary',
  block: true,
})
</script>

<template>
  <!--
    設計稿 Form components 的主要按鈕三態：
      non-active  底 #7a8599  字 #f1f4f8
      active      底 #1565ff  字 #f1f4f8
      hover       底 #1343bb  字 #d8ff1a ← 字色也會翻成螢光，不是只有底色變
  -->
  <button
    :type="type"
    :disabled="disabled || loading"
    class="inline-flex min-h-(--c17-btn-h) items-center justify-center rounded-(--c17-field-radius) px-4 py-1.5 text-center leading-tight font-extrabold uppercase transition-colors"
    :class="[
      block ? 'w-full' : '',
      variant === 'primary'
        ? 'bg-accent text-inverse hover:bg-accent-hover hover:text-accent-hover-ink disabled:bg-disabled disabled:text-disabled-ink'
        : 'border border-accent bg-surface text-accent hover:bg-accent-soft disabled:border-disabled disabled:text-disabled',
    ]"
    :style="{ fontFamily: 'var(--c17-font-label)', fontSize: 'var(--c17-text-body)' }"
  >
    <slot />
  </button>
</template>
