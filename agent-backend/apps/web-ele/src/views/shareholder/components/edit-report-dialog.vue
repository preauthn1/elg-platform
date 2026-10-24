<script lang="ts" setup>
import type {
  ShareholderCategoryRow,
  ShareholderTotalSalesSummary,
} from '#/api';

import { computed, ref, watch } from 'vue';

import dayjs from 'dayjs';
import {
  ElButton,
  ElDialog,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getShareholderCategoryListApi,
  getShareholderTotalSalesApi,
  updateShareholderTotalSalesApi,
} from '#/api';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

defineOptions({ name: 'ShareholderEditReportDialog' });

const props = defineProps<{
  agentId: number | string;
  payoutRecordId: number | string;
  shareholderType: number | string;
  summaryDate: string;
  v2?: boolean;
}>();
const emit = defineEmits<{ success: [] }>();
const visible = defineModel<boolean>({ default: false });

const isType1 = computed(() => Number(props.shareholderType) === 1);

interface EditRow {
  bet_ratio: number | string;
  category_id: number | string;
  commission_ratio: number | string;
  game_spending: number | string;
  name: string;
  net_win: number | string;
  record_id: number | string;
  turnover: number | string;
}

const loading = ref(false);
const saving = ref(false);
const reportStatus = ref<number | string>(0);
const step = ref(1);
const rows = ref<EditRow[]>([]);

/** summary_date - 8h（Y-m-d H:i:s）。 */
const apiDate = computed(() =>
  dayjs(`${props.summaryDate} 00:00:00`)
    .subtract(8, 'hour')
    .format('YYYY-MM-DD HH:mm:ss'),
);

const editable = computed(() => Number(reportStatus.value) === 3);

async function load() {
  loading.value = true;
  step.value = 1;
  try {
    const [catRes, salesRes] = await Promise.all([
      getShareholderCategoryListApi(),
      getShareholderTotalSalesApi(
        {
          date: apiDate.value,
          id: props.agentId,
          payout_record_id: props.payoutRecordId,
          shareholder_type: props.shareholderType,
        },
        props.v2,
      ),
    ]);
    const cats: ShareholderCategoryRow[] =
      Number(catRes.code) === 1000 ? (catRes.data ?? []) : [];
    reportStatus.value = salesRes.data?.status ?? 0;
    const summary: ShareholderTotalSalesSummary[] =
      Number(salesRes.code) === 1000 ? (salesRes.data?.summary ?? []) : [];
    const byCat = new Map<string, ShareholderTotalSalesSummary>();
    for (const s of summary) byCat.set(String(s.shareholder_category_id), s);

    rows.value = cats.map((c) => {
      const s = byCat.get(String(c.id));
      return {
        category_id: c.id,
        name: c.name ?? '',
        record_id: s?.id ?? Number(c.id) * -1,
        turnover: s?.turnover ?? '0.0000',
        game_spending: s?.game_spending ?? '0.0000',
        net_win: s?.net_win ?? '0.0000',
        bet_ratio: s?.bet_ratio ?? 100,
        commission_ratio: s?.commission_ratio ?? 0,
      };
    });
  } finally {
    loading.value = false;
  }
}

/** step2 預覽計算列。 */
const previewRows = computed(() =>
  rows.value.map((r) => {
    const turnover = Number(r.turnover) || 0;
    const spending = Number(r.game_spending) || 0;
    const profit = turnover - spending;
    const betR = Number(r.bet_ratio) || 0;
    const commR = Number(r.commission_ratio) || 0;
    const denom = betR + commR;
    return {
      name: r.name,
      turnover,
      game_spending: spending,
      game_profit: profit,
      net_win: Number(r.net_win) || 0,
      bet_bonus: denom === 0 ? 0 : profit * (betR / denom),
      comm_bonus: denom === 0 ? 0 : profit * (commR / denom),
    };
  }),
);

async function save() {
  saving.value = true;
  try {
    const record = rows.value.map((r) => ({
      id: r.record_id,
      turnover: r.turnover === '' ? '0.0000' : r.turnover,
      game_spending: r.game_spending === '' ? '0.0000' : r.game_spending,
      category_name: 0,
      ...(isType1.value
        ? { net_win: r.net_win === '' ? '0.0000' : r.net_win }
        : {}),
    }));
    const res = await updateShareholderTotalSalesApi(
      {
        payout_record_id: props.payoutRecordId,
        record,
        shareholder_type: props.shareholderType,
        summary_date: apiDate.value,
      },
      props.v2,
    );
    if (Number(res.code) === 1000) {
      visible.value = false;
      // 存檔後派發狀態會變，通知父層重載列表（舊版是整頁 reload）
      emit('success');
    } else if (String(res.code) === '2002') {
      ElMessageBox.alert(String(res.data ?? ''));
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}

watch(visible, (v) => {
  if (v) load();
});

/**
 * 工單4371：總押注/總賠出/總正向業績原本是純文字輸入框，使用者常從 Excel
 * 貼上帶千分位逗號或尾端空白的數字，送到後端做算術運算會拋錯。改為輸入當下
 * 就只保留數字與小數點（僅保留第一個小數點），從源頭擋掉非數字字元。
 */
function sanitizeNumericInput(raw: string): string {
  let cleaned = raw.replaceAll(/[^0-9.]/g, '');
  const firstDot = cleaned.indexOf('.');
  if (firstDot !== -1) {
    cleaned =
      cleaned.slice(0, firstDot + 1) +
      cleaned.slice(firstDot + 1).replaceAll('.', '');
  }
  return cleaned;
}

function onNumericInput(
  row: EditRow,
  key: 'game_spending' | 'net_win' | 'turnover',
  val: string,
) {
  row[key] = sanitizeNumericInput(val);
}
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('menu_cj7_shareholder_report')"
    width="900px"
  >
    <div v-loading="loading">
      <!-- step1 編輯 -->
      <ElTable v-show="editable && step === 1" :data="rows" border size="small">
        <ElTableColumn :label="tl('category')" prop="name" min-width="120" />
        <ElTableColumn :label="tl('total_bet')" min-width="140">
          <template #default="{ row }">
            <ElInput
              :model-value="row.turnover"
              class="text-right"
              @update:model-value="
                (val) => onNumericInput(row as EditRow, 'turnover', String(val))
              "
            />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('total_lose')" min-width="140">
          <template #default="{ row }">
            <ElInput
              :model-value="row.game_spending"
              class="text-right"
              @update:model-value="
                (val) =>
                  onNumericInput(row as EditRow, 'game_spending', String(val))
              "
            />
          </template>
        </ElTableColumn>
        <ElTableColumn
          v-if="isType1"
          :label="tl('total_positive_performance')"
          min-width="140"
        >
          <template #default="{ row }">
            <ElInput
              :model-value="row.net_win"
              class="text-right"
              @update:model-value="
                (val) => onNumericInput(row as EditRow, 'net_win', String(val))
              "
            />
          </template>
        </ElTableColumn>
      </ElTable>

      <!-- step2 預覽 -->
      <ElTable
        v-show="!editable || step === 2"
        :data="previewRows"
        border
        size="small"
      >
        <ElTableColumn :label="tl('category')" prop="name" min-width="120" />
        <ElTableColumn :label="tl('total_bet')" align="right" min-width="120">
          <template #default="{ row }">
            {{ amountThousands(row.turnover, 4) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('total_lose')" align="right" min-width="120">
          <template #default="{ row }">
            {{ amountThousands(row.game_spending, 4) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('net_profit')" align="right" min-width="120">
          <template #default="{ row }">
            {{ amountThousands(row.game_profit, 4) }}
          </template>
        </ElTableColumn>
        <template v-if="isType1">
          <ElTableColumn
            :label="tl('total_positive_performance')"
            align="right"
            min-width="120"
          >
            <template #default="{ row }">
              {{ amountThousands(row.net_win, 4) }}
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('betting_turnover_bonus')"
            align="right"
            min-width="120"
          >
            <template #default="{ row }">
              {{ amountThousands(row.bet_bonus, 4) }}
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('performance_bonus')"
            align="right"
            min-width="120"
          >
            <template #default="{ row }">
              {{ amountThousands(row.comm_bonus, 4) }}
            </template>
          </ElTableColumn>
        </template>
      </ElTable>
    </div>

    <template #footer>
      <template v-if="editable">
        <ElButton v-if="step === 1" type="success" @click="step = 2">
          {{ tl('next') }}
        </ElButton>
        <ElButton v-if="step === 2" @click="step = 1">
          {{ tl('previous') }}
        </ElButton>
        <ElButton
          v-if="step === 2"
          :loading="saving"
          type="success"
          @click="save"
        >
          {{ tl('next') }}
        </ElButton>
      </template>
    </template>
  </ElDialog>
</template>
