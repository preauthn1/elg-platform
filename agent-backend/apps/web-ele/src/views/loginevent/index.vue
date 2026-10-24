<script lang="ts" setup>
import type { LoginEventRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getLoginEventListApi,
  getVendorListApi,
  toggleLoginEventStatusApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';

import LoginEventEditDialog from './components/loginevent-edit-dialog.vue';

defineOptions({ name: 'LoginEventIndex' });

const editRef = ref<InstanceType<typeof LoginEventEditDialog>>();
const userStore = useUserStore();
const hostId = computed(() =>
  Number((userStore.userInfo as any)?.host_id ?? 0),
);
const PER_PAGE = 15;

const filters = reactive({ status: 'all' as number | string, title: '' });
const loading = ref(false);
const rows = ref<LoginEventRow[]>([]);
/** 試玩遊戲商 id → 名稱 */
const vendorMap = ref<Record<string, string>>({});

const {
  handlePageChange,
  handleSearch: handleQuickSearch,
  handleSortChange,
  keyword,
  page,
  pagedRows,
  total,
} = useClientTable(rows, {
  perPage: PER_PAGE,
  searchFields: (r) => [
    r.id,
    r.title,
    r.agent_username,
    r.start_time,
    r.end_time,
  ],
});

function vendorNames(vendorId?: string) {
  if (!vendorId) return '';
  return vendorId
    .split(',')
    .map((id) => vendorMap.value[id.trim()])
    .filter(Boolean)
    .join(', ');
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getLoginEventListApi({
      status: filters.status,
      title: filters.title,
    });
    rows.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadData();
}

function handleReset() {
  filters.title = '';
  filters.status = 'all';
  handleSearch();
}

async function toggleStatus(row: LoginEventRow) {
  const next = Number(row.status) === 1 ? 0 : 1;
  const res = await toggleLoginEventStatusApi({ id: row.id, status: next });
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    loadData();
  } else {
    ElMessage.error(res.message || tl('failure'));
  }
}

function goEdit(row?: LoginEventRow) {
  editRef.value?.open(row ? row.id : '');
}

onMounted(() => {
  loadData();
  getVendorListApi()
    .then((list) => {
      const map: Record<string, string> = {};
      for (const v of list ?? []) map[String(v.id)] = v.name;
      vendorMap.value = map;
    })
    .catch(() => {});
});
</script>

<template>
  <Page
    :title="`${tl('event_exclusive_offers')} > ${tl('event_login_management')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <div class="mb-3">
        <ElButton type="success" @click="goEdit()">
          {{ tl('event_create') }}
        </ElButton>
      </div>
      <ElForm inline @submit.prevent="handleSearch">
        <ElFormItem :label="tl('event_name')">
          <ElInput v-model="filters.title" clearable />
        </ElFormItem>
        <ElFormItem :label="tl('status')">
          <ElSelect v-model="filters.status" class="!w-32">
            <ElOption :label="tl('all')" value="all" />
            <ElOption :label="tl('enable')" :value="1" />
            <ElOption :label="tl('disable')" :value="0" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
          <ElButton @click="handleReset">{{ tl('clear') }}</ElButton>
        </ElFormItem>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-3">
        <ElInput
          v-model="keyword"
          :placeholder="tl('search')"
          class="!w-56"
          clearable
          @input="handleQuickSearch"
        />
      </div>
      <ElTable
        v-loading="loading"
        :data="pagedRows"
        border
        size="small"
        @sort-change="handleSortChange"
      >
        <ElTableColumn
          :label="tl('event_name')"
          min-width="160"
          prop="title"
          sortable="custom"
        >
          <template #default="{ row }">
            <div>{{ (row as LoginEventRow).title }}</div>
            <div class="text-muted-foreground text-xs">
              {{ tl('id') }}{{ (row as LoginEventRow).id }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('label_start_time')"
          prop="start_time"
          min-width="150"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('label_end_time')"
          prop="end_time"
          min-width="150"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('agent_name')"
          min-width="120"
          prop="agent_username"
          sortable="custom"
        >
          <template #default="{ row }">
            <span class="text-green-600">
              <AgentAccount :username="(row as LoginEventRow).agent_username" />
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('login_event_type')"
          min-width="160"
          prop="type"
          sortable="custom"
        >
          <template #default="{ row }">
            {{ tl(`login_event_type.${(row as LoginEventRow).type}`) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('not_withdraw_record')"
          prop="how_days"
          align="right"
          min-width="100"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('trial_amount')"
          prop="point"
          align="right"
          min-width="100"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('unlock_amount')"
          prop="times"
          align="right"
          min-width="100"
          sortable="custom"
        />
        <ElTableColumn :label="tl('trial_vendor')" min-width="140">
          <template #default="{ row }">
            {{ vendorNames((row as LoginEventRow).vendor_id) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('repeat_participation')"
          align="center"
          min-width="100"
          prop="repeat_hour"
          sortable="custom"
        >
          <template #default="{ row }">
            <span
              :class="
                Number((row as LoginEventRow).repeat_hour) === 1
                  ? 'text-green-600'
                  : 'text-red-500'
              "
            >
              {{ Number((row as LoginEventRow).repeat_hour) === 1 ? '✓' : '✗' }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('number_of_participants')}/${tl('the_number_of_participants')}`"
          align="center"
          min-width="130"
        >
          <template #default="{ row }">
            <span
              v-if="Number((row as LoginEventRow).number_of_people) === 0"
              class="text-green-600"
            >
              {{ tl('unlimited') }}
            </span>
            <span
              v-else
              :class="
                Number((row as LoginEventRow).number_of_participants) >=
                Number((row as LoginEventRow).number_of_people)
                  ? 'text-red-500'
                  : 'text-green-600'
              "
            >
              {{ (row as LoginEventRow).number_of_people }} /
              {{ (row as LoginEventRow).number_of_participants }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('status')"
          align="center"
          min-width="90"
          prop="status"
          sortable="custom"
        >
          <template #default="{ row }">
            <span
              :class="
                Number((row as LoginEventRow).status) === 1
                  ? 'text-green-600'
                  : 'text-red-500'
              "
            >
              {{
                Number((row as LoginEventRow).status) === 1
                  ? tl('enable')
                  : tl('disable')
              }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('features')"
          align="center"
          width="160"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              v-if="hostId === Number((row as LoginEventRow).agent_id)"
              size="small"
              type="primary"
              @click="goEdit(row as LoginEventRow)"
            >
              {{ tl('edit') }}
            </ElButton>
            <ElButton
              :type="
                Number((row as LoginEventRow).status) === 1
                  ? 'danger'
                  : 'success'
              "
              size="small"
              @click="toggleStatus(row as LoginEventRow)"
            >
              {{
                Number((row as LoginEventRow).status) === 1
                  ? tl('disable')
                  : tl('enable')
              }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>

    <LoginEventEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
