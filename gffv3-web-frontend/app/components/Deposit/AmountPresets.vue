<script setup lang="ts">
import { computed } from 'vue'
import { presetAmounts } from '#shared/utils/deposit'
import { decFormat } from '#shared/utils/amount'

/**
 * 金額快選（設計稿 Wallet-page）：一排四顆 80×32 r8 白底方鈕，
 * 投影 #1A1F3642 2/2/2，數字 PJS ExtraBold 14 #1565FF。
 */
const props = defineProps<{ min: number, max: number }>()
const emit = defineEmits<{ select: [amount: number] }>()

const amounts = computed(() => presetAmounts(props.min, props.max))
</script>

<template>
  <div
    v-if="amounts.length"
    class="grid grid-cols-4 gap-x-(--c17-gap-3) gap-y-(--c17-tile-gap)"
  >
    <button
      v-for="a in amounts"
      :key="a"
      type="button"
      class="h-8 rounded-(--c17-field-radius) bg-surface font-extrabold tabular-nums"
      :style="{
        fontSize: 'var(--c17-text-body)',
        color: 'var(--c17-primary)',
        boxShadow: 'var(--c17-shadow-panel)',
      }"
      @click="emit('select', a)"
    >
      {{ decFormat(a, 0) }}
    </button>
  </div>
</template>
