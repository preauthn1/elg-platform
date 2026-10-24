<script lang="ts" setup>
import type { StatusLogReportParams, StatusLogReportRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElForm,
  ElFormItem,
  ElInput,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getMemberStatusLogReportApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'MemberStatusLogList' });

const PER_PAGE = 30;

/** 狀態下拉：全部 / 正常(1) / 未認證(0) / 鎖定(2)，順序比照舊版 */
const STATUS_OPTIONS = [1, 0, 2];
/** 理由下拉：後台變更等，比照舊版 member_status_log_type.1~5 */
const REASON_OPTIONS = [1, 2, 3, 4, 5];
/**
 * 「全部」選項用 -1 佔位，而非空字串：ElSelect 對空字串值視同未選取，
 * 選了也只會顯示 placeholder，等送出查詢時再還原成空字串給後端（後端用空字串代表不篩選）。
 */
const ALL_VALUE = '-1';

const filters = reactive({
  after_status: ALL_VALUE as number | string,
  agent_username: '',
  before_status: ALL_VALUE as number | string,
  fuzzy_query: false,
  member_username: '',
  operator_username: '',
  reason: ALL_VALUE as number | string,
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
});

const loading = ref(false);
const list = ref<StatusLogReportRow[]>([]);
const total = ref(0);
const page = ref(1);

function statusColor(v: number | string) {
  return Number(v) === 1 ? 'text-green-600' : 'text-red-500';
}

function buildParams(): StatusLogReportParams {
  const [start, end] = filters.time_range ?? [];
  return {
    after_status:
      filters.after_status === ALL_VALUE ? '' : filters.after_status,
    agent_username: filters.agent_username,
    before_status:
      filters.before_status === ALL_VALUE ? '' : filters.before_status,
    end_time: toApiTime(end),
    fuzzy_query: filters.fuzzy_query ? 1 : 0,
    member_username: filters.member_username,
    operator_username: filters.operator_username,
    page: page.value,
    per_page: PER_PAGE,
    reason: filters.reason === ALL_VALUE ? '' : filters.reason,
    start_time: toApiTime(start),
  };
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getMemberStatusLogReportApi(buildParams());
    list.value = res.data ?? [];
    total.value = res.pagination?.total ?? 0;
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadData();
}

function handleClear() {
  filters.agent_username = '';
  filters.member_username = '';
  filters.operator_username = '';
  filters.fuzzy_query = false;
  filters.before_status = ALL_VALUE;
  filters.after_status = ALL_VALUE;
  filters.reason = ALL_VALUE;
  filters.time_range = [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ];
  handleSearch();
}

function handlePageChange(p: number) {
  page.value = p;
  loadData();
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('member_lower_management')} > ${tl('menu_member_status_log')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.agent_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('member_account')">
            <div class="w-full">
              <ElInput v-model="filters.member_username" clearable />
              <ElCheckbox v-model="filters.fuzzy_query" class="mt-1">
                {{ tl('fuzzy_query') }}
              </ElCheckbox>
            </div>
          </ElFormItem>
          <ElFormItem :label="tl('operator')">
            <ElInput v-model="filters.operator_username" clearable />
          </ElFormItem>
          <ElFormItem :label="`${tl('before')}${tl('status')}`">
            <ElSelect v-model="filters.before_status" class="!w-full">
              <ElOption :label="tl('all')" :value="ALL_VALUE" />
              <ElOption
                v-for="s in STATUS_OPTIONS"
                :key="s"
                :label="tl(`member_status.${s}`)"
                :value="String(s)"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="`${tl('after')}${tl('status')}`">
            <ElSelect v-model="filters.after_status" class="!w-full">
              <ElOption :label="tl('all')" :value="ALL_VALUE" />
              <ElOption
                v-for="s in STATUS_OPTIONS"
                :key="s"
                :label="tl(`member_status.${s}`)"
                :value="String(s)"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('reason')">
            <ElSelect v-model="filters.reason" class="!w-full">
              <ElOption :label="tl('all')" :value="ALL_VALUE" />
              <ElOption
                v-for="r in REASON_OPTIONS"
                :key="r"
                :label="tl(`member_status_log_type.${r}`)"
                :value="String(r)"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('change_date')" class="md:col-span-3">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :formatter="(row: StatusLogReportRow) => fromApiTime(row.created_at)"
          :label="tl('change_date')"
          min-width="150"
        />
        <ElTableColumn
          :label="tl('agent_account')"
          min-width="120"
          prop="agent_username"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="120">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('before')}${tl('status')}`"
          min-width="100"
        >
          <template #default="{ row }">
            <span :class="statusColor(row.before_status)">
              {{ tl(`member_status.${row.before_status}`) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="`${tl('after')}${tl('status')}`" min-width="100">
          <template #default="{ row }">
            <span :class="statusColor(row.after_status)">
              {{ tl(`member_status.${row.after_status}`) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('reason')" min-width="160">
          <template #default="{ row }">
            {{ tl(`member_status_log_type.${row.type}`) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('operator')"
          min-width="120"
          prop="operator_username"
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
        @current-change="handlePageChange"
      />
    </ElCard>
  </Page>
</template>
