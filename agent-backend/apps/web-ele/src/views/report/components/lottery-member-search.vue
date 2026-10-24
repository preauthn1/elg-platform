<script lang="ts" setup>
import type {
  LotteryDropdownRow,
  LotteryMemberRow,
  LotteryVariant,
} from '#/api';

import { computed, onMounted, ref } from 'vue';

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

import { getLotteryDropdownApi, getLotteryMemberApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands, toHalfWidth } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

const props = defineProps<{
  /** 標題第二段 menu key（menu_report_sr16 / menu_report_sr17） */
  reportKey: string;
  /** 標題第三段 menu key */
  titleKey: string;
  variant: LotteryVariant;
}>();

const PER_PAGE = 20;

const periods = ref<LotteryDropdownRow[]>([]);
const filters = ref({
  period_id: '' as number | string,
  type: 2 as number | string,
  username: '',
});

const searched = ref(false);

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<LotteryMemberRow>(
    ({ page }) => {
      searched.value = true;
      return getLotteryMemberApi(props.variant, {
        page,
        period_id: filters.value.period_id,
        type: filters.value.type,
        username: toHalfWidth(filters.value.username),
      });
    },
    { perPage: PER_PAGE },
  );

function periodLabel(r: LotteryDropdownRow) {
  return `${tl('periods').replace('{N}', String(r.no))} - ${r.start_time} ~ ${r.end_time}`;
}

const fmtBonus = (row: LotteryMemberRow) => {
  if (Number(row.prize_type) === 2) return String(row.point ?? '');
  return Number(row.point) > 0 ? amountThousands(row.point, 2) : '';
};

/** 獎項：未開獎 / 未中獎 / 中獎獎項名 */
function awardText(row: LotteryMemberRow) {
  if (Number(row.lucky_draw_status) === 0) return tl('no_draw');
  if (!row.prize) return tl('not_prize');
  return tl(row.prize);
}

/** 兌獎狀態：無實物獎項顯示空、否則 已/未領取 */
function statusText(row: LotteryMemberRow) {
  if (Number(row.reward_item_id) === 0) return '';
  return Number(row.status) === 0 ? tl('not_withdrawn') : tl('withdrawn');
}

const periodTitle = computed(
  () =>
    `${tl('statistical_report')} > ${tl(props.reportKey)} > ${tl(props.titleKey)}`,
);

const exporting = ref(false);
/** 匯出 Excel：欄位/17Member（9 欄；值沿用畫面已驗格式） */
async function handleExport() {
  exporting.value = true;
  try {
    const params = {
      page: 1,
      per_page: 100_000,
      period_id: filters.value.period_id,
      type: filters.value.type,
      username: toHalfWidth(filters.value.username),
    };
    const res = await getLotteryMemberApi(props.variant, params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await exportExcel(
      `${props.variant}_member_${Date.now()}`,
      [
        tl('agent_account'),
        tl('member_account'),
        tl('period'),
        tl('lottery_ticket_no'),
        tl('awards'),
        tl('bonus'),
        tl('status'),
        tl('exchange_time'),
        tl('time'),
      ],
      rows.map((r) => [
        r.agent_username,
        r.member_username,
        tl('periods').replace('{N}', String(r.no)),
        r.ticket_number,
        awardText(r),
        fmtBonus(r),
        statusText(r),
        r.time,
        r.created_at,
      ]),
      { audit: { conditions: params }, title: tl(props.titleKey) },
    );
  } finally {
    exporting.value = false;
  }
}

onMounted(async () => {
  periods.value = (await getLotteryDropdownApi(props.variant)) ?? [];
  if (periods.value.length > 0)
    filters.value.period_id = periods.value[0]?.id ?? filters.value.period_id;
});
</script>

<template>
  <Page :title="periodTitle">
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2 lg:grid-cols-4">
          <ElFormItem :label="tl('period')">
            <ElSelect v-model="filters.period_id" class="w-full" filterable>
              <ElOption
                v-for="r in periods"
                :key="r.id"
                :label="periodLabel(r)"
                :value="r.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem>
            <ElSelect v-model="filters.type" class="w-full">
              <ElOption :label="tl('member_account')" :value="2" />
              <ElOption :label="tl('agent_account')" :value="1" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem>
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem>
            <div class="flex gap-2">
              <ElButton :loading="loading" native-type="submit" type="primary">
                {{ tl('search') }}
              </ElButton>
              <ElButton
                :loading="exporting"
                type="success"
                @click="handleExport"
              >
                {{ tl('excel') }}
              </ElButton>
            </div>
          </ElFormItem>
        </div>
      </ElForm>
    </ElCard>

    <ElCard v-if="searched" shadow="never">
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
        <ElTableColumn :label="tl('member_account')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('period')" min-width="100">
          <template #default="{ row }">
            {{ tl('periods').replace('{N}', String(row.no)) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('lottery_ticket_no')"
          prop="ticket_number"
          min-width="120"
        />
        <ElTableColumn :label="tl('awards')" min-width="110">
          <template #default="{ row }">
            {{ awardText(row as LotteryMemberRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('bonus')" align="right" min-width="110">
          <template #default="{ row }">
            {{ fmtBonus(row as LotteryMemberRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" min-width="100">
          <template #default="{ row }">
            {{ statusText(row as LotteryMemberRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('exchange_time')"
          prop="time"
          min-width="150"
        />
        <ElTableColumn :label="tl('time')" prop="created_at" min-width="150" />
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
