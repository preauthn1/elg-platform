<script lang="ts" setup>
import type { EventV2RecycleRow } from '#/api';

import { ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getEventV2RecycleListApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { fromApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import EventV2SearchForm from './components/event-v2-search-form.vue';

defineOptions({ name: 'EventV2Recycle' });

const PER_PAGE = 10;

const STATUS_OPTIONS = [
  { label: tl('system_recycle'), value: 1 },
  { label: tl('complete'), value: 2 },
  { label: tl('give_up'), value: 3 },
  { label: tl('system_reset'), value: 4 },
  { label: tl('operater_quit'), value: 5 },
  { label: tl('to_vendor_failed_schedule_recycle'), value: 6 },
];

const STATUS_COLOR: Record<number, string> = {
  2: 'text-green-600',
  3: 'text-red-500',
  4: 'text-blue-500',
  5: 'text-purple-600',
};

const loading = ref(false);
const list = ref<EventV2RecycleRow[]>([]);
const total = ref(0);
const page = ref(1);
let currentFilters: Record<string, any> = {};

function statusText(s?: number) {
  const map: Record<number, string> = {
    1: tl('system_recycle'),
    2: tl('complete'),
    3: tl('give_up'),
    4: tl('system_reset'),
    5: tl('operater_quit'),
    6: tl('to_vendor_failed_schedule_recycle'),
  };
  return map[Number(s)] ?? String(s ?? '');
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getEventV2RecycleListApi({
      ...(currentFilters as any),
      page: page.value,
      per_page: PER_PAGE,
    });
    if (Number(res.code) === 200) {
      list.value = res.data?.items ?? [];
      total.value = res.data?.pagination?.total ?? 0;
    } else {
      list.value = [];
      total.value = 0;
      ElMessage.warning(res.message);
    }
  } finally {
    loading.value = false;
  }
}

function onSearch(filters: Record<string, any>) {
  currentFilters = filters;
  page.value = 1;
  loadData();
}

function onPage(p: number) {
  page.value = p;
  loadData();
}

const exporting = ref(false);
/** 匯出 Excel：欄位（8 欄） */
async function handleExport() {
  exporting.value = true;
  try {
    const params = { ...(currentFilters as any), page: 1, per_page: 100_000 };
    const res = await getEventV2RecycleListApi(params);
    const rows = Number(res.code) === 200 ? (res.data?.items ?? []) : [];
    await exportExcel({
      audit: { conditions: params },
      columns: [
        { title: tl('event_name'), width: 20 },
        { title: tl('agent_name'), width: 20 },
        { title: tl('member_name'), width: 20 },
        { title: tl('final_balance'), width: 20 },
        { title: `${tl('complete')}${tl('disburse_award')}`, width: 20 },
        { title: tl('recycle_amount'), width: 20 },
        { title: tl('recycle_time'), width: 20 },
        { title: tl('status'), width: 20 },
      ],
      rows: rows.map((r) => [
        r.name,
        r.agent_username,
        r.member_username,
        r.final_balance,
        r.withdraw,
        r.recycle_amount,
        fromApiTime(r.created_at),
        statusText(r.status),
      ]),
      title: tl('recycle_record'),
    });
  } finally {
    exporting.value = false;
  }
}
</script>

<template>
  <Page
    :title="`${tl('event_exclusive_offers')} > ${tl('menu_event_v2')} > ${tl('recycle_record')}`"
  >
    <EventV2SearchForm :status-options="STATUS_OPTIONS" @search="onSearch" />

    <ElCard shadow="never">
      <div class="mb-2 flex justify-end">
        <ElButton :loading="exporting" type="success" @click="handleExport">
          {{ tl('excel') }}
        </ElButton>
      </div>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('event_name')"
          align="center"
          prop="name"
          min-width="140"
        />
        <ElTableColumn
          :label="tl('agent_name')"
          align="center"
          prop="agent_username"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_name')"
          align="center"
          min-width="110"
        >
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('final_balance')"
          align="center"
          prop="final_balance"
          width="110"
        />
        <ElTableColumn
          :label="`${tl('complete')}${tl('disburse_award')}`"
          align="center"
          prop="withdraw"
          width="120"
        />
        <ElTableColumn
          :label="tl('recycle_amount')"
          align="center"
          prop="recycle_amount"
          width="110"
        />
        <ElTableColumn
          :formatter="(row: any) => fromApiTime(row.created_at)"
          :label="tl('recycle_time')"
          align="center"
          width="160"
        />
        <ElTableColumn :label="tl('status')" align="center" width="130">
          <template #default="{ row }">
            <b :class="STATUS_COLOR[Number(row.status)] ?? 'text-gray-500'">
              {{ statusText(row.status) }}
            </b>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_data') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="onPage"
      />
    </ElCard>
  </Page>
</template>
