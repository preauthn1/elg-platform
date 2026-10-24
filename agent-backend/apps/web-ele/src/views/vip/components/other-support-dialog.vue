<script lang="ts" setup>
import { ref } from 'vue';

import { ElDialog } from 'element-plus';

import { getVipOtherSupportApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'VipOtherSupportDialog' });

const visible = ref(false);
const loading = ref(false);
const items = ref<Array<number | string>>([]);

async function open(id: number | string) {
  visible.value = true;
  loading.value = true;
  items.value = [];
  try {
    const res = await getVipOtherSupportApi(id);
    items.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
  } finally {
    loading.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('other_support')"
    width="700"
    align-center
  >
    <div v-loading="loading" class="min-h-24 text-center">
      <p v-if="!loading && items.length === 0" class="text-gray-400">
        {{ tl('no_record') }}
      </p>
      <p v-for="(it, idx) in items" :key="idx" class="my-2 text-lg">
        {{ tl(`other_support_list.${it}`) }}
      </p>
    </div>
  </ElDialog>
</template>
