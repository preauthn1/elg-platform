<script lang="ts" setup>
import type {
  EventProgressiveJackpotJoinUserParams,
  EventProgressiveJackpotJoinUserRow,
} from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElProgress,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import {
  getEventProgressiveJackpotJoinUserListApi,
  getEventProgressiveJackpotViewApi,
  unlockEventProgressiveJackpotJoinUserApi,
} from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands, buildPlainAmounts } from '#/utils/format';
import { nowInUserTz } from '#/utils/timezone-shift';

import DepositLogDialog from './components/deposit-log-dialog.vue';
import EventLogDialog from './components/event-log-dialog.vue';
import MemberDetailDialog from './components/member-detail-dialog.vue';
import {
  JOIN_USER_STATUS,
  joinUserStatusLabel,
  joinUserStatusType,
  tierLabel,
} from './status-map';

defineOptions({ name: 'EventProgressiveJackpotRecord' });

const PER_PAGE = 20;
/** 頁面口徑固定 GMT+8，日期原樣送出不平移 */
const FIXED_OFFSET = 8;

const filters = reactive({
  date_range: [
    nowInUserTz(FIXED_OFFSET).subtract(1, 'month').format('YYYY-MM-DD'),
    nowInUserTz(FIXED_OFFSET).format('YYYY-MM-DD'),
  ] as string[],
  level: '' as number | string,
  member_username: '',
  status: '' as number | string,
});

const loading = ref(false);
const exporting = ref(false);
const unlocking = ref<number | string>('');
const page = ref(1);
const total = ref(0);
const list = ref<EventProgressiveJackpotJoinUserRow[]>([]);
const tierOptions = ref<{ label: string; level: number }[]>([]);

const eventLogDialog = ref<InstanceType<typeof EventLogDialog>>();
const depositLogDialog = ref<InstanceType<typeof DepositLogDialog>>();
const memberDetailDialog = ref<InstanceType<typeof MemberDetailDialog>>();

const statusOptions = computed(() =>
  Object.keys(JOIN_USER_STATUS).map((k) => ({
    label: joinUserStatusLabel(k),
    value: Number(k),
  })),
);

/** 「全部」的 level/status 是空字串，帶出去會被後端當成 0，所以整個欄位不帶 */
function buildParams(
  p: number,
  perPage: number,
): EventProgressiveJackpotJoinUserParams {
  const [start, end] = filters.date_range ?? [];
  const params: EventProgressiveJackpotJoinUserParams = {
    end_date: end ?? '',
    member_username: filters.member_username,
    page: p,
    per_page: perPage,
    start_date: start ?? '',
  };
  if (filters.level !== '') params.level = filters.level;
  if (filters.status !== '') params.status = filters.status;
  return params;
}

function unlockProgress(row: EventProgressiveJackpotJoinUserRow) {
  const reward = Number(row.reward ?? 0);
  if (reward <= 0) return 0;
  return Math.min(
    100,
    Math.round((Number(row.point ?? 0) / reward) * 100 * 10) / 10,
  );
}

/** 舊版只顯示到日，沒有到期時間就顯示破折號 */
function endTimeText(row: EventProgressiveJackpotJoinUserRow) {
  return row.expire_time ? String(row.expire_time).slice(5, 10) : '—';
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getEventProgressiveJackpotJoinUserListApi(
      buildParams(page.value, PER_PAGE),
    );
    if (Number(res.code) === 1000) {
      list.value = res.data ?? [];
      total.value = res.pagination?.total ?? 0;
    } else {
      list.value = [];
      total.value = 0;
      ElMessage.error(res.message ?? '');
    }
  } finally {
    loading.value = false;
  }
}

function search() {
  page.value = 1;
  loadData();
}

function handlePageChange(p: number) {
  page.value = p;
  loadData();
}

function resetFilters() {
  filters.member_username = '';
  filters.level = '';
  filters.status = '';
  filters.date_range = [
    nowInUserTz(FIXED_OFFSET).subtract(1, 'month').format('YYYY-MM-DD'),
    nowInUserTz(FIXED_OFFSET).format('YYYY-MM-DD'),
  ];
  search();
}

async function handleUnlock(row: EventProgressiveJackpotJoinUserRow) {
  try {
    await ElMessageBox.confirm(
      tl('event_progressive_jackpot_unlock_confirm'),
      '',
      { type: 'warning' },
    );
  } catch {
    return;
  }
  unlocking.value = row.id;
  try {
    const res = await unlockEventProgressiveJackpotJoinUserApi(row.id);
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('save_success'));
      await loadData();
    } else {
      ElMessage.error(
        String(res.code) === '2002'
          ? String(res.data ?? '')
          : (res.message ?? ''),
      );
    }
  } finally {
    unlocking.value = '';
  }
}

async function handleExport() {
  exporting.value = true;
  try {
    const params = buildParams(1, 100_000);
    const res = await getEventProgressiveJackpotJoinUserListApi(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    const headers = [
      tl('member_account'),
      tl('level'),
      tl('event_progressive_jackpot_reward_amount'),
      tl('unlocked'),
      tl('received'),
      tl('unlock_progress'),
      tl('status'),
      tl('label_end_time'),
    ];
    const body = buildPlainAmounts(() =>
      rows.map((r) => [
        r.member_username ?? '',
        tierLabel(r.level),
        amountThousands(r.reward, 0),
        amountThousands(r.point, 0),
        amountThousands(r.receive, 0),
        `${unlockProgress(r)}%`,
        joinUserStatusLabel(r.status),
        r.expire_time ?? '',
      ]),
    );
    const [start, end] = filters.date_range ?? [];
    await exportExcel(
      `event_progressive_jackpot_record_${Date.now()}`,
      headers,
      body,
      {
        audit: { conditions: { ...params } },
        dateRange: [start ?? '', end ?? ''],
        title: `${tl('menu_event_progressive_jackpot')}${tl('menu_event_progressive_jackpot_record')}`,
        tzOffset: FIXED_OFFSET,
      },
    );
  } finally {
    exporting.value = false;
  }
}

const title = computed(
  () =>
    `${tl('menu_event')} > ${tl('menu_event_progressive_jackpot')} > ${tl('menu_event_progressive_jackpot_record')}`,
);

onMounted(async () => {
  const res = await getEventProgressiveJackpotViewApi().catch(() => null);
  tierOptions.value = (res?.data?.tier ?? []).map((row) => ({
    label: tierLabel(row.level),
    level: Number(row.level),
  }));
  await loadData();
});
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('member_account')">
            <ElInput
              v-model="filters.member_username"
              :placeholder="`${tl('example')}：U100234`"
              clearable
              @keyup.enter="search"
            />
          </ElFormItem>
          <ElFormItem :label="tl('level')">
            <ElSelect v-model="filters.level" class="!w-full" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption
                v-for="t in tierOptions"
                :key="t.level"
                :label="t.label"
                :value="t.level"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="!w-full" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption
                v-for="s in statusOptions"
                :key="s.value"
                :label="s.label"
                :value="s.value"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('label_start_time')">
            <DateRangeFields
              v-model="filters.date_range"
              :fixed-offset="FIXED_OFFSET"
            />
          </ElFormItem>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <FixedTimezoneHint :offset="FIXED_OFFSET" />
          <div class="flex gap-2">
            <ElButton :loading="exporting" type="success" @click="handleExport">
              {{ tl('excel') }}
            </ElButton>
            <ElButton @click="resetFilters">{{ tl('reset') }}</ElButton>
            <ElButton :loading="loading" type="primary" @click="search">
              {{ tl('search') }}
            </ElButton>
          </div>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('member_account')"
          prop="member_username"
          min-width="120"
        />
        <ElTableColumn :label="tl('level')" width="90">
          <template #default="{ row }">{{ tierLabel(row.level) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('event_progressive_jackpot_reward_amount')"
          align="right"
          min-width="110"
        >
          <template #default="{ row }">
            {{ amountThousands(row.reward, 0) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('unlocked')}／${tl('received')}`"
          align="right"
          min-width="140"
        >
          <template #default="{ row }">
            {{ amountThousands(row.point, 0) }} ／
            {{ amountThousands(row.receive, 0) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('unlock_progress')" width="170">
          <template #default="{ row }">
            <ElProgress
              :percentage="
                unlockProgress(row as EventProgressiveJackpotJoinUserRow)
              "
              :status="Number(row.status) === 2 ? 'success' : undefined"
              :stroke-width="10"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" width="110">
          <template #default="{ row }">
            <ElTag :type="joinUserStatusType(row.status)">
              {{ joinUserStatusLabel(row.status) }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('label_end_time')" width="100">
          <template #default="{ row }">
            {{ endTimeText(row as EventProgressiveJackpotJoinUserRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('event_progressive_jackpot_record_column')"
          align="left"
          width="230"
        >
          <template #default="{ row }">
            <div class="flex flex-wrap items-center gap-2">
              <ElButton
                class="!ml-0"
                size="small"
                @click="depositLogDialog?.open(row.member_id, row.level)"
              >
                {{ tl('event_action.deposit') }}
              </ElButton>
              <ElButton
                class="!ml-0"
                size="small"
                @click="memberDetailDialog?.open(row.member_id)"
              >
                {{ tl('member') }}
              </ElButton>
              <ElButton
                class="!ml-0"
                size="small"
                @click="eventLogDialog?.open(row.member_id, row.level)"
              >
                {{ tl('event_progressive_jackpot_bet_detail') }}
              </ElButton>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('operate')"
          align="left"
          fixed="right"
          width="100"
        >
          <template #default="{ row }">
            <ElButton
              v-if="Number(row.status) === 4"
              :loading="unlocking === row.id"
              class="!ml-0"
              size="small"
              type="warning"
              @click="handleUnlock(row as EventProgressiveJackpotJoinUserRow)"
            >
              {{ tl('event_progressive_jackpot_unlock') }}
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

    <EventLogDialog ref="eventLogDialog" />
    <DepositLogDialog ref="depositLogDialog" />
    <MemberDetailDialog ref="memberDetailDialog" />
  </Page>
</template>
