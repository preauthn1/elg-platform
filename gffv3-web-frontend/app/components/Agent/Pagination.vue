<script setup lang="ts">
import type { PageState } from '#shared/utils/agent'

/** 代理中心的分頁：PageState 轉給 C17 的 UiPagination，外觀與其他列表一致。 */
defineProps<{ page: PageState }>()
const emit = defineEmits<{ change: [page: number] }>()
</script>

<template>
  <UiPagination
    :page="page.current"
    :pages="page.pages"
    @update:page="emit('change', $event)"
  />
</template>
