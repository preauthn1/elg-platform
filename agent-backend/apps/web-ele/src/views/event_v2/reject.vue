<script lang="ts" setup>
import type { EventV2RejectRow } from '#/api';

import { ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElMessage,
  ElMessageBox,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  eventV2AllowJoinApi,
  getEventV2RejectDetailApi,
  getEventV2RejectListApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { fromApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import EventV2SearchForm from './components/event-v2-search-form.vue';

defineOptions({ name: 'EventV2Reject' });

const PER_PAGE = 10;

const STATUS_OPTIONS = [
  { label: tl('reject_join'), value: 1 },
  { label: tl('allow_join'), value: 2 },
  { label: tl('joined'), value: 3 },
];

const STATUS_COLOR: Record<number, string> = {
  1: 'text-red-500',
  2: 'text-green-600',
  3: 'text-blue-500',
};

const loading = ref(false);
const list = ref<EventV2RejectRow[]>([]);
const total = ref(0);
const page = ref(1);
let currentFilters: Record<string, any> = {};

const detailVisible = ref(false);
const detailLoading = ref(false);
const detailRows = ref<any[]>([]);

function statusText(s?: number) {
  const map: Record<number, string> = {
    1: tl('reject_join'),
    2: tl('allow_join'),
    3: tl('joined'),
  };
  return map[Number(s)] ?? String(s ?? '');
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getEventV2RejectListApi({
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

async function showDetail(row: EventV2RejectRow) {
  detailVisible.value = true;
  detailLoading.value = true;
  detailRows.value = [];
  try {
    const res = await getEventV2RejectDetailApi(row.id ?? 0);
    if (Number(res.code) === 200) detailRows.value = res.data ?? [];
  } finally {
    detailLoading.value = false;
  }
}

async function allowJoin(row: EventV2RejectRow) {
  try {
    await ElMessageBox.confirm(tl('allow_join'), tl('allow_join'));
  } catch {
    return;
  }
  const res = await eventV2AllowJoinApi(row.id ?? 0);
  if (Number(res.code) === 200) {
    ElMessage.success(res.message);
    loadData();
  } else {
    ElMessage.error(res.message);
  }
}
</script>

<template>
  <Page
    :title="`${tl('event_exclusive_offers')} > ${tl('menu_event_v2_reject')}`"
  >
    <EventV2SearchForm :status-options="STATUS_OPTIONS" @search="onSearch" />

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="`${tl('event')}ID`"
          align="center"
          prop="event_v2_detail_id"
          width="80"
        />
        <ElTableColumn
          :label="`${tl('join')}ID`"
          align="center"
          prop="event_v2_user_join_id"
          width="80"
        />
        <ElTableColumn
          :label="tl('event_name')"
          align="center"
          prop="name"
          min-width="130"
        />
        <ElTableColumn
          :label="tl('agent_name')"
          align="center"
          prop="agent_username"
          min-width="100"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_name')"
          align="center"
          min-width="100"
        >
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('reject_reason')"
          align="center"
          prop="reject_reason"
          min-width="130"
        />
        <ElTableColumn label="IP" align="center" prop="ip" min-width="110" />
        <ElTableColumn
          :formatter="(row: any) => fromApiTime(row.reject_time)"
          :label="tl('reject_time')"
          align="center"
          width="150"
        />
        <ElTableColumn
          :label="tl('allow_time')"
          align="center"
          prop="allow_time"
          width="150"
        />
        <ElTableColumn :label="tl('status')" align="center" width="100">
          <template #default="{ row }">
            <b :class="STATUS_COLOR[Number(row.status)] ?? 'text-gray-500'">
              {{ statusText(row.status) }}
            </b>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('operate')" align="center" width="100">
          <template #default="{ row }">
            <ElButton
              v-if="Number(row.status) === 1"
              link
              type="primary"
              @click="allowJoin(row as EventV2RejectRow)"
            >
              {{ tl('allow_join') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('detail_record')"
          align="center"
          width="100"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              link
              type="primary"
              @click="showDetail(row as EventV2RejectRow)"
            >
              {{ tl('detail_record') }}
            </ElButton>
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

    <ElDialog
      v-model="detailVisible"
      :title="tl('detail_record')"
      align-center
      width="700"
    >
      <ElTable v-loading="detailLoading" :data="detailRows" border size="small">
        <ElTableColumn
          :formatter="(row: any) => fromApiTime(row.reject_time)"
          :label="tl('reject_time')"
          align="center"
          width="170"
        />
        <ElTableColumn
          :label="tl('reject_reason')"
          align="center"
          prop="reject_reason"
          min-width="160"
        />
        <ElTableColumn label="IP" align="center" prop="ip" min-width="120" />
        <template #empty>{{ tl('no_data') }}</template>
      </ElTable>
    </ElDialog>
  </Page>
</template>
