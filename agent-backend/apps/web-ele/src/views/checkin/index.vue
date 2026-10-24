<script lang="ts" setup>
import type { CheckinRow } from '#/api';

import { onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getCheckinListApi, updateCheckinStatusApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import { tl } from '#/locales';

import CheckinEditDialog from './components/checkin-edit-dialog.vue';

defineOptions({ name: 'CheckinIndex' });

const loading = ref(false);
const list = ref<CheckinRow[]>([]);
const editRef = ref<InstanceType<typeof CheckinEditDialog>>();

async function loadData() {
  loading.value = true;
  try {
    const res = await getCheckinListApi();
    if (Number(res.code) === 200) {
      list.value = res.data ?? [];
    } else {
      list.value = [];
      ElMessage.warning(res.message);
    }
  } finally {
    loading.value = false;
  }
}

function goEdit(id?: number | string) {
  editRef.value?.open(id ?? '');
}

async function toggle(row: CheckinRow) {
  const next = Number(row.status) === 1 ? 0 : 1;
  const res = await updateCheckinStatusApi({ id: row.id, status: next });
  if (Number(res.code) === 200) {
    ElMessage.success(tl('success'));
    loadData();
  } else {
    ElMessage.error(res.message);
  }
}

onMounted(loadData);
</script>

<template>
  <Page :title="`${tl('menu_checkin')} > ${tl('menu_checkin_index')}`">
    <ElCard shadow="never">
      <div class="mb-3">
        <ElButton type="success" @click="goEdit()">
          {{ tl('event_create') }}
        </ElButton>
      </div>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('agent_name')"
          min-width="120"
          prop="agent_name"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_name" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('label_start_time')" min-width="150">
          <template #default="{ row }">
            <span class="text-info">{{ row.start_date }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('label_end_time')" min-width="150">
          <template #default="{ row }">
            <span class="text-info">{{ row.end_date }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" min-width="90">
          <template #default="{ row }">
            <span
              :class="
                Number(row.status) === 1 ? 'text-green-600' : 'text-red-500'
              "
            >
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('features')"
          align="center"
          width="150"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton size="small" type="primary" @click="goEdit(row.id)">
              {{ tl('edit') }}
            </ElButton>
            <ElButton size="small" @click="toggle(row as CheckinRow)">
              {{ Number(row.status) === 1 ? tl('disable') : tl('enable') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>

    <CheckinEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
