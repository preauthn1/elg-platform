<script lang="ts" setup>
import type { RiskMemberRestrictionRow } from '#/api';

import { reactive, ref } from 'vue';

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
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import {
  deleteRiskMemberRestrictionApi,
  getRiskMemberRestrictionListApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';

import MemberRestrictionEditDialog from './components/member-restriction-edit-dialog.vue';

defineOptions({ name: 'RiskMemberRestriction' });

const PER_PAGE = 20;

const editRef = ref<InstanceType<typeof MemberRestrictionEditDialog>>();

const loading = ref(false);
const list = ref<RiskMemberRestrictionRow[]>([]);
const page = ref(1);
const total = ref(0);

const filters = reactive({
  remark: '',
  status: '' as number | string,
  type: '' as number | string,
  username: '',
});

async function loadData() {
  loading.value = true;
  try {
    const res = await getRiskMemberRestrictionListApi({
      page: page.value,
      per_page: PER_PAGE,
      ...(filters.username.trim() ? { username: filters.username.trim() } : {}),
      ...(filters.remark.trim() ? { remark: filters.remark.trim() } : {}),
      ...(filters.status === '' ? {} : { status: filters.status }),
      ...(filters.type === '' ? {} : { type: filters.type }),
    });
    const ok = Number(res.code) === 200;
    list.value = ok ? (res.data?.items ?? []) : [];
    total.value = ok ? (res.data?.pagination?.total ?? 0) : 0;
  } finally {
    loading.value = false;
  }
}

function search() {
  page.value = 1;
  loadData();
}

function clear() {
  filters.username = '';
  filters.remark = '';
  filters.status = '';
  filters.type = '';
  search();
}

function handlePageChange(next: number) {
  page.value = next;
  loadData();
}

function yesNoText(value?: number): string {
  return Number(value) === 1 ? tl('option_yes') : tl('option_no');
}

async function onDelete(row: RiskMemberRestrictionRow) {
  try {
    await ElMessageBox.confirm(
      tl('risk_member_restriction_delete_confirm'),
      tl('tips'),
      {
        cancelButtonText: tl('cancel'),
        confirmButtonText: tl('confirm'),
      },
    );
  } catch {
    return;
  }

  const res = await deleteRiskMemberRestrictionApi(row.id).catch(() => null);
  if (res && Number(res.code) === 200) {
    ElMessage.success(tl('operation_success'));
    loadData();
  } else {
    ElMessage.error(res?.message ?? tl('operation_failed'));
  }
}

loadData();
</script>

<template>
  <Page
    :title="`${tl('menu_risk_management')} > ${tl('menu_risk_member_restriction')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-4">
          <ElFormItem :label="tl('risk_member_restriction_username')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('memo')">
            <ElInput v-model="filters.remark" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="!w-full">
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('close')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('risk_member_restriction_type')">
            <ElSelect v-model="filters.type" class="!w-full">
              <ElOption :label="tl('all')" value="" />
              <ElOption
                :label="tl('risk_member_restriction_type_1')"
                :value="1"
              />
              <ElOption
                :label="tl('risk_member_restriction_type_2')"
                :value="2"
              />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="clear">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <template #header>
        <div class="flex items-center justify-end">
          <ElButton type="success" @click="editRef?.open()">
            {{ tl('add') }}
          </ElButton>
        </div>
      </template>

      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('number')"
          align="center"
          prop="id"
          width="80"
        />
        <ElTableColumn
          :label="tl('risk_member_restriction_type')"
          align="center"
          min-width="110"
        >
          <template #default="{ row }">
            {{ tl(`risk_member_restriction_type_${row.type}`) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_account')"
          align="center"
          min-width="130"
          prop="agent_username"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('real_name')"
          align="center"
          min-width="150"
          prop="target_name"
        />
        <ElTableColumn
          :label="tl('risk_member_restriction_is_disabled')"
          align="center"
          width="100"
        >
          <template #default="{ row }">
            {{ yesNoText(row.is_disabled) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('memo')"
          align="center"
          min-width="140"
          prop="remark"
          show-overflow-tooltip
        />
        <ElTableColumn :label="tl('status')" align="center" width="90">
          <template #default="{ row }">
            <ElTag :type="Number(row.status) === 1 ? 'success' : 'danger'">
              {{ Number(row.status) === 1 ? tl('enable') : tl('close') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('risk_member_restriction_withdrawal_lock')"
          align="center"
          width="100"
        >
          <template #default="{ row }">
            {{ yesNoText(row.withdrawal_locked) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('operate_agent_username')"
          align="center"
          min-width="120"
          prop="updated_by"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.updated_by" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('updated_at')"
          align="center"
          prop="updated_at"
          width="160"
        />
        <ElTableColumn
          :label="tl('action')"
          align="center"
          width="140"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              size="small"
              @click="editRef?.open((row as RiskMemberRestrictionRow).id)"
            >
              {{ tl('edit') }}
            </ElButton>
            <ElButton
              size="small"
              type="danger"
              @click="onDelete(row as RiskMemberRestrictionRow)"
            >
              {{ tl('delete') }}
            </ElButton>
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

    <MemberRestrictionEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
