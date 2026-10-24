<script lang="ts" setup>
import type {
  LotteryDropdownRow,
  LotteryPeriodRow,
  LotteryVariant,
} from '#/api';

import { computed, onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getLotteryDropdownApi, getLotteryPeriodApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { hasMenuCode } from '#/router/agent-menu';
import { exportExcel } from '#/utils/export-excel';
import { amountPlain, amountThousands, numberFormat } from '#/utils/format';

import LotteryManualDrawDialog from './lottery-manual-draw-dialog.vue';
import LotteryPrizeListDialog from './lottery-prize-list-dialog.vue';
import LotteryWinningListDialog from './lottery-winning-list-dialog.vue';

const props = defineProps<{
  reportKey: string;
  titleKey: string;
  variant: LotteryVariant;
}>();

const PER_PAGE = 3;

/** 各變體：5 個獎項欄 label、winning_numbers 是否開 prize_list、手動開獎權限碼 */
const CONFIG: Record<
  LotteryVariant,
  { manualCode: string; prizeLabels: string[]; winningAsPrizeList: boolean }
> = {
  sr16: {
    manualCode: 'sr16_manual_draw',
    prizeLabels: [
      'ultimate_pot',
      'first_prize',
      'second_prize',
      'third_prize',
      'fourth_prize',
    ],
    winningAsPrizeList: false,
  },
  sr17: {
    manualCode: 'sr17_manual_draw',
    prizeLabels: [
      'first_prize',
      'second_prize',
      'third_prize',
      'special_prize',
      'consolation_prize',
    ],
    winningAsPrizeList: true,
  },
  sr17_6d: {
    manualCode: 'sr17_manual_draw_6d',
    prizeLabels: [
      'first_prize',
      'second_prize',
      'third_prize',
      'fourth_prize',
      'five_prize',
    ],
    winningAsPrizeList: false,
  },
};

const cfg = computed(() => CONFIG[props.variant]);
const canManualDraw = computed(() => hasMenuCode(cfg.value.manualCode));

const winningRef = ref<InstanceType<typeof LotteryWinningListDialog>>();
const prizeRef = ref<InstanceType<typeof LotteryPrizeListDialog>>();
const manualRef = ref<InstanceType<typeof LotteryManualDrawDialog>>();

const periods = ref<LotteryDropdownRow[]>([]);
const periodId = ref<number | string>('');

const searched = ref(false);

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<LotteryPeriodRow>(
    ({ page }) => {
      searched.value = true;
      return getLotteryPeriodApi(props.variant, {
        page,
        period_id: periodId.value,
      });
    },
    { perPage: PER_PAGE },
  );

const title = computed(
  () =>
    `${tl('statistical_report')} > ${tl(props.reportKey)} > ${tl(props.titleKey)}`,
);

function periodLabel(r: LotteryDropdownRow) {
  return `${tl('periods').replace('{N}', String(r.no))} - ${r.start_time} ~ ${r.end_time}`;
}

const rewardItem = (row: LotteryPeriodRow, i: number) =>
  String((row as unknown as Record<string, unknown>)[`reward_item_${i}`] ?? '');

function openPrizeList(row: LotteryPeriodRow) {
  prizeRef.value?.open([
    row.draw_number ?? '',
    row.draw_number_2 ?? '',
    row.draw_number_3 ?? '',
    row.draw_number_4 ?? '',
    row.draw_number_5 ?? '',
  ]);
}

const exporting = ref(false);
/** 匯出 Excel：欄位/17Period（10 欄；獎項欄依變體） */
async function handleExport() {
  exporting.value = true;
  try {
    const params = {
      page: 1,
      per_page: 100_000,
      period_id: periodId.value,
    };
    const res = await getLotteryPeriodApi(props.variant, params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await exportExcel(
      `${props.variant}_period_${Date.now()}`,
      [
        tl('period'),
        tl('winning_numbers'),
        tl('total_number_of_pots'),
        tl('number_of_tickets_issued'),
        tl('bonus'),
        ...cfg.value.prizeLabels.map((l) => tl(l)),
      ],
      rows.map((r) => [
        tl('periods').replace('{N}', String(r.no)),
        r.draw_number,
        amountPlain(r.v_jackpot, 2),
        numberFormat(r.tickets, 0),
        amountPlain(r.point, 2),
        rewardItem(r, 1),
        rewardItem(r, 2),
        rewardItem(r, 3),
        rewardItem(r, 4),
        rewardItem(r, 5),
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
    periodId.value = periods.value[0]?.id ?? periodId.value;
});
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('period')">
            <ElSelect v-model="periodId" class="w-full" filterable>
              <ElOption
                v-for="r in periods"
                :key="r.id"
                :label="periodLabel(r)"
                :value="r.id"
              />
            </ElSelect>
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
        <ElTableColumn :label="tl('period')" min-width="90">
          <template #default="{ row }">
            {{ tl('periods').replace('{N}', String(row.no)) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('winning_numbers')" min-width="120">
          <template #default="{ row }">
            <ElButton
              v-if="cfg.winningAsPrizeList && row.draw_number"
              link
              type="primary"
              @click="openPrizeList(row as LotteryPeriodRow)"
            >
              {{ row.draw_number }}
            </ElButton>
            <span v-else>{{ row.draw_number || '' }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('total_number_of_pots')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            {{ amountThousands(row.v_jackpot, 2) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('number_of_tickets_issued')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            {{ numberFormat(row.tickets, 0) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('bonus')" align="right" min-width="110">
          <template #default="{ row }">
            {{ amountThousands(row.point, 2) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          v-for="(label, i) in cfg.prizeLabels"
          :key="label"
          :label="tl(label)"
          align="right"
          min-width="100"
        >
          <template #default="{ row }">
            {{ rewardItem(row as LotteryPeriodRow, i + 1) }}
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" min-width="120" fixed="right">
          <template #default="{ row }">
            <ElButton
              v-if="row.draw_number"
              size="small"
              type="info"
              @click="winningRef?.open(row.id)"
            >
              {{ tl('winning_list') }}
            </ElButton>
            <ElButton
              v-else-if="canManualDraw"
              :disabled="Number(row.manual_draw) !== 1"
              size="small"
              type="danger"
              @click="manualRef?.open(row as LotteryPeriodRow)"
            >
              {{ tl('manual_draw') }}
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

    <LotteryWinningListDialog ref="winningRef" :variant="variant" />
    <LotteryPrizeListDialog ref="prizeRef" />
    <LotteryManualDrawDialog
      ref="manualRef"
      :variant="variant"
      @success="loadData"
    />
  </Page>
</template>
