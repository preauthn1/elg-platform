<script lang="ts" setup>
import type { MemberBankCardRow } from '#/api';

import { reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getMemberBankAccountListApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { hasMenuCode } from '#/router/agent-menu';
import { fromApiTime, toApiTime as tzToApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import MemberBankAccountEditDialog from './components/member-bank-account-edit-dialog.vue';

defineOptions({ name: 'MemberBankAccount' });

const PER_PAGE = 50;
const canEdit = hasMenuCode('member_bank_account_edit_permission');

const editRef = ref<InstanceType<typeof MemberBankAccountEditDialog>>();

const filters = reactive({
  account_no: '',
  agent_username: '',
  binding_range: ['', ''] as string[],
  member_username: '',
});

/**
 * 綁定時間查詢參數換算成 UTC（basis 0）。
 * 此端點（MemberBankAccountController@list）輸入端直接比對 UTC 欄位、
 * 輸出端 DATE_ADD +8 —— 不對稱端點，前端以 basis 0 補償輸入側
 * （原本寫死 -8 小時；預設 GMT+8 時行為完全相同）。 */
function toUtcParam(v?: string) {
  return v ? tzToApiTime(v, 0) : '';
}

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<MemberBankCardRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.binding_range ?? [];
      return getMemberBankAccountListApi({
        account_no: filters.account_no.trim(),
        agent_username: filters.agent_username.trim(),
        binding_end_time: toUtcParam(end),
        binding_start_time: toUtcParam(start),
        member_username: filters.member_username.trim(),
        page,
        per_page,
      });
    },
    { immediate: true, perPage: PER_PAGE },
  );

function handleClear() {
  filters.account_no = '';
  filters.agent_username = '';
  filters.binding_range = ['', ''];
  filters.member_username = '';
}
</script>

<template>
  <Page
    :title="`${tl('member_lower_management')} > ${tl('bank_card_management')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('binding_time')">
            <DateRangeFields
              v-model="filters.binding_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.agent_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('username')">
            <ElInput v-model="filters.member_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('account_no')">
            <ElInput v-model="filters.account_no" clearable />
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
      <template #header>
        <span class="font-medium">{{ tl('list') }}</span>
      </template>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :formatter="(row: MemberBankCardRow) => fromApiTime(row.binding_time)"
          :label="tl('binding_time')"
          align="center"
          min-width="150"
        />
        <ElTableColumn
          :label="tl('agent_account')"
          align="center"
          min-width="120"
          prop="agent_username"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('username')" align="center" min-width="120">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('bank_name')" align="center" min-width="120">
          <template #default="{ row }">{{ row.bank_name || '-' }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('account_no')"
          align="center"
          min-width="140"
          prop="account_no"
        />
        <ElTableColumn
          :label="tl('account_name')"
          align="center"
          min-width="120"
          prop="account_name"
        />
        <ElTableColumn
          v-if="canEdit"
          :label="tl('operate')"
          align="center"
          width="100"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              size="small"
              type="warning"
              @click="editRef?.open(row.id)"
            >
              {{ tl('edit') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_data') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>

    <MemberBankAccountEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
