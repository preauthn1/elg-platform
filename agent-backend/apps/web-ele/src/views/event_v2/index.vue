<script lang="ts" setup>
import type { EventV2ListRow, EventV2WithdrawType } from '#/api';

import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getEventV2AdminListApi, searchAgentOptionsApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';

import EventV2EditDialog from './components/event-v2-edit-dialog.vue';

defineOptions({ name: 'EventV2Index' });

const PER_PAGE = 10;

const router = useRouter();
const editRef = ref<InstanceType<typeof EventV2EditDialog>>();

const agentUsername = ref('');
const agentOptions = ref<Array<{ id: number; username: string }>>([]);
const agentLoading = ref(false);
// 領取去向篩選：'' 全部、1 主錢包、2 積分（target/tickets#4231）
const withdrawType = ref<'' | EventV2WithdrawType>('');

function withdrawTypeText(t?: number) {
  return Number(t) === 2 ? tl('withdraw_type_2') : tl('withdraw_type_1');
}

const loading = ref(false);
const allRows = ref<EventV2ListRow[]>([]);

const {
  handlePageChange,
  handleSearch,
  handleSortChange,
  keyword,
  page,
  pagedRows,
  total,
} = useClientTable(allRows, {
  perPage: PER_PAGE,
  searchFields: (r) => [
    r.event_detail_id,
    r.name,
    r.event_type_name,
    r.agent_username,
    r.start_time,
    r.end_time,
  ],
});

async function searchAgent(query: string) {
  if (!query) return;
  agentLoading.value = true;
  try {
    const res = await searchAgentOptionsApi(query);
    if (Number(res.code) === 1000) agentOptions.value = res.data ?? [];
  } finally {
    agentLoading.value = false;
  }
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getEventV2AdminListApi({
      agent_username: agentUsername.value,
      page: 1,
      per_page: 1000,
      ...(withdrawType.value === ''
        ? {}
        : { withdraw_type: withdrawType.value }),
    });
    if (Number(res.code) === 200) {
      allRows.value = res.data?.items ?? [];
    } else {
      allRows.value = [];
      ElMessage.warning(res.message);
    }
    page.value = 1;
  } finally {
    loading.value = false;
  }
}

onMounted(loadData);
</script>

<template>
  <Page :title="`${tl('event_exclusive_offers')} > ${tl('menu_event_v2')}`">
    <ElCard class="mb-3" shadow="never">
      <div class="mb-3 flex gap-2">
        <ElButton type="success" @click="editRef?.open()">
          {{ tl('event_create') }}
        </ElButton>
        <ElButton type="info" @click="router.push('/event_v2/join')">
          {{ tl('join_record') }}
        </ElButton>
        <ElButton type="info" @click="router.push('/event_v2/recycle')">
          {{ tl('recycle_record') }}
        </ElButton>
      </div>
      <div class="flex flex-wrap items-end gap-3">
        <ElFormItem :label="tl('agent_account')" class="!mb-0">
          <ElSelect
            v-model="agentUsername"
            :debounce="500"
            :loading="agentLoading"
            :remote-method="searchAgent"
            class="!w-60"
            clearable
            filterable
            remote
          >
            <ElOption
              v-for="a in agentOptions"
              :key="a.id"
              :label="a.username"
              :value="a.username"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('withdraw_type')" class="!mb-0">
          <ElSelect v-model="withdrawType" class="!w-40" clearable>
            <ElOption :label="tl('please_choose')" value="" />
            <ElOption :label="tl('withdraw_type_1')" :value="1" />
            <ElOption :label="tl('withdraw_type_2')" :value="2" />
          </ElSelect>
        </ElFormItem>
        <ElButton :loading="loading" type="primary" @click="loadData">
          {{ tl('search') }}
        </ElButton>
      </div>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-3 flex justify-end">
        <ElInput
          v-model="keyword"
          :placeholder="tl('search')"
          class="!w-56"
          clearable
          @input="handleSearch"
        />
      </div>
      <ElTable
        v-loading="loading"
        :data="pagedRows"
        border
        size="small"
        stripe
        @sort-change="handleSortChange"
      >
        <ElTableColumn
          :label="`${tl('event')}ID`"
          align="center"
          prop="event_detail_id"
          sortable="custom"
          width="110"
        />
        <ElTableColumn
          :label="tl('event_name')"
          align="center"
          prop="name"
          min-width="180"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('event_type')"
          align="center"
          prop="event_type_name"
          min-width="120"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('agent_account')"
          align="center"
          prop="agent_username"
          min-width="120"
          sortable="custom"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('label_start_time')"
          align="center"
          prop="start_time"
          sortable="custom"
          width="160"
        >
          <template #default="{ row }">
            <span class="font-bold text-sky-600">{{ row.start_time }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('label_end_time')"
          align="center"
          prop="end_time"
          sortable="custom"
          width="160"
        >
          <template #default="{ row }">
            <span class="font-bold text-sky-600">{{ row.end_time }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('withdraw_type')"
          align="center"
          prop="withdraw_type"
          sortable="custom"
          width="110"
        >
          <template #default="{ row }">
            <b
              :class="
                Number(row.withdraw_type) === 2
                  ? 'text-amber-600'
                  : 'text-gray-600'
              "
            >
              {{ withdrawTypeText(row.withdraw_type) }}
            </b>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('status')"
          align="center"
          prop="status"
          sortable="custom"
          width="110"
        >
          <template #default="{ row }">
            <b
              :class="
                Number(row.status) === 1 ? 'text-green-600' : 'text-red-500'
              "
            >
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </b>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('features')"
          align="center"
          width="90"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              link
              type="primary"
              @click="editRef?.open(row.event_detail_id)"
            >
              {{ tl('edit') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_data') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>

    <EventV2EditDialog ref="editRef" @saved="loadData" />
  </Page>
</template>
