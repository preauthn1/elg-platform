<script lang="ts" setup>
import type { AgentStatusLogRow } from '#/api';

import { ref } from 'vue';

import { ElDialog, ElTable, ElTableColumn, ElTag } from 'element-plus';

import { getAgentStatusLogApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';

defineOptions({ name: 'AgentStatusLogDialog' });

const PER_PAGE = 8;

const visible = ref(false);
const loading = ref(false);
const username = ref('');
const agentId = ref(0);
const list = ref<AgentStatusLogRow[]>([]);
const page = ref(1);
const total = ref(0);

async function load() {
  loading.value = true;
  try {
    const res = await getAgentStatusLogApi({
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

function open(id: number, name: string) {
  agentId.value = id;
  username.value = name;
  page.value = 1;
  visible.value = true;
  load();
}

function handlePage(p: number) {
  page.value = p;
  load();
}

const hasValue = (v: unknown) => v !== null && v !== undefined;

/** 當期操作：優先看限制登入維度有無變更，其次看狀態維度 */
function operationText(row: AgentStatusLogRow) {
  if (
    hasValue(row.after_sys_lock) &&
    hasValue(row.before_sys_lock) &&
    Number(row.after_sys_lock) !== Number(row.before_sys_lock)
  ) {
    return Number(row.after_sys_lock) === 1
      ? tl('restrict_login')
      : tl('remove_restrict_login');
  }
  if (Number(row.after_status) !== Number(row.before_status)) {
    return Number(row.after_status) === 1 ? tl('enable') : tl('disable');
  }
  return '-';
}

/** 變更後的限制登入值；無此欄位的舊資料視為未限制 */
function finalSysLock(row: AgentStatusLogRow) {
  return hasValue(row.after_sys_lock) ? Number(row.after_sys_lock) : 0;
}

defineExpose({ open });
</script>

<template>
  <ElDialog v-model="visible" :title="username" width="820" align-center>
    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn
        :label="tl('operating_time')"
        prop="created_at"
        width="170"
      />
      <ElTableColumn :label="tl('action')" width="110">
        <template #default="{ row }">
          {{ operationText(row as AgentStatusLogRow) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('status')" width="90">
        <template #default="{ row }">
          <ElTag
            :type="Number(row.after_status) === 0 ? 'danger' : 'success'"
            size="small"
          >
            {{ Number(row.after_status) === 0 ? tl('disable') : tl('enable') }}
          </ElTag>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('restrict_login')" width="100">
        <template #default="{ row }">
          <ElTag
            :type="
              finalSysLock(row as AgentStatusLogRow) ? 'danger' : 'success'
            "
            size="small"
          >
            {{ finalSysLock(row as AgentStatusLogRow) ? tl('yes') : tl('no') }}
          </ElTag>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('memo')" min-width="180">
        <template #default="{ row }">
          <span class="whitespace-pre-line break-words">{{ row.remark }}</span>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('operator')" width="120">
        <template #default="{ row }">
          <AgentAccount
            v-if="row.operator_username"
            :username="row.operator_username"
          />
          <span v-else>{{ tl('system') }}</span>
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
