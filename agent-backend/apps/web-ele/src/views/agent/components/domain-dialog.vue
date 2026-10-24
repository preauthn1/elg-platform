<script lang="ts" setup>
import type { AgentDomainRow } from '#/api';

import { ref } from 'vue';

import { ElDialog, ElTable, ElTableColumn, ElTag } from 'element-plus';

import { getAgentDomainListApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';

defineOptions({ name: 'AgentDomainDialog' });

const PER_PAGE = 20;

const visible = ref(false);
const loading = ref(false);
const agentId = ref(0);
const list = ref<AgentDomainRow[]>([]);
const page = ref(1);
const total = ref(0);

async function load() {
  loading.value = true;
  try {
    const res = await getAgentDomainListApi({
      agent_id: agentId.value,
      page: page.value,
      per_page: PER_PAGE,
    });
    list.value = res.code === 1000 ? (res.data ?? []) : [];
    total.value = res.pagination?.total ?? 0;
  } finally {
    loading.value = false;
  }
}

function open(id: number) {
  agentId.value = id;
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
  <ElDialog v-model="visible" :title="tl('domain')" width="600" align-center>
    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn :label="tl('domain')" prop="domain" min-width="200" />
      <ElTableColumn
        :label="tl('domain_noted')"
        prop="domain_noted"
        min-width="160"
      />
      <ElTableColumn :label="tl('enable_status')" align="center" width="110">
        <template #default="{ row }">
          <ElTag :type="row.status === 1 ? 'success' : 'danger'" size="small">
            {{ row.status === 1 ? tl('enable') : tl('disable') }}
          </ElTag>
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
