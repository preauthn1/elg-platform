<script setup lang="ts">
/**
 * 六格數字密碼輸入（支付密碼用），照舊版 C13 `Base/PaymentPasswordInput.vue`：
 * 每格一碼、打完自動跳下一格、空格按 Backspace 退回上一格、Enter 送出，掛載時聚焦第一格。
 * 只收數字，字以圓點遮住。
 */
const model = defineModel<string>({ default: '' })

const props = withDefaults(defineProps<{
  length?: number
  disabled?: boolean
  /** 錯誤態（例如兩次輸入不一致），外框轉紅 */
  error?: boolean
}>(), {
  length: 6,
})

const emit = defineEmits<{
  submit: []
}>()

const cells = ref<string[]>(Array.from({ length: props.length }, (_, i) => model.value[i] ?? ''))
const inputs = useTemplateRef<HTMLInputElement[]>('cellInputs')

watch(model, (value) => {
  if (value === cells.value.join('')) return
  cells.value = Array.from({ length: props.length }, (_, i) => value[i] ?? '')
})

function onInput(index: number, event: Event) {
  const target = event.target as HTMLInputElement
  const digit = target.value.replace(/\D/g, '').slice(-1)
  cells.value[index] = digit
  target.value = digit
  model.value = cells.value.join('')
  if (digit && index < props.length - 1) inputs.value?.[index + 1]?.focus()
}

function onKeydown(index: number, event: KeyboardEvent) {
  if (event.key === 'Backspace' && !cells.value[index] && index > 0) {
    inputs.value?.[index - 1]?.focus()
  }
  if (event.key === 'Enter') {
    event.preventDefault()
    ;(event.target as HTMLInputElement).blur()
    emit('submit')
  }
}

onMounted(() => {
  inputs.value?.[0]?.focus()
})
</script>

<template>
  <div class="flex justify-center gap-(--c17-gap-2)">
    <input
      v-for="(cell, index) in cells"
      :key="index"
      ref="cellInputs"
      :value="cell"
      type="tel"
      inputmode="numeric"
      maxlength="1"
      autocomplete="off"
      :aria-label="`${index + 1}`"
      :disabled="disabled"
      class="h-(--c17-field-h) w-full max-w-12 min-w-0 rounded-(--c17-field-radius) border bg-field text-center font-semibold text-ink focus:ring-2 focus:ring-accent/25 focus:outline-none [-webkit-text-security:disc] disabled:bg-disabled disabled:text-disabled-ink"
      :class="error ? 'border-field-line-error' : cell ? 'border-field-line-done' : 'border-field-line'"
      :style="{ fontSize: 'var(--c17-text-lg)' }"
      @input="onInput(index, $event)"
      @keydown="onKeydown(index, $event)"
    >
  </div>
</template>
