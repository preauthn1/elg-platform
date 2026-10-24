<script lang="ts" setup>
import type { MemberInactiveRow } from '#/api';

import { onMounted, reactive } from 'vue';

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
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getAgentViewByIdApi, getMemberInactiveApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useExcelExport } from '#/composables/use-excel-export';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { fromApiTime, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'MemberInactive' });

const userStore = useUserStore();
const { hasAccessByCodes } = useAccess();
const canRevealPhone = hasAccessByCodes(['reveal_phone_number']);

const PER_PAGE = 30;

const filters = reactive({
  agent_username: '',
  date_time_enable: false,
  never_deposit_members: false,
  not_bet_days: '',
  not_deposit_days: '',
  not_login_days: '',
  time_range: [] as string[],
});

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<MemberInactiveRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      const params: any = {
        agent_username: filters.agent_username,
        date_time_enable: filters.date_time_enable ? 1 : 0,
        never_deposit_members: filters.never_deposit_members ? 1 : 0,
        not_bet_days: filters.not_bet_days || 0,
        not_deposit_days: filters.not_deposit_days || 0,
        not_login_days: filters.not_login_days || 0,
        page,
        per_page,
      };
      if (filters.date_time_enable) {
        params.start_date = toApiTime(start ?? '');
        params.end_date = toApiTime(end ?? '');
      }
      return getMemberInactiveApi(params);
    },
    {
      guard: () => {
        if (!filters.agent_username) {
          ElMessage.warning(tl('agent_account'));
          return false;
        }
        return true;
      },
      perPage: PER_PAGE,
      showError: true,
    },
  );

const { exporting, runExport } = useExcelExport();
/** 分批抓到最後一頁；任一批失敗就提示並中止，不產出空白檔 */
async function fetchAllPages(params: any) {
  let data: MemberInactiveRow[] = [];
  for (let page = 1; ; page++) {
    const res = await getMemberInactiveApi({ ...params, page });
    if (Number(res.code) !== 1000) {
      ElMessage.error(res.message);
      throw new Error(res.message);
    }
    data = [...data, ...(res.data ?? [])];
    if (page >= Number(res.pagination?.pages ?? 1)) {
      return { code: res.code, data };
    }
  }
}
/** 匯出 Excel：條件同查詢（agent_username 必填） */
async function handleExport() {
  if (!filters.agent_username) {
    ElMessage.warning(tl('agent_account'));
    return;
  }
  const [start, end] = filters.time_range ?? [];
  const params: any = {
    agent_username: filters.agent_username,
    date_time_enable: filters.date_time_enable ? 1 : 0,
    never_deposit_members: filters.never_deposit_members ? 1 : 0,
    not_bet_days: filters.not_bet_days || 0,
    not_deposit_days: filters.not_deposit_days || 0,
    not_login_days: filters.not_login_days || 0,
    page: 1,
    per_page: 20_000,
  };
  if (filters.date_time_enable) {
    params.start_date = toApiTime(start ?? '');
    params.end_date = toApiTime(end ?? '');
  }
  await runExport(
    () => fetchAllPages(params),
    `member_inactive_${Date.now()}`,
    [
      tl('agent_account'),
      tl('username'),
      tl('real_name'),
      tl('register_time'),
      tl('last_login_time'),
      tl('last_deposit_time'),
      tl('last_withdrawal_time'),
      tl('last_bet_time'),
      ...(canRevealPhone ? [tl('mobile')] : []),
    ],
    (r) => [
      r.agent_username,
      r.username,
      r.first_name,
      fromApiTime(r.register_time),
      fromApiTime(r.last_login_time),
      fromApiTime(r.last_deposit_time),
      fromApiTime(r.last_withdrawal_time),
      fromApiTime(r.last_bet_time),
      ...(canRevealPhone ? [r.mobile] : []),
    ],
    filters.date_time_enable && start && end
      ? {
          audit: { conditions: params },
          dateRange: [start, end],
          title: tl('member_inactivities'),
        }
      : { audit: { conditions: params }, title: tl('member_inactivities') },
  ).catch(() => {});
}

onMounted(async () => {
  const info = userStore.userInfo as any;
  if (Number(info?.host_id) === 0) {
    filters.agent_username = info?.username ?? '';
  } else if (info?.host_id) {
    const parent = await getAgentViewByIdApi(Number(info.host_id)).catch(
      () => null,
    );
    filters.agent_username = (parent as any)?.username ?? info?.username ?? '';
  }
});
</script>

<template>
  <Page :title="tl('member_inactivities')">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm :model="filters" label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.agent_username" clearable />
          </ElFormItem>
          <ElFormItem>
            <ElCheckbox v-model="filters.never_deposit_members">
              {{ tl('never_deposit_members') }}
            </ElCheckbox>
          </ElFormItem>
          <div></div>

          <ElFormItem :label="tl('not_login_days')">
            <ElInput v-model="filters.not_login_days" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('not_deposit_days')">
            <ElInput v-model="filters.not_deposit_days" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('not_bet_days')">
            <ElInput v-model="filters.not_bet_days" clearable />
          </ElFormItem>

          <ElFormItem>
            <ElCheckbox v-model="filters.date_time_enable">
              {{ tl('between') }}
            </ElCheckbox>
          </ElFormItem>
          <ElFormItem v-if="filters.date_time_enable" class="md:col-span-2">
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
          :formatter="
            (row: MemberInactiveRow) => fromApiTime(row.register_time)
          "
          :label="tl('register_time')"
          width="150"
        />
        <ElTableColumn
          :formatter="
            (row: MemberInactiveRow) => fromApiTime(row.last_login_time)
          "
          :label="tl('last_login_time')"
          width="150"
        />
        <ElTableColumn
          :formatter="
            (row: MemberInactiveRow) => fromApiTime(row.last_deposit_time)
          "
          :label="tl('last_deposit_time')"
          width="150"
        />
        <ElTableColumn
          :formatter="
            (row: MemberInactiveRow) => fromApiTime(row.last_withdrawal_time)
          "
          :label="tl('last_withdrawal_time')"
          width="150"
        />
        <ElTableColumn
          :formatter="
            (row: MemberInactiveRow) => fromApiTime(row.last_bet_time)
          "
          :label="tl('last_bet_time')"
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
