<script lang="ts" setup>
import type { EventInviteJoinUserParams, EventInviteJoinUserRow } from '#/api';

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
  closeEventInviteJoinUserApi,
  getEventInviteJoinUserListApi,
} from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands, buildPlainAmounts } from '#/utils/format';
import { nowInUserTz } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import EventLogDialog from './components/event-log-dialog.vue';
import StatusLogDialog from './components/status-log-dialog.vue';
import { eventInviteStatusTagType, eventInviteStatusText } from './status-map';

defineOptions({ name: 'EventInviteRecord' });

const PER_PAGE = 20;
/** 頁面口徑固定 GMT+8，日期原樣送出不平移 */
const FIXED_OFFSET = 8;

const filters = reactive({
  date_range: [
    nowInUserTz(FIXED_OFFSET).subtract(1, 'month').format('YYYY-MM-DD'),
    nowInUserTz(FIXED_OFFSET).format('YYYY-MM-DD'),
  ] as string[],
  invitee: '',
  inviter: '',
  status: '' as number | string,
});

const loading = ref(false);
const exporting = ref(false);
const closing = ref<number | string>('');
const page = ref(1);
const total = ref(0);
const list = ref<EventInviteJoinUserRow[]>([]);
/** 獎勵總額為整個活動共用的上限，非每筆邀請各自的值 */
const maxReward = ref(0);
const totals = reactive({
  bet: 0,
  received: 0,
  remaining: 0,
  unlocked: 0,
});

/** 「全部」的 status 是空字串，帶出去會被後端當成 0（進行中），所以整個欄位不帶 */
function buildParams(p: number, perPage: number): EventInviteJoinUserParams {
  const [start, end] = filters.date_range ?? [];
  const params: EventInviteJoinUserParams = {
    end_date: end ?? '',
    invitee: filters.invitee,
    inviter: filters.inviter,
    page: p,
    per_page: perPage,
    start_date: start ?? '',
  };
  if (filters.status !== '') params.status = filters.status;
  return params;
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getEventInviteJoinUserListApi(
      buildParams(page.value, PER_PAGE),
    );
    if (Number(res.code) === 1000) {
      const d = res.data;
      list.value = d?.list ?? [];
      maxReward.value = Number(d?.max_reward ?? 0);
      totals.unlocked = Number(d?.total_unlocked ?? 0);
      totals.received = Number(d?.total_received ?? 0);
      totals.remaining = Number(d?.total_remaining ?? 0);
      totals.bet = Number(d?.total_bet ?? 0);
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

function remaining(row: EventInviteJoinUserRow) {
  return maxReward.value - Number(row.point ?? 0);
}

function percentage(row: EventInviteJoinUserRow) {
  if (maxReward.value <= 0) return 0;
  return Math.round((Number(row.point ?? 0) / maxReward.value) * 100 * 10) / 10;
}

function percentageText(row: EventInviteJoinUserRow) {
  const p = percentage(row);
  return p >= 100 ? '100%' : `${p.toFixed(1)}%`;
}

function doneText(v: number | string | undefined) {
  return Number(v) === 1 ? tl('completed') : tl('not_completed');
}

const logDialog = ref<InstanceType<typeof EventLogDialog>>();
const statusLogDialog = ref<InstanceType<typeof StatusLogDialog>>();

async function handleClose(row: EventInviteJoinUserRow) {
  try {
    await ElMessageBox.confirm(tl('event_invite_close_confirm'), {
      cancelButtonText: tl('cancel'),
      confirmButtonText: tl('submit'),
      type: 'warning',
    });
  } catch {
    return;
  }
  closing.value = row.id;
  try {
    const res = await closeEventInviteJoinUserApi(row.id);
    const code = String(res.code);
    if (code === '1000') {
      ElMessage.success(tl('save_success'));
      await loadData();
      return;
    }
    ElMessage.error(
      code === '2002' ? String(res.data ?? '') : (res.message ?? ''),
    );
  } finally {
    closing.value = '';
  }
}

async function handleExport() {
  exporting.value = true;
  try {
    const params = buildParams(1, 100_000);
    const res = await getEventInviteJoinUserListApi(params);
    const d = Number(res.code) === 1000 ? res.data : undefined;
    const rows = d?.list ?? [];
    const cap = Number(d?.max_reward ?? 0);
    const headers = [
      tl('inviter'),
      tl('invitee'),
      tl('unlocked'),
      tl('received'),
      tl('remaining_point'),
      tl('accumulated_valid_bet'),
      tl('unlock_progress'),
      `${tl('realname_authentication')}${tl('complete')}`,
      `${tl('paytype_deposit')}${tl('complete')}`,
      tl('status'),
    ];
    const body = buildPlainAmounts(() =>
      rows.map((r) => {
        const point = Number(r.point ?? 0);
        const pct = cap > 0 ? Math.round((point / cap) * 100 * 10) / 10 : 0;
        return [
          r.inviter ?? '',
          r.invitee ?? '',
          amountThousands(point),
          amountThousands(r.receive),
          amountThousands(cap - point),
          amountThousands(r.bet),
          `${pct}%`,
          doneText(r.realname_status),
          doneText(r.deposit_status),
          eventInviteStatusText(r.status),
        ];
      }),
    );
    const [start, end] = filters.date_range ?? [];
    await exportExcel(`event_invite_record_${Date.now()}`, headers, body, {
      audit: { conditions: { ...params } },
      dateRange: [start ?? '', end ?? ''],
      title: `${tl('menu_event_invite')}${tl('menu_event_invite_record')}`,
      tzOffset: FIXED_OFFSET,
    });
  } finally {
    exporting.value = false;
  }
}

const title = computed(
  () =>
    `${tl('menu_event')} > ${tl('menu_event_invite')} > ${tl('menu_event_invite_record')}`,
);

onMounted(loadData);
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div
          class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2 lg:grid-cols-4"
        >
          <ElFormItem :label="`${tl('inviter')}${tl('account')}`">
            <ElInput
              v-model="filters.inviter"
              :placeholder="`${tl('example')}：U100234`"
              clearable
            />
          </ElFormItem>
          <ElFormItem :label="`${tl('invitee')}${tl('account')}`">
            <ElInput
              v-model="filters.invitee"
              :placeholder="`${tl('example')}：U100901`"
              clearable
            />
          </ElFormItem>
          <ElFormItem :label="`${tl('event')}${tl('status')}`">
            <ElSelect v-model="filters.status" class="!w-full">
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('in_progress')" :value="0" />
              <ElOption :label="tl('completed')" :value="1" />
              <ElOption :label="tl('closed')" :value="2" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('between')" class="lg:col-span-2">
            <DateRangeFields
              v-model="filters.date_range"
              :fixed-offset="FIXED_OFFSET"
            />
            <FixedTimezoneHint text-key="fixed_tz_hint_no_shift" />
          </ElFormItem>
        </div>
        <div class="flex items-center justify-end gap-2">
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('excel') }}
          </ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable
        v-loading="loading"
        :data="list"
        border
        show-summary
        size="small"
        stripe
        :summary-method="
          () => [
            tl('total'),
            '',
            amountThousands(totals.unlocked),
            amountThousands(totals.received),
            amountThousands(totals.remaining),
            amountThousands(totals.bet),
            '',
            '',
            '',
            '',
            '',
          ]
        "
      >
        <!-- 工單4342：會員帳號欄改掛 MemberAccountLink（可點開會員詳情＋複製 icon） -->
        <ElTableColumn :label="tl('inviter')" prop="inviter" min-width="120">
          <template #default="{ row }">
            <MemberAccountLink :username="row.inviter" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('invitee')" prop="invitee" min-width="120">
          <template #default="{ row }">
            <MemberAccountLink :username="row.invitee" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('unlocked')" align="right" min-width="110">
          <template #default="{ row }">
            {{ amountThousands(row.point) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('received')" align="right" min-width="110">
          <template #default="{ row }">
            {{ amountThousands(row.receive) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('remaining_point')"
          align="right"
          min-width="110"
        >
          <template #default="{ row }">
            {{ amountThousands(remaining(row as EventInviteJoinUserRow)) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('accumulated_valid_bet')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">
            {{ amountThousands(row.bet) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('unlock_progress')" min-width="170">
          <template #default="{ row }">
            <div class="flex items-center gap-2">
              <ElProgress
                :percentage="
                  Math.min(percentage(row as EventInviteJoinUserRow), 100)
                "
                :show-text="false"
                :status="Number(row.status) === 1 ? 'success' : undefined"
                class="w-20"
              />
              <span>{{ percentageText(row as EventInviteJoinUserRow) }}</span>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('realname_authentication')}${tl('complete')}`"
          min-width="120"
        >
          <template #default="{ row }">
            <span
              :class="
                Number(row.realname_status) === 1
                  ? 'text-green-600'
                  : 'text-muted-foreground'
              "
            >
              {{ doneText(row.realname_status) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('paytype_deposit')}${tl('complete')}`"
          min-width="120"
        >
          <template #default="{ row }">
            <span
              :class="
                Number(row.deposit_status) === 1
                  ? 'text-green-600'
                  : 'text-muted-foreground'
              "
            >
              {{ doneText(row.deposit_status) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" min-width="100">
          <template #default="{ row }">
            <ElTag :type="eventInviteStatusTagType(row.status)" size="small">
              {{ eventInviteStatusText(row.status) }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('operate')"
          align="left"
          fixed="right"
          width="210"
        >
          <template #default="{ row }">
            <div class="flex flex-wrap items-center gap-2">
              <ElButton
                class="!ml-0"
                size="small"
                @click="logDialog?.open(row.member_id)"
              >
                {{ tl('log') }}
              </ElButton>
              <ElButton
                class="!ml-0"
                size="small"
                @click="statusLogDialog?.open(row.member_id)"
              >
                {{ tl('status') }}
              </ElButton>
              <ElButton
                v-if="Number(row.status) === 0"
                :loading="closing === row.id"
                class="!ml-0"
                size="small"
                type="danger"
                @click="handleClose(row as EventInviteJoinUserRow)"
              >
                {{ tl('close') }}
              </ElButton>
            </div>
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

    <EventLogDialog ref="logDialog" />
    <StatusLogDialog ref="statusLogDialog" />
  </Page>
</template>
