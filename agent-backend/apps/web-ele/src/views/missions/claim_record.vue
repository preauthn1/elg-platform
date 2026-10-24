<script lang="ts" setup>
import type { MissionClaimRecordRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

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

import { getMissionClaimRecordApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'MissionsClaimRecord' });

const PER_PAGE = 20;

const filters = reactive({
  is_claimed: '',
  member_username: '',
  reward_type: '',
  time_column: 'claim_time',
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
});

const loading = ref(false);
const list = ref<MissionClaimRecordRow[]>([]);
const total = ref(0);
const page = ref(1);

/**
 * is_claimed：後端 ClaimRecordResource 已經回傳翻譯過的字串（不是 0/1 數字），
 * 直接顯示即可；原本用 Number(v)===1 判斷永遠是 NaN，狀態才會全部顯示未領取
 */
function claimText(v?: number | string) {
  return v ? String(v) : tl('not_receive');
}
function statusFormatter(row: MissionClaimRecordRow) {
  return claimText(row.is_claimed);
}
/** reward_type 為 balance/rolling_chip 等語系 key */
function rewardTypeText(v?: string) {
  return v ? tl(v) : '';
}

function buildParams(p: number, perPage: number) {
  const [start, end] = filters.time_range ?? [];
  return {
    end_time: toApiTime(end ?? ''),
    is_claimed: filters.is_claimed,
    member_username: filters.member_username.trim(),
    page: p,
    per_page: perPage,
    reward_type: filters.reward_type,
    start_time: toApiTime(start ?? ''),
    time_column: filters.time_column,
  };
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getMissionClaimRecordApi(
      buildParams(page.value, PER_PAGE),
    );
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

function handleSearch() {
  page.value = 1;
  loadData();
}
function handlePage(p: number) {
  page.value = p;
  loadData();
}

const exporting = ref(false);
async function handleExport() {
  exporting.value = true;
  try {
    const params = buildParams(1, 5000);
    const res = await getMissionClaimRecordApi(params);
    const rows = Number(res.code) === 200 ? (res.data?.items ?? []) : [];
    await exportExcel({
      audit: { conditions: params },
      columns: [
        { title: `${tl('complete')}${tl('time')}`, width: 20 },
        { title: tl('claim_time'), width: 20 },
        { title: tl('mission.name'), width: 20 },
        { title: tl('agent_account'), width: 20 },
        { title: tl('member_account'), width: 20 },
        { title: `${tl('reward')}${tl('quantity')}`, width: 15 },
        { title: tl('reward_type'), width: 15 },
        { title: tl('status'), width: 12 },
      ],
      rows: rows.map((r) => [
        fromApiTime(r.finish_time),
        fromApiTime(r.claim_time),
        r.mission_name,
        r.agent_username,
        r.member_username,
        r.reward_value,
        rewardTypeText(r.reward_type),
        claimText(r.is_claimed),
      ]),
      title: tl('menu_claim_record'),
    });
  } finally {
    exporting.value = false;
  }
}

onMounted(loadData);
</script>

<template>
  <Page :title="`${tl('missions')} > ${tl('menu_claim_record')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.member_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('reward_type')">
            <ElSelect v-model="filters.reward_type" class="w-full">
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('balance')" value="balance" />
              <ElOption :label="tl('rolling_chip')" value="rolling_chip" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.is_claimed" class="w-full">
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('received')" value="1" />
              <ElOption :label="tl('not_receive')" value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('search_time')">
            <ElSelect v-model="filters.time_column" class="w-full">
              <ElOption :label="tl('claim_time')" value="claim_time" />
              <ElOption
                :label="`${tl('complete')}${tl('time')}`"
                value="finish_time"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('between')" class="md:col-span-2">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
        </div>
        <div class="flex justify-end">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-2 flex justify-end">
        <ElButton :loading="exporting" type="success" @click="handleExport">
          {{ tl('excel') }}
        </ElButton>
      </div>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :formatter="
            (row: MissionClaimRecordRow) => fromApiTime(row.finish_time)
          "
          :label="`${tl('complete')}${tl('time')}`"
          align="center"
          min-width="150"
        />
        <ElTableColumn
          :formatter="
            (row: MissionClaimRecordRow) => fromApiTime(row.claim_time)
          "
          :label="tl('claim_time')"
          align="center"
          min-width="150"
        />
        <ElTableColumn
          :label="tl('mission.name')"
          align="center"
          min-width="120"
          prop="mission_name"
        />
        <ElTableColumn
          :label="tl('agent_account')"
          align="center"
          min-width="110"
          prop="agent_username"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_account')"
          align="center"
          min-width="110"
        >
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('reward')}${tl('quantity')}`"
          align="center"
          prop="reward_value"
          width="100"
        />
        <ElTableColumn
          :label="tl('reward_type')"
          align="center"
          min-width="100"
        >
          <template #default="{ row }">
            {{ rewardTypeText(row.reward_type) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :formatter="statusFormatter"
          :label="tl('status')"
          align="center"
          width="100"
        />
        <template #empty>{{ tl('no_data') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePage"
      />
    </ElCard>
  </Page>
</template>
