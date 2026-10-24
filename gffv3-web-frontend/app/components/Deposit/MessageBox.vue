<script setup lang="ts">
defineProps<{ message: string, okText?: string }>()
const emit = defineEmits<{ close: [] }>()
</script>

<template>
  <UiModal
    :dismissible="false"
    @close="emit('close')"
  >
    <div class="flex flex-col gap-(--c17-gap-4)">
      <p
        class="whitespace-pre-wrap break-words"
        :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
      >
        {{ message }}
      </p>
      <UiButton @click="emit('close')">
        {{ okText ?? $t('common.ui.action.confirm') }}
      </UiButton>
    </div>
  </UiModal>
</template>
