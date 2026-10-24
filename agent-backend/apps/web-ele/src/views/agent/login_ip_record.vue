<script lang="ts" setup>
import type { LoginIpLogRow } from '#/api';

import { reactive } from 'vue';

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

import { getLoginIpLogApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useExcelExport } from '#/composables/use-excel-export';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'AgentLoginIpRecord' });

const PER_PAGE = 20;

const filters = reactive({
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
  type: 1,
  username: '',
});

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<LoginIpLogRow>(
    ({ page, per_page }) => {
      // type 1/3 為 IP 查詢、2/4 為帳號查詢
      // ⚠ 端點（AgentController@getLoginIpLog）輸入不減 8 直接比對 UTC 欄位、
      // 輸出卻 DATE_ADD +8（既有不對稱缺陷 F2）。此處與匯出同用 basis 8，
      // 把介面口徑定成「GMT+8 牆鐘」＝與舊後台餵給它的完全同一種值：
      // T=+8 逐字相同，其他時區則等比例重現同一套語意（含那 8 小時偏差）。
      const params: Record<string, unknown> = {
        end_time: toApiTime(filters.time_range?.[1] ?? ''),
        page,
        per_page,
        start_time: toApiTime(filters.time_range?.[0] ?? ''),
        type: filters.type,
        [filters.type === 1 || filters.type === 3 ? 'ip' : 'username']:
          filters.username,
      };
      return getLoginIpLogApi(params as any);
    },
    {
      guard: () => {
        // 未輸入帳號/IP 不查詢
        if (!filters.username) {
          ElMessage.warning(tl('required_column'));
          return false;
        }
        const [start, end] = filters.time_range ?? [];
        return Boolean(start && end);
      },
      perPage: PER_PAGE,
      showError: true,
    },
  );

function ipText(row: LoginIpLogRow) {
  return `${row.ip_1}.${row.ip_2}.${row.ip_3}.${row.ip_4}`;
}

const { exporting, runExport } = useExcelExport();
/** 匯出 Excel：欄位（3 欄） */
async function handleExport() {
  if (!filters.username) {
    ElMessage.warning(tl('required_column'));
    return;
  }
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) return;
  const params: Record<string, unknown> = {
    end_time: toApiTime(end),
    page: 1,
    per_page: 100_000,
    start_time: toApiTime(start),
    type: filters.type,
    [filters.type === 1 || filters.type === 3 ? 'ip' : 'username']:
      filters.username,
  };
  await runExport(
    () => getLoginIpLogApi(params as any),
    `login_ip_record_${Date.now()}`,
    [tl('time'), tl('username'), tl('ip')],
    (r) => [fromApiTime(r.created_at), r.username, ipText(r)],
    {
      audit: { conditions: params },
      dateRange: [start, end],
      title: tl('menu_agent_login_record'),
    },
  );
}
</script>

<template>
  <Page :title="`${tl('agnet_management')} > ${tl('menu_agent_login_record')}`">
    <!-- 查詢 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem>
            <template #label>
              <ElSelect v-model="filters.type" class="!w-36">
                <ElOption :label="`${tl('agent')}${tl('ip')}`" :value="1" />
                <ElOption :label="tl('agent_account')" :value="2" />
                <ElOption :label="`${tl('member')}${tl('ip')}`" :value="3" />
                <ElOption :label="tl('member_account')" :value="4" />
              </ElSelect>
            </template>
            <div class="w-full">
              <ElInput v-model="filters.username" clearable />
              <div class="mt-1 text-xs text-red-500">
                {{ tl('fuzzy_search_example_1') }}
              </div>
            </div>
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <div class="flex gap-2">
            <ElButton :loading="exporting" type="success" @click="handleExport">
              {{ tl('excel') }}
            </ElButton>
            <ElButton :loading="loading" native-type="submit" type="primary">
              {{ tl('search') }}
            </ElButton>
          </div>
        </div>
      </ElForm>
    </ElCard>

    <!-- 列表 -->
    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :formatter="(row: LoginIpLogRow) => fromApiTime(row.created_at)"
          :label="tl('time')"
          width="180"
        />
        <ElTableColumn :label="tl('username')" prop="username" min-width="140">
          <template #default="{ row }">
            <AgentAccount :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('ip')" min-width="140">
          <template #default="{ row }">{{ ipText(row) }}</template>
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
