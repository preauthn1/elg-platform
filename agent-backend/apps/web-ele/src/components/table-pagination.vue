<script lang="ts" setup>
import { useMediaQuery } from '@vueuse/core';
import { ElPagination } from 'element-plus';

defineOptions({ name: 'TablePagination' });

withDefaults(
  defineProps<{
    currentPage: number;
    layout?: string;
    pageSize: number;
    pageSizes?: number[];
    total: number;
  }>(),
  { layout: 'total, prev, pager, next, jumper', pageSizes: () => [] },
);

const emit = defineEmits<{
  currentChange: [page: number];
  sizeChange: [size: number];
}>();

// 窄螢幕：縮小尺寸並減少頁碼鈕，配合換行避免被裁切
const isNarrow = useMediaQuery('(max-width: 640px)');
</script>

<template>
  <div class="mt-4 flex justify-end">
    <ElPagination
      :current-page="currentPage"
      :layout="layout"
      :page-size="pageSize"
      :page-sizes="pageSizes"
      :pager-count="isNarrow ? 5 : 7"
      :size="isNarrow ? 'small' : 'default'"
      :total="total"
      background
      class="flex-wrap justify-end gap-y-2"
      @current-change="emit('currentChange', $event)"
      @size-change="emit('sizeChange', $event)"
    />
  </div>
</template>
