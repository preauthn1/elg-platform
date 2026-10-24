<script lang="ts" setup>
import type { MemberActivityRow } from '#/api';

import { onMounted, reactive } from 'vue';
import { useRoute } from 'vue-router';

import { useAccess } from '@vben/access';
import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getAgentViewByIdApi, getMemberActivitiesApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useExcelExport } from '#/composables/use-excel-export';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'MemberActivities' });

const route = useRoute();
const userStore = useUserStore();
const { hasAccessByCodes } = useAccess();
const canRevealPhone = hasAccessByCodes(['reveal_phone_number']);

/** 進頁的 query（鈴鐺通知會帶查詢條件進來） */
const qs = (k: string) => {
  const v = route.query[k];
  return String((Array.isArray(v) ? v[0] : v) ?? '');
};
const qb = (k: string) => qs(k) === '1';

const PER_PAGE = 30;

const filters = reactive({
  agent_username: '',
  amount_search_range: 0,
  amount_search_type: 0,
  amount_search_value: '',
  include_backend_deposit: false,
  just_registered: false,
  search_all_members: false,
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
  username: '',
});

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<MemberActivityRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      const params: any = {
        agent_username: filters.agent_username,
        end_date: toApiTime(end),
        include_backend_deposit: filters.include_backend_deposit ? 1 : 0,
        just_registered: filters.just_registered ? 1 : 0,
        page,
        per_page,
        search_all_members: filters.search_all_members ? 1 : 0,
        start_date: toApiTime(start),
        username: filters.username,
      };
      // 金額搜尋：僅在有值且非 0 時帶上
      const av = String(filters.amount_search_value).trim();
      if (av !== '' && av !== '0') {
        params.amount_search_type = filters.amount_search_type;
        params.amount_search_value = av;
        params.amount_search_range = filters.amount_search_range;
      }
      return getMemberActivitiesApi(params);
    },
    {
      guard: () => {
        // agent_username 或 username 至少一個
        if (!filters.agent_username && !filters.username) {
          ElMessage.warning(`${tl('agent_account')} / ${tl('username')}`);
          return false;
        }
        const [start, end] = filters.time_range ?? [];
        return Boolean(start && end);
      },
      perPage: PER_PAGE,
      showError: true,
    },
  );

const { exporting, runExport } = useExcelExport();
/** 匯出 Excel：條件同查詢（agent_username 或 username 至少一個、日期必填） */
async function handleExport() {
  if (!filters.agent_username && !filters.username) {
    ElMessage.warning(`${tl('agent_account')} / ${tl('username')}`);
    return;
  }
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) return;
  const params: any = {
    agent_username: filters.agent_username,
    end_date: toApiTime(end),
    include_backend_deposit: filters.include_backend_deposit ? 1 : 0,
    just_registered: filters.just_registered ? 1 : 0,
    page: 1,
    per_page: 100_000,
    search_all_members: filters.search_all_members ? 1 : 0,
    start_date: toApiTime(start),
    username: filters.username,
  };
  const av = String(filters.amount_search_value).trim();
  if (av !== '' && av !== '0') {
    params.amount_search_type = filters.amount_search_type;
    params.amount_search_value = av;
    params.amount_search_range = filters.amount_search_range;
  }
  await runExport(
    () => getMemberActivitiesApi(params),
    `member_activities_${Date.now()}`,
    [
      tl('agent_account'),
      tl('username'),
      tl('real_name'),
      tl('deposit_amount'),
      tl('deposit_count'),
      tl('withdrawal_amount'),
      tl('withdrawal_count'),
      tl('deposit_sub_withdrawal_amount'),
      tl('register_time'),
      tl('last_login_time'),
      ...(canRevealPhone ? [tl('mobile')] : []),
    ],
    (r) => [
      r.agent_username,
      r.username,
      r.first_name,
      r.deposit_point_sum,
      r.deposit_count,
      r.withdrawal_point_sum,
      r.withdrawal_count,
      r.deposit_sub_withdrawal_amount,
      r.registered_time,
      r.last_login,
      ...(canRevealPhone ? [r.mobile] : []),
    ],
    {
      audit: { conditions: params },
      dateRange: [start, end],
      title: tl('member_activities'),
    },
  );
}

onMounted(async () => {
  // 通知列/外部連結帶進來的條件（比照舊版 index.php 從 GET 接同一組）
  filters.just_registered = qb('just_registered');
  filters.search_all_members = qb('search_all_members');
  filters.include_backend_deposit = qb('include_backend_deposit');
  if (qs('username')) filters.username = qs('username');
  if (qs('amount_search_type')) {
    filters.amount_search_type = Number(qs('amount_search_type'));
  }
  if (qs('amount_search_value')) {
    filters.amount_search_value = qs('amount_search_value');
  }
  if (qs('amount_search_range')) {
    filters.amount_search_range = Number(qs('amount_search_range'));
  }
  if (qs('start_date') && qs('end_date')) {
    filters.time_range = [qs('start_date'), qs('end_date')];
  }

  // agent_username：query 優先，沒帶才用本人（總代 host_id=0）或上層代理
  const fromQuery = qs('agent_username');
  const info = userStore.userInfo as any;
  if (fromQuery) {
    filters.agent_username = fromQuery;
  } else if (Number(info?.host_id) === 0) {
    filters.agent_username = info?.username ?? '';
  } else if (info?.host_id) {
    const parent = await getAgentViewByIdApi(Number(info.host_id)).catch(
      () => null,
    );
    filters.agent_username = (parent as any)?.username ?? info?.username ?? '';
  }

  if (qb('start_search')) search();
});
</script>

<template>
  <Page :title="tl('member_activities')">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm :model="filters" label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('agent_account')">
            <div class="w-full">
              <ElInput v-model="filters.agent_username" clearable />
              <ElCheckbox v-model="filters.search_all_members" class="mt-1">
                {{ tl('search_all_members') }}
              </ElCheckbox>
            </div>
          </ElFormItem>
          <ElFormItem :label="tl('username')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem>
            <div class="flex gap-4">
              <ElCheckbox v-model="filters.just_registered">
                {{ tl('just_registered') }}
              </ElCheckbox>
              <ElCheckbox v-model="filters.include_backend_deposit">
                {{ tl('include_backend_deposit') }}
              </ElCheckbox>
            </div>
          </ElFormItem>

          <ElFormItem
            :label="`${tl('amount')}${tl('search')}`"
            class="md:col-span-2"
          >
            <div class="flex w-full gap-1">
              <ElSelect
                v-model="filters.amount_search_type"
                class="!w-28 shrink-0"
              >
                <ElOption :label="tl('paytype_deposit')" :value="0" />
                <ElOption :label="tl('paytype_withdrawal')" :value="1" />
              </ElSelect>
              <ElInput v-model="filters.amount_search_value" clearable />
              <ElSelect
                v-model="filters.amount_search_range"
                class="!w-24 shrink-0"
              >
                <ElOption :label="tl('above')" :value="0" />
                <ElOption :label="tl('below')" :value="1" />
              </ElSelect>
            </div>
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
        <div class="flex flex-wrap items-center justify-end gap-2">
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
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('agent_account')"
          prop="agent_username"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('username')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('real_name')"
          prop="first_name"
          min-width="100"
        />
        <ElTableColumn
          :label="tl('deposit_amount')"
          prop="deposit_point_sum"
          align="right"
          min-width="110"
        />
        <ElTableColumn
          :label="tl('deposit_count')"
          prop="deposit_count"
          align="right"
          width="90"
        />
        <ElTableColumn
          :label="tl('withdrawal_amount')"
          prop="withdrawal_point_sum"
          align="right"
          min-width="110"
        />
        <ElTableColumn
          :label="tl('withdrawal_count')"
          prop="withdrawal_count"
          align="right"
          width="90"
        />
        <ElTableColumn
          :label="tl('deposit_sub_withdrawal_amount')"
          prop="deposit_sub_withdrawal_amount"
          align="right"
          min-width="130"
        />
        <ElTableColumn
          :label="tl('register_time')"
          prop="registered_time"
          width="150"
        />
        <ElTableColumn
          :formatter="(row: MemberActivityRow) => fromApiTime(row.last_login)"
          :label="tl('last_login_time')"
          width="150"
        />
        <ElTableColumn
          v-if="canRevealPhone"
          :label="tl('mobile')"
          prop="mobile"
          min-width="110"
        />
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
