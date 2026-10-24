<script setup lang="ts">
defineProps<{ message: string }>()
const emit = defineEmits<{ close: [] }>()
</script>

<template>
  <div
    v-if="message"
    class="rounded border border-warning bg-warning-soft px-3 py-2 text-sm text-warning"
  >
    <p class="whitespace-pre-line">
      {{ message }}
    </p>
    <button
      type="button"
      class="mt-2 rounded border px-3 py-1 text-xs"
      @click="emit('close')"
    >
      {{ $t('common.ui.action.close') }}
    </button>
  </div>
</template>
