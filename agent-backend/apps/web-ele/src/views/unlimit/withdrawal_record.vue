<script lang="ts" setup>
import type { UnlimitWithdrawalRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

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

import { getCustomizedSettingApi, getUnlimitWithdrawalRecordApi } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'UnlimitWithdrawalRecord' });

const PER_PAGE = 20;

const filters = reactive({
  member: '',
  order_no: '',
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
});

/** customizedSetting 4501 == 1 顯示本身相關欄 */
const isAgentItselfRebate = ref(false);

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<UnlimitWithdrawalRow>(
    ({ page }) => {
      const [start, end] = filters.time_range ?? [];
      return getUnlimitWithdrawalRecordApi({
        end_time: toApiTime(end ?? ''),
        member: filters.member,
        order_no: filters.order_no,
        page,
        start_time: toApiTime(start ?? ''),
      });
    },
    {
      guard: () => {
        const [start, end] = filters.time_range ?? [];
        return Boolean(start && end);
      },
      perPage: PER_PAGE,
    },
  );

const fmt = (v?: number | string) => amountThousands(v, 2);

/** 提現金額 = amount + itself_amount */
function withdrawAmount(row: UnlimitWithdrawalRow) {
  return amountThousands(
    Number(row.amount ?? 0) + Number(row.itself_amount ?? 0),
    2,
  );
}

const title = computed(
  () =>
    `${tl('menu_reward_mode_management')} > ${tl('menu_unlimit')} > ${tl('menu_unlimit_record')}`,
);

function handleReset() {
  filters.member = '';
  filters.order_no = '';
  filters.time_range = [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ];
  search();
}

onMounted(async () => {
  try {
    const settings = await getCustomizedSettingApi();
    const s = (settings ?? []).find((x) => Number(x.setting_key) === 4501);
    isAgentItselfRebate.value = Number(s?.value) === 1;
  } catch {
    isAgentItselfRebate.value = false;
  }
  loadData();
});
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.member" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('withdraw_no')">
            <ElInput v-model="filters.order_no" clearable />
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
        <div class="flex justify-end gap-2">
          <ElButton @click="handleReset">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('withdraw_no')" prop="id" min-width="90" />
        <ElTableColumn :label="tl('member_account')" min-width="120">
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('availableapply_socre')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">{{ fmt(row.reward_point) }}</template>
        </ElTableColumn>
        <ElTableColumn
          v-if="isAgentItselfRebate"
          :label="tl('itself_availableapply_score')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">
            {{ fmt(row.itself_reward_point) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('withdraw_amount')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            {{ withdrawAmount(row as UnlimitWithdrawalRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('remaining_reward_points')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">
            {{ fmt(row.remaining_reward_points) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          v-if="isAgentItselfRebate"
          :label="tl('remaining_itself_reward_points')"
          align="right"
          min-width="150"
        >
          <template #default="{ row }">
            {{ fmt(row.remaining_itself_reward_points) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" min-width="100">
          <template #default="{ row }">
            {{ Number(row.type) === 1 ? tl('passed') : tl('rejected') }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('withdraw_application_time')"
          :formatter="
            (row: UnlimitWithdrawalRow) => fromApiTime(row.created_at)
          "
          align="right"
          min-width="160"
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
