<script lang="ts" setup>
import type { NewbieChallengeBlockDetailRow } from '#/api';

import { ref } from 'vue';

import { ElDialog, ElTable, ElTableColumn } from 'element-plus';

import { getNewbieChallengeBlockDetailApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'NewbieChallengeBlockDetailDialog' });

const visible = ref(false);
const loading = ref(false);
const list = ref<NewbieChallengeBlockDetailRow[]>([]);

async function open(blockJoinId: number | string) {
  list.value = [];
  visible.value = true;
  loading.value = true;
  try {
    const res = await getNewbieChallengeBlockDetailApi(blockJoinId);
    list.value = Number(res.code) === 200 ? (res.data ?? []) : [];
  } finally {
    loading.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('detail_record')"
    width="720"
    align-center
  >
    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn :label="tl('reject_time')" prop="created_at" width="180" />
      <ElTableColumn :label="tl('reject_reason')" min-width="220">
        <template #default="{ row }">{{ row.message ?? '-' }}</template>
      </ElTableColumn>
      <ElTableColumn label="IP" min-width="140">
        <template #default="{ row }">{{ row.ip ?? '-' }}</template>
      </ElTableColumn>
      <template #empty>{{ tl('no_data') }}</template>
    </ElTable>
  </ElDialog>
</template>
