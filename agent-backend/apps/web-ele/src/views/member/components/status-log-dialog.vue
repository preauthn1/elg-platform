<script lang="ts" setup>
import type { StatusLogRow } from '#/api';

import { ref } from 'vue';

import { ElDialog, ElTable, ElTableColumn, ElTag } from 'element-plus';

import { getMemberStatusLogApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';

defineOptions({ name: 'MemberStatusLogDialog' });

const PER_PAGE = 8;

const visible = ref(false);
const loading = ref(false);
const username = ref('');
const memberId = ref(0);
const list = ref<StatusLogRow[]>([]);
const page = ref(1);
const total = ref(0);

async function load() {
  loading.value = true;
  try {
    const res = await getMemberStatusLogApi({
      member_id: memberId.value,
      page: page.value,
      per_page: PER_PAGE,
    });
    list.value = res.code === 1000 ? (res.data ?? []) : [];
    total.value = res.pagination?.total ?? 0;
  } finally {
    loading.value = false;
  }
}

function open(id: number, name: string) {
  memberId.value = id;
  username.value = name;
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
  <ElDialog v-model="visible" :title="username" width="720" align-center>
    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn
        :label="tl('operating_time')"
        prop="created_at"
        width="170"
      />
      <ElTableColumn :label="`${tl('before')}${tl('status')}`" width="100">
        <template #default="{ row }">
          <ElTag
            :type="row.before_status === 1 ? 'success' : 'danger'"
            size="small"
          >
            {{ row.before_status === 1 ? tl('enable') : tl('disable') }}
          </ElTag>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('after')" width="100">
        <template #default="{ row }">
          <ElTag
            :type="row.after_status === 1 ? 'success' : 'danger'"
            size="small"
          >
            {{ row.after_status === 1 ? tl('enable') : tl('disable') }}
          </ElTag>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('memo')" min-width="180">
        <template #default="{ row }">
          <span class="whitespace-pre-line break-words">{{ row.remark }}</span>
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('operator')"
        prop="operator_username"
        width="140"
      >
        <template #default="{ row }">
          <AgentAccount :username="row.operator_username" />
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
