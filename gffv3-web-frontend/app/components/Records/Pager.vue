<script setup lang="ts">
import { recordPageCount } from '~/utils/records'

const props = defineProps<{
  total: number
  perPage: number
}>()

const page = defineModel<number>('page', { required: true })

const pages = computed(() => recordPageCount(props.total, props.perPage))
</script>

<template>
  <div
    v-if="total > perPage"
    class="flex items-center gap-3 text-sm"
  >
    <button
      type="button"
      class="rounded border border-line px-2 py-1 disabled:opacity-40"
      :disabled="page <= 1"
      @click="page = page - 1"
    >
      {{ $t('common.pagination.action.prev') }}
    </button>
    <span>{{ page }} / {{ pages }}</span>
    <button
      type="button"
      class="rounded border border-line px-2 py-1 disabled:opacity-40"
      :disabled="page >= pages"
      @click="page = page + 1"
    >
      {{ $t('common.ui.action.next') }}
    </button>
  </div>
</template>
