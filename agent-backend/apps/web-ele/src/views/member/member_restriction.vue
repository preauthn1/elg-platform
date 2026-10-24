<script lang="ts" setup>
import type { MemberRestrictionRow } from '#/api';

import { reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { deleteMemberRestrictionApi, getMemberRestrictionListApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import RestrictionEditDialog from './components/restriction-edit-dialog.vue';

defineOptions({ name: 'MemberRestriction' });

const PER_PAGE = 10;

const editRef = ref<InstanceType<typeof RestrictionEditDialog>>();

const filters = reactive({
  account_status: '0' as number | string,
  username: '',
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<MemberRestrictionRow>(
    ({ page, per_page }) =>
      getMemberRestrictionListApi({
        account_status: filters.account_status,
        page,
        per_page,
        username: filters.username,
      }),
    { perPage: PER_PAGE, showError: true },
  );

/** 備註檢視 */
const memoVisible = ref(false);
const memoText = ref('');
function showMemo(text?: string) {
  memoText.value = text ?? '';
  memoVisible.value = true;
}

function accountStatusText(s?: number) {
  return Number(s) === 0 ? '-' : tl(`account_status.${s}`);
}

function riskText(s?: number) {
  return Number(s) === 0 ? '-' : tl(`risk.${s}`);
}

function handleClear() {
  filters.username = '';
  filters.account_status = '0';
}

async function handleDelete(row: MemberRestrictionRow) {
  try {
    await ElMessageBox.confirm(
      `${tl('delete')} ${row.username}?`,
      tl('delete'),
    );
  } catch {
    return;
  }
  const res = await deleteMemberRestrictionApi(row.id);
  if (Number(res.code) === 1000) {
    ElMessage.success(res.message);
    loadData();
  } else {
    // 2002 顯示 data，其餘顯示 message
    ElMessage.error(Number(res.code) === 2002 ? String(res.data) : res.message);
  }
}
</script>

<template>
  <Page
    :title="`${tl('member_lower_management')} > ${tl('menu_member_restriction')}`"
  >
    <!-- 查詢 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('account_status')">
            <ElSelect v-model="filters.account_status" class="w-full">
              <ElOption :label="tl('all')" value="0" />
              <ElOption :label="tl('account_status.1')" value="1" />
              <ElOption :label="tl('account_status.2')" value="2" />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
          <ElButton type="success" @click="editRef?.open()">
            {{ tl('add') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <!-- 列表 -->
    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('member_name')"
          align="center"
          min-width="110"
        >
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('account_status')" align="center" width="100">
          <template #default="{ row }">
            {{ tl(`account_status.${row.account_status}`) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('member_restriction.previous')}${tl('account_status')}`"
          align="center"
          width="120"
        >
          <template #default="{ row }">
            {{ accountStatusText(row.previous_account_status) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('risk')" align="center" width="100">
          <template #default="{ row }">{{ tl(`risk.${row.risk}`) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('member_restriction.previous')}${tl('risk')}`"
          align="center"
          width="120"
        >
          <template #default="{ row }">
            {{ riskText(row.previous_risk) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" align="center" width="100">
          <template #default="{ row }">
            <ElButton
              :disabled="!String(row.remark ?? '').trim()"
              size="small"
              type="info"
              @click="showMemo(row.remark)"
            >
              {{ tl('detail') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('member_restriction.previous')}${tl('memo')}`"
          align="center"
          width="120"
        >
          <template #default="{ row }">
            <ElButton
              :disabled="!String(row.previous_remark ?? '').trim()"
              size="small"
              type="info"
              @click="showMemo(row.previous_remark)"
            >
              {{ tl('detail') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('amend')" align="center" width="90">
          <template #default="{ row }">
            {{ Number(row.amend) === 0 ? tl('option_no') : tl('option_yes') }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('handle')"
          align="center"
          prop="operator"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.operator" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('last_modify_date')"
          align="center"
          prop="updated_at"
          width="160"
        />
        <ElTableColumn align="center" width="90" fixed="right">
          <template #default="{ row }">
            <ElDropdown>
              <ElButton size="small">{{ tl('features') }}</ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem @click="editRef?.open(row.id)">
                    {{ tl('edit') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    @click="handleDelete(row as MemberRestrictionRow)"
                  >
                    {{ tl('delete') }}
                  </ElDropdownItem>
                </ElDropdownMenu>
              </template>
            </ElDropdown>
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

    <!-- 備註檢視 -->
    <ElDialog
      v-model="memoVisible"
      :title="tl('memo')"
      width="500"
      align-center
    >
      <p class="break-words text-center">{{ memoText }}</p>
    </ElDialog>

    <RestrictionEditDialog ref="editRef" @saved="loadData" />
  </Page>
</template>
