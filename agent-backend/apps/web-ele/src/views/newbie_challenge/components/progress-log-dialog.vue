<script lang="ts" setup>
import type {
  NewbieChallengeOperationLogRow,
  NewbieChallengeProgressDetail,
} from '#/api';

import { ref } from 'vue';

import { ElDialog, ElTable, ElTableColumn } from 'element-plus';

import {
  getNewbieChallengeOperationLogListApi,
  getNewbieChallengeProgressDetailApi,
} from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';

import { actionText, operatorTypeText, stageText } from '../stage-map';

defineOptions({ name: 'NewbieChallengeProgressLogDialog' });

const PER_PAGE = 20;

const visible = ref(false);
const loading = ref(false);
const progressId = ref<number | string>(0);
const detail = ref<NewbieChallengeProgressDetail | null>(null);
const logs = ref<NewbieChallengeOperationLogRow[]>([]);
const page = ref(1);
const total = ref(0);

async function loadLogs() {
  const res = await getNewbieChallengeOperationLogListApi({
    page: page.value,
    per_page: PER_PAGE,
    progress_id: progressId.value,
  });
  if (Number(res.code) === 200) {
    logs.value = res.data?.items ?? [];
    total.value = res.data?.pagination?.total ?? 0;
  } else {
    logs.value = [];
    total.value = 0;
  }
}

async function load() {
  loading.value = true;
  try {
    const res = await getNewbieChallengeProgressDetailApi(progressId.value);
    detail.value = Number(res.code) === 200 ? (res.data ?? null) : null;
    await loadLogs();
  } finally {
    loading.value = false;
  }
}

function open(id: number | string) {
  progressId.value = id;
  page.value = 1;
  detail.value = null;
  logs.value = [];
  visible.value = true;
  load();
}

function handlePage(p: number) {
  page.value = p;
  loadLogs();
}

/** 操作者：會員/代理各自附帶帳號 */
function operatorLabel(row: NewbieChallengeOperationLogRow) {
  const base = operatorTypeText(row.operator_type);
  if (Number(row.operator_type) === 2 && row.member_username) {
    return `${base} (${row.member_username})`;
  }
  if (Number(row.operator_type) === 3 && row.agent_username) {
    return `${base} (${row.agent_username})`;
  }
  return base;
}

function stageChange(row: NewbieChallengeOperationLogRow) {
  const to = stageText(row.to_stage);
  return row.from_stage === null || row.from_stage === undefined
    ? to
    : `${stageText(row.from_stage)} → ${to}`;
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('newbie_challenge_member_log')"
    width="900"
    align-center
  >
    <div v-loading="loading">
      <div
        v-if="detail"
        class="bg-accent mb-3 grid grid-cols-2 gap-3 rounded p-3 md:grid-cols-4"
      >
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('member_account') }}
          </div>
          <div>{{ detail.member_username }}</div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('newbie_challenge_current_stage') }}
          </div>
          <div>
            {{ stageText(detail.stage) }}
            <span v-if="detail.is_bonus_window_expired" class="text-red-500">
              （{{ tl('newbie_challenge_expired') }}）
            </span>
          </div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('newbie_challenge_activity_amount') }}
          </div>
          <div>{{ detail.activity_reward }}</div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('newbie_challenge_principal_total') }}
          </div>
          <div>{{ detail.principal_total }}</div>
        </div>
        <div v-if="detail.challenge_flow">
          <div class="text-muted-foreground text-xs">
            {{ tl('newbie_challenge_challenge_flow') }}
          </div>
          <div>
            {{ detail.challenge_flow.reach }} /
            {{ detail.challenge_flow.flow_limit }}
          </div>
        </div>
        <div v-if="detail.bonus_flow">
          <div class="text-muted-foreground text-xs">
            {{ tl('newbie_challenge_bonus_flow') }}
          </div>
          <div>
            {{ detail.bonus_flow.reach }} / {{ detail.bonus_flow.flow_limit }}
          </div>
        </div>
      </div>

      <ElTable :data="logs" border size="small" stripe>
        <ElTableColumn :label="tl('time')" prop="created_at" width="170" />
        <ElTableColumn :label="tl('operator')" min-width="140">
          <template #default="{ row }">
            {{ operatorLabel(row as NewbieChallengeOperationLogRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('newbie_challenge_action')" min-width="170">
          <template #default="{ row }">{{ actionText(row.action) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('newbie_challenge_stage_change')"
          min-width="180"
        >
          <template #default="{ row }">
            {{ stageChange(row as NewbieChallengeOperationLogRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" min-width="160">
          <template #default="{ row }">
            <span class="whitespace-pre-line break-words">
              {{ row.remark ?? '' }}
            </span>
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
    </div>
  </ElDialog>
</template>
