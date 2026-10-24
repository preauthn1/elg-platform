<script lang="ts" setup>
import type { VipMemberRow } from '#/api';

import { reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElForm,
  ElFormItem,
  ElInput,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getVipMemberSearchApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useExcelExport } from '#/composables/use-excel-export';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import VipChangeRecordDialog from './components/vip-change-record-dialog.vue';
import VipExpLogDialog from './components/vip-exp-log-dialog.vue';
import VipFlowLimitDialog from './components/vip-flow-limit-dialog.vue';

defineOptions({ name: 'VipSearchMember' });

const PER_PAGE = 50;

const flowLimitRef = ref<InstanceType<typeof VipFlowLimitDialog>>();
const changeRecordRef = ref<InstanceType<typeof VipChangeRecordDialog>>();
const expLogRef = ref<InstanceType<typeof VipExpLogDialog>>();

const filters = reactive({
  fuzzy_query: false,
  username: '',
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<VipMemberRow>(
    ({ page, per_page }) =>
      getVipMemberSearchApi({
        fuzzy_query: filters.fuzzy_query ? 1 : 0,
        page,
        per_page,
        username: filters.username,
      }),
    { perPage: PER_PAGE, showError: true },
  );

function handleClear() {
  filters.username = '';
  filters.fuzzy_query = false;
}

/** VIP 名稱：'-' 表示未啟用 */
function vipNameText(name?: string) {
  return name === '-' ? tl('non_activated') : (name ?? '');
}

/** 次數欄：limit==-1 顯示無限，否則 now/limit */
function timesText(row: VipMemberRow) {
  return Number(row.limit_withdrawal_times) === -1
    ? tl('unlimited')
    : `${row.now_times}/${row.limit_withdrawal_times}`;
}

/** 累積經驗值（target/tickets#4484）：目標為 0 顯示「-」，對齊舊後台 */
function accumulatedExperienceText(row: VipMemberRow) {
  const target = Number(row.target_amount ?? 0);
  return `${amountThousands(row.deposit_amount)} / ${target === 0 ? '-' : amountThousands(row.target_amount)}`;
}

/** 保級經驗值（target/tickets#4484）：達標綠字，未達標紅字，目標為 0 顯示「-」 */
function keepExperienceText(row: VipMemberRow) {
  const need = Number(row.need_keep_amount ?? 0);
  return `${amountThousands(row.keep_amount)} / ${need === 0 ? '-' : amountThousands(row.need_keep_amount)}`;
}
function keepExperienceClass(row: VipMemberRow) {
  return Number(row.keep_amount) > Number(row.need_keep_amount)
    ? 'text-green-600'
    : 'text-red-500';
}

const { exporting, runExport } = useExcelExport();
/** 匯出 Excel：欄位對齊舊版 vip/ajax.php（4 欄；已用次數與上限分兩欄） */
async function handleExport() {
  const params = {
    fuzzy_query: filters.fuzzy_query ? 1 : 0,
    page: 1,
    per_page: 10_000,
    username: filters.username,
  };
  await runExport(
    () => getVipMemberSearchApi(params),
    `vip_search_member_${Date.now()}`,
    [
      tl('member_name'),
      `VIP${tl('name')}`,
      tl('withdrawal_used_times'),
      tl('that_day_withdrawal_amount_limit'),
      tl('accumulated_experience'),
      tl('keep_experience'),
    ],
    (r: VipMemberRow) => [
      r.username,
      vipNameText(r.vip_name),
      Number(r.limit_withdrawal_times) === -1 ? '-' : r.now_times,
      Number(r.limit_withdrawal_times) === -1
        ? tl('unlimited')
        : r.limit_withdrawal_times,
      accumulatedExperienceText(r),
      keepExperienceText(r),
    ],
    { audit: { conditions: params }, title: tl('menu_vip_search_member') },
  );
}

loadData();
</script>

<template>
  <Page :title="`${tl('menu_vip')} > ${tl('menu_vip_search_member')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <ElFormItem :label="tl('username')">
          <div>
            <ElInput v-model="filters.username" clearable />
            <ElCheckbox v-model="filters.fuzzy_query" class="mt-1">
              {{ tl('fuzzy_query') }}
            </ElCheckbox>
          </div>
        </ElFormItem>
        <div class="flex justify-end gap-2">
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('excel') }}
          </ElButton>
          <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('member_name')"
          align="center"
          min-width="140"
        >
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`VIP${tl('name')}`"
          align="center"
          min-width="120"
        >
          <template #default="{ row }">
            {{ vipNameText(row.vip_name) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('withdrawal_used_times')}／${tl('that_day_withdrawal_amount_limit')}`"
          align="center"
          min-width="180"
        >
          <template #default="{ row }">
            {{ timesText(row as VipMemberRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('accumulated_experience')"
          align="center"
          min-width="140"
        >
          <template #default="{ row }">
            {{ accumulatedExperienceText(row as VipMemberRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('keep_experience')"
          align="center"
          min-width="140"
        >
          <template #default="{ row }">
            <span :class="keepExperienceClass(row as VipMemberRow)">
              {{ keepExperienceText(row as VipMemberRow) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('vip_flow_limit_record')"
          align="center"
          width="140"
        >
          <template #default="{ row }">
            <ElButton
              size="small"
              type="primary"
              @click="flowLimitRef?.open(row.id)"
            >
              {{ tl('search') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('vip_change_record')"
          align="center"
          width="140"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              size="small"
              type="primary"
              @click="changeRecordRef?.open(row.id)"
            >
              {{ tl('search') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('vip_exp_log')"
          align="center"
          width="140"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              size="small"
              type="primary"
              @click="expLogRef?.open(row.id)"
            >
              {{ tl('search') }}
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

    <VipFlowLimitDialog ref="flowLimitRef" />
    <VipChangeRecordDialog ref="changeRecordRef" />
    <VipExpLogDialog ref="expLogRef" />
  </Page>
</template>
