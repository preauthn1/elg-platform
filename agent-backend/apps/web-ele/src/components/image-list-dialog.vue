<script lang="ts" setup>
import type { AdvertiseImageRow } from '#/api';

import { ref } from 'vue';

import { ElDialog, ElImage } from 'element-plus';

import { getAdvertiseImageListApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';

defineOptions({ name: 'ImageListDialog' });

const emit = defineEmits<{
  select: [payload: { id: number | string; target: string; url: string }];
}>();

const PER_PAGE = 6;

const visible = ref(false);
const loading = ref(false);
const images = ref<AdvertiseImageRow[]>([]);
const total = ref(0);
const page = ref(1);
/** 呼叫端要回填的目標欄位識別 */
const target = ref('');

async function loadData() {
  loading.value = true;
  try {
    const res = await getAdvertiseImageListApi({
      page: page.value,
      per_page: PER_PAGE,
    });
    if (Number(res.code) === 1000) {
      images.value = res.data ?? [];
      total.value = res.pagination?.total ?? 0;
    } else {
      images.value = [];
      total.value = 0;
    }
  } finally {
    loading.value = false;
  }
}

function open(targetKey = '') {
  target.value = targetKey;
  page.value = 1;
  images.value = [];
  total.value = 0;
  visible.value = true;
  loadData();
}

function handlePageChange(p: number) {
  page.value = p;
  loadData();
}

function pick(row: AdvertiseImageRow) {
  emit('select', { id: row.id, target: target.value, url: row.image_url });
  visible.value = false;
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('upload_image')"
    align-center
    append-to-body
    width="820"
  >
    <div v-loading="loading" class="grid grid-cols-2 gap-3 md:grid-cols-3">
      <div
        v-for="img in images"
        :key="img.id"
        class="cursor-pointer rounded border p-1 hover:border-blue-500"
        @click="pick(img)"
      >
        <ElImage
          :src="img.image_url"
          fit="contain"
          style="width: 100%; height: 160px"
        />
      </div>
      <div
        v-if="!loading && images.length === 0"
        class="col-span-full py-8 text-center text-gray-400"
      >
        {{ tl('no_record') }}
      </div>
    </div>
    <TablePagination
      :current-page="page"
      :page-size="PER_PAGE"
      :total="total"
      layout="total, prev, pager, next"
      @current-change="handlePageChange"
    />
  </ElDialog>
</template>
