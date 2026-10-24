<script setup lang="ts">
/**
 * 核取方塊。三種狀態對齊 XD 的 Form components：
 *   未勾 灰框白底 ／ 已勾 螢光底 #d8ff1a + 綠框 + 深色勾 ／ 錯誤 紅框
 * 原生 input 保留但隱藏，讓鍵盤與表單驗證照常運作。
 */
const model = defineModel<boolean>({ default: false })

defineProps<{
  /** 有值就是錯誤態（例如沒勾同意條款就送出） */
  error?: string
  disabled?: boolean
}>()
</script>

<template>
  <div class="space-y-1">
    <label class="flex items-start gap-2">
      <input
        v-model="model"
        type="checkbox"
        :disabled="disabled"
        class="peer sr-only"
      >
      <!--
        設計稿 Form components 的核取方塊：20×20、圓角 4、1px 內框，三種狀態
          未勾選  底 #ebebeb（bg-field-muted）框 #b4b4b4（border-field-line）
          已勾選  底 #d8ff1a（bg-highlight）框 #1bc964（border-field-line-done）
          錯誤    底 #ebebeb              框 #ff4d4f（border-field-line-error）
        已勾選**不是** bg-accent——那是藍色主色，挑錯這顆整片會變藍。
      -->
      <span
        aria-hidden="true"
        class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-(--c17-radius-xs) border peer-focus-visible:ring-2 peer-focus-visible:ring-accent"
        :class="[
          model
            ? 'border-field-line-done bg-highlight'
            : error ? 'border-field-line-error bg-field-muted' : 'border-field-line bg-field-muted',
          disabled ? 'opacity-50' : '',
        ]"
      >
        <svg
          v-if="model"
          viewBox="0 0 12 10"
          class="h-2.5 w-3"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
          :style="{ color: 'var(--c17-ink)' }"
        >
          <path d="M1 5.2 4.3 8.5 11 1.5" />
        </svg>
      </span>
      <!-- 標籤字色照設計稿是連結藍 #1343bb（它唯一畫過的用法是條款連結） -->
      <span
        class="font-semibold leading-tight text-link"
        :style="{ fontSize: 'var(--c17-text-link)' }"
      >
        <slot />
      </span>
    </label>
    <p
      v-if="error"
      class="text-[length:var(--c17-text-label)] text-danger"
    >
      {{ error }}
    </p>
  </div>
</template>
