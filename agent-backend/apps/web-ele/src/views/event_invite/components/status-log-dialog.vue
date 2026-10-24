<script lang="ts" setup>
import type { EventInviteStatusLogRow } from '#/api';

import { ref } from 'vue';

import { ElDialog, ElTable, ElTableColumn } from 'element-plus';

import { getEventInviteStatusLogApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';

import { eventInviteStatusText } from '../status-map';

defineOptions({ name: 'EventInviteStatusLogDialog' });

const PER_PAGE = 20;

const visible = ref(false);
const loading = ref(false);
const memberId = ref<number | string>(0);
const list = ref<EventInviteStatusLogRow[]>([]);
const page = ref(1);
const total = ref(0);

async function load() {
  loading.value = true;
  try {
    const res = await getEventInviteStatusLogApi({
      member_id: memberId.value,
      page: page.value,
      per_page: PER_PAGE,
    });
    list.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
    total.value = res.pagination?.total ?? 0;
  } finally {
    loading.value = false;
  }
}

function open(id: number | string) {
  memberId.value = id;
  page.value = 1;
  visible.value = true;
  load();
}

function handlePage(p: number) {
  page.value = p;
  load();
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('menu_event_invite')}${tl('status')}`"
    width="720"
    align-center
  >
    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn :label="tl('time')" prop="created_at" width="180" />
      <ElTableColumn :label="tl('before_change')" min-width="110">
        <template #default="{ row }">
          {{ eventInviteStatusText(row.before_status) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('after_change')" min-width="110">
        <template #default="{ row }">
          {{ eventInviteStatusText(row.after_status) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('operator')" prop="operator" min-width="140">
        <template #default="{ row }">
          <AgentAccount :username="row.operator" />
        </template>
      </ElTableColumn>
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>
    <TablePagination
      :current-page="page"
      :page-size="PER_PAGE"
      :total="total"
      layout="total, prev, pager, next"
      @current-change="handlePage"
    />
  </ElDialog>
</template>
