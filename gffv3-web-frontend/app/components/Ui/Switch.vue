<script setup lang="ts">
/**
 * 開關。只負責顯示 on/off 與發出 click，**不自己翻轉狀態**——
 * 切換常常要先過驗證（例如 IP 變更驗證要先驗碼），所以值由呼叫端在成功後才改。
 */
defineProps<{
  on: boolean
  disabled?: boolean
  label?: string
}>()

const emit = defineEmits<{ click: [] }>()
</script>

<template>
  <button
    type="button"
    role="switch"
    :aria-checked="on"
    :aria-label="label"
    :disabled="disabled"
    class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors disabled:opacity-40"
    :class="on ? 'bg-accent' : 'bg-line-strong'"
    @click="emit('click')"
  >
    <span
      class="absolute left-1 size-5 rounded-full bg-inverse shadow transition-transform"
      :class="on ? 'translate-x-5' : 'translate-x-0'"
    />
  </button>
</template>
