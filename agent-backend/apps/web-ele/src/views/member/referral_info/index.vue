<script lang="ts" setup>
import type { MemberReferralInfoParams, MemberReferralInfoRow } from '#/api';

import { onMounted, reactive } from 'vue';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import { getMemberReferralInfoListApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useExcelExport } from '#/composables/use-excel-export';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'MemberReferralInfo' });

const PER_PAGE = 25;

const filters = reactive({
  agent_username: '',
  time_range: [
    dayjs().format('YYYY-MM-DD 00:00'),
    dayjs().format('YYYY-MM-DD 23:59'),
  ] as string[],
  username: '',
});

function buildParams(): Omit<MemberReferralInfoParams, 'page' | 'per_page'> {
  const [start, end] = filters.time_range ?? [];
  const p: Omit<MemberReferralInfoParams, 'page' | 'per_page'> = {
    end_time: end ?? '',
    start_time: start ?? '',
  };
  if (filters.agent_username) p.agent_username = filters.agent_username;
  if (filters.username) p.username = filters.username;
  return p;
}

/** 日期必填（不設限等於全代理線大撈） */
function guardDate() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('required_column'));
    return false;
  }
  return true;
}

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<MemberReferralInfoRow>(
    ({ page, per_page }) =>
      getMemberReferralInfoListApi({ ...buildParams(), page, per_page }),
    { guard: guardDate, perPage: PER_PAGE },
  );

function handleClear() {
  filters.agent_username = '';
  filters.username = '';
  filters.time_range = [
    dayjs().format('YYYY-MM-DD 00:00'),
    dayjs().format('YYYY-MM-DD 23:59'),
  ];
}

const fmt = (v?: null | number | string) => amountThousands(v, 2);

/** 狀態顯示（同會員列表） */
function statusTag(status?: number) {
  if (status === 1) return { text: tl('enable'), type: 'success' as const };
  if (status === 2) return { text: tl('user_block'), type: 'warning' as const };
  return { text: tl('disable'), type: 'danger' as const };
}

// 會員詳細彈窗（點帳號開啟，同會員列表）

// Excel 匯出
const { exporting, runExport } = useExcelExport();
function handleExport() {
  if (!guardDate()) return;
  const params = buildParams();
  runExport(
    async () => {
      const res = await getMemberReferralInfoListApi({
        ...params,
        page: 1,
        per_page: 100_000,
      });
      return { code: res.code, data: res.data };
    },
    `member_referral_info_${dayjs().format('YYYYMMDDHHmmss')}.xlsx`,
    [
      tl('agent_account'),
      tl('username'),
      tl('direct_count'),
      tl('first_deposit_count'),
      tl('deposit_count'),
      tl('maf_total_deposit_amount'),
      tl('withdrawal_count'),
      tl('maf_total_withdraw_amount'),
      tl('bet_count'),
      tl('total_valid_bet'),
      tl('reward_amount'),
      tl('total_referral_reward'),
      tl('status'),
    ],
    (row) => [
      row.agent_username,
      row.username,
      row.direct_count,
      row.first_deposit_count,
      row.deposit_count,
      fmt(row.deposit_amount),
      row.withdrawal_count,
      fmt(row.withdrawal_amount),
      row.bet_count,
      fmt(row.valid_bet),
      fmt(row.reward_amount),
      fmt(row.referral_reward_amount),
      statusTag(row.status).text,
    ],
    {
      audit: { conditions: params },
      dateRange: [filters.time_range[0] ?? '', filters.time_range[1] ?? ''],
      title: tl('menu_member_referral_info'),
    },
  );
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('member_lower_management')} > ${tl('menu_member_referral_info')}`"
  >
    <!-- 搜尋 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('agent_account')">
            <ElInput
              v-model="filters.agent_username"
              :placeholder="tl('please_enter')"
              clearable
            />
          </ElFormItem>
          <ElFormItem :label="tl('username')">
            <ElInput
              v-model="filters.username"
              :placeholder="tl('please_enter')"
              clearable
            />
          </ElFormItem>
          <ElFormItem :label="tl('date')" required>
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
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('excel') }}
          </ElButton>
          <ElButton native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <!-- 列表 -->
    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('agent_account')"
          fixed="left"
          min-width="120"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('username')" fixed="left" min-width="130">
          <template #default="{ row }">
            <MemberAccountLink
              :member-id="row.member_id"
              :username="row.username"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('direct_downline')" align="center">
          <ElTableColumn
            :label="tl('direct_count')"
            align="right"
            min-width="90"
            prop="direct_count"
          />
          <ElTableColumn
            :label="tl('first_deposit_count')"
            align="right"
            min-width="90"
            prop="first_deposit_count"
          />
          <ElTableColumn
            :label="tl('deposit_count')"
            align="right"
            min-width="90"
            prop="deposit_count"
          />
          <ElTableColumn
            :label="tl('maf_total_deposit_amount')"
            align="right"
            min-width="110"
          >
            <template #default="{ row }">
              {{ fmt(row.deposit_amount) }}
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('withdrawal_count')"
            align="right"
            min-width="90"
            prop="withdrawal_count"
          />
          <ElTableColumn
            :label="tl('maf_total_withdraw_amount')"
            align="right"
            min-width="110"
          >
            <template #default="{ row }">
              {{ fmt(row.withdrawal_amount) }}
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('bet_count')"
            align="right"
            min-width="90"
            prop="bet_count"
          />
          <ElTableColumn
            :label="tl('total_valid_bet')"
            align="right"
            min-width="110"
          >
            <template #default="{ row }">{{ fmt(row.valid_bet) }}</template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('reward_amount')"
            align="right"
            min-width="100"
          >
            <template #default="{ row }">{{ fmt(row.reward_amount) }}</template>
          </ElTableColumn>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('total_referral_reward')"
          align="right"
          min-width="110"
        >
          <template #default="{ row }">
            {{ fmt(row.referral_reward_amount) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" fixed="right" min-width="80">
          <template #default="{ row }">
            <ElTag :type="statusTag(row.status).type" size="small">
              {{ statusTag(row.status).text }}
            </ElTag>
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
