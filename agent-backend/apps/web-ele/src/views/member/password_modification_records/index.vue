<script lang="ts" setup>
import type { PasswordRecordRow } from '#/api';

import { reactive } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getMemberPasswordRecordsApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { fromApiTime, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'MemberPasswordRecords' });

const PER_PAGE = 30;

const filters = reactive({
  agent_username: '',
  ip: '',
  member_username: '',
  modifier_username: '',
  time_range: [] as string[],
  type: '',
});

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<PasswordRecordRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      return getMemberPasswordRecordsApi({
        agent_username: filters.agent_username,
        end_time: toApiTime(end ?? ''),
        ip: filters.ip,
        member_username: filters.member_username,
        modifier_username: filters.modifier_username,
        page,
        per_page,
        start_time: toApiTime(start ?? ''),
        type: filters.type,
      });
    },
    { perPage: PER_PAGE, showError: true },
  );

/**
 * created_at 顯示：端點（getModifyPasswordRecords）回傳裸 UTC，
 * 以 basis 0 換算成所選時區牆鐘（原本寫死 +8 小時；預設 GMT+8 時行為相同）。
 * 注意：此端點輸入側直接比對 UTC 欄位而查詢參數維持 basis 8 —— 這是既有的
 * 輸入輸出不對稱缺陷（見 4095-defects.md），依「缺陷另冊」決策原樣保留。 */
function fmtTime(v?: string) {
  return fromApiTime(v, 0) || '-';
}
</script>

<template>
  <Page :title="tl('password_modification_records')">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm :model="filters" label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('type')">
            <ElSelect v-model="filters.type" class="w-full" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('password')" value="password" />
              <ElOption
                :label="tl('payment_password')"
                value="payment_password"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.agent_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.member_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('operator')">
            <ElInput v-model="filters.modifier_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('ip')">
            <ElInput v-model="filters.ip" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm:ss"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
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
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('id')" prop="id" width="90" />
        <ElTableColumn :label="tl('type')" width="120">
          <template #default="{ row }">
            {{ row.type ? tl(row.type) : '-' }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_account')"
          prop="agent_username"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('operator')"
          prop="modifier_username"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.modifier_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('ip')" prop="ip" min-width="120" />
        <ElTableColumn :label="tl('created_time')" width="170">
          <template #default="{ row }">{{ fmtTime(row.created_at) }}</template>
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
