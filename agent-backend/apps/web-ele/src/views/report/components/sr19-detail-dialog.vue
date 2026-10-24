<script lang="ts" setup>
import type { VNode } from 'vue';

import type { Sr19DetailRow } from '#/api';

import { computed, h, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElInput,
  ElInputNumber,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getReportSr19DetailApi, getTranslateApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands, sumAmounts } from '#/utils/format';
import { toApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'Sr19DetailDialog' });

interface FeeEntry {
  amount: number;
  name: string;
}

const visible = ref(false);
const loading = ref(false);
/** 開窗查詢條件，匯出稽核紀錄用 */
const criteria = ref<Record<string, unknown>>({});
const rows4d = ref<Sr19DetailRow[]>([]);
const rowsOther = ref<Sr19DetailRow[]>([]);
const translates = ref<Record<string, string>>({});
/** 結算頁面手動新增雜項（僅前端暫存，計入結算合計，隨明細一起匯出） */
const feeName = ref('');
const feeAmount = ref<null | number>(null);
const feeEntries = ref<FeeEntry[]>([]);

const costLabel = computed(() => translates.value['3008'] ?? 'Cost');
const jackpotLabel = computed(() => translates.value['3004'] ?? 'Jackpot');

/** 平台名：payfee/smsfee 用 translate，否則原值 */
function vendorText(row: Sr19DetailRow) {
  if (row.vendor === 'payfee') return translates.value['3006'] ?? 'Pay fees';
  if (row.vendor === 'smsfee') return translates.value['3007'] ?? 'SMS fee';
  return row.vendor ?? '';
}

/** 4 位 floor、負紅（vendor_id 36 的投注/輸贏顯示 -- ） */
const fmt = (v?: null | number | string) => amountThousands(v, 4);

function sumOf(rows: Sr19DetailRow[], key: keyof Sr19DetailRow) {
  return Number(sumAmounts(rows.map((r) => r[key])));
}

/** 表尾合計列（4D / Other 共用；標籤放第 4 欄，與 total_count 欄位對齊） */
function buildSummary(rows: Sr19DetailRow[]): Array<string | VNode> {
  const cell = (v?: null | number | string) =>
    h(
      'b',
      { class: Number(v) < 0 ? 'text-red-500' : '' },
      amountThousands(v, 4),
    );
  return [
    '',
    '',
    '',
    tl('total_count'),
    cell(sumOf(rows, 'total_bet')) as any,
    cell(sumOf(rows, 'win_lose')) as any,
    cell(sumOf(rows, 'commission')) as any,
    cell(sumOf(rows, 'lottery')) as any,
    cell(sumOf(rows, 'cost')) as any,
    cell(sumOf(rows, 'v_jackpot')) as any,
    cell(sumOf(rows, 'score')) as any,
    cell(sumOf(rows, 'proportion')) as any,
  ];
}
const summaryMethod4d = () => buildSummary(rows4d.value);
const summaryMethodOther = () => buildSummary(rowsOther.value);

function addFeeEntry() {
  const name = feeName.value.trim();
  if (!name) {
    ElMessage.warning(tl('required_column'));
    return;
  }
  feeEntries.value.push({ amount: Number(feeAmount.value ?? 0), name });
  feeName.value = '';
  feeAmount.value = null;
}
function removeFeeEntry(i: number) {
  feeEntries.value.splice(i, 1);
}
/** 新增雜項總和：計入結算合計 */
const feeTotal = computed(() =>
  Number(sumAmounts(feeEntries.value.map((e) => e.amount))),
);

/** 結算合計 = 4D 佔成總和 + 一般佔成總和 + 新增雜項總和 */
const settleTotal = computed(
  () =>
    sumOf(rows4d.value, 'proportion') +
    sumOf(rowsOther.value, 'proportion') +
    feeTotal.value,
);

async function open(agentId: number | string, start: string, end: string) {
  visible.value = true;
  loading.value = true;
  rows4d.value = [];
  rowsOther.value = [];
  feeName.value = '';
  feeAmount.value = null;
  feeEntries.value = [];
  try {
    if (Object.keys(translates.value).length === 0) {
      translates.value = (await getTranslateApi().catch(() => ({}))) ?? {};
    }
    // 時間補 :00
    const params = {
      agent_id: agentId,
      end_time: toApiTime(`${end}:00`),
      start_time: toApiTime(`${start}:00`),
    };
    criteria.value = params;
    const res = await getReportSr19DetailApi(params);
    if (Number(res.code) === 1000) {
      rows4d.value = res.data?.['4D'] ?? [];
      rowsOther.value = res.data?.Other ?? [];
    }
  } finally {
    loading.value = false;
  }
}

const exporting = ref(false);
/** 匯出 Excel：欄位（12 欄；4D 段 + 合計列 + Other 段 + 合計列，同一表） */
async function handleExport() {
  exporting.value = true;
  try {
    const section = (rows: Sr19DetailRow[]) => {
      const out: Array<null | number | string | undefined>[] = rows.map((r) => [
        r.agent_username,
        r.agent_nickname,
        vendorText(r),
        r.game_type,
        Number(r.vendor_id) === 36 ? '--' : fmt(r.total_bet),
        Number(r.vendor_id) === 36 ? '--' : fmt(r.win_lose),
        fmt(r.commission),
        fmt(r.lottery),
        fmt(r.cost),
        fmt(r.v_jackpot),
        fmt(r.score),
        fmt(r.proportion),
      ]);
      if (rows.length > 0) {
        out.push([
          '',
          '',
          '',
          tl('total_count'),
          fmt(sumOf(rows, 'total_bet')),
          fmt(sumOf(rows, 'win_lose')),
          fmt(sumOf(rows, 'commission')),
          fmt(sumOf(rows, 'lottery')),
          fmt(sumOf(rows, 'cost')),
          fmt(sumOf(rows, 'v_jackpot')),
          fmt(sumOf(rows, 'score')),
          fmt(sumOf(rows, 'proportion')),
        ]);
      }
      return out;
    };
    const feeRows: Array<null | number | string | undefined>[] =
      feeEntries.value.map((e) => [
        '',
        '',
        '',
        e.name,
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        fmt(e.amount),
      ]);
    await exportExcel(
      `sr19_detail_${Date.now()}`,
      [
        tl('agent_account'),
        tl('agent_name'),
        tl('platform'),
        tl('game_type'),
        tl('total_bet'),
        tl('total_winlose'),
        tl('commission'),
        tl('jackpot_event'),
        costLabel.value,
        jackpotLabel.value,
        tl('total_score'),
        tl('proportion'),
      ],
      [...section(rows4d.value), ...section(rowsOther.value), ...feeRows],
      {
        audit: {
          // 同一頁的主表匯出各自成一筆紀錄，action 要分得開
          action: 'sr19_detail',
          conditions: criteria.value,
          // 合計列與手動雜項列不是資料列，不能算進稽核筆數
          recordCount: rows4d.value.length + rowsOther.value.length,
        },
        title: tl('detail'),
      },
    );
  } finally {
    exporting.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog v-model="visible" :title="tl('detail')" width="1100" align-center>
    <div v-loading="loading">
      <div class="mb-2 flex justify-end">
        <ElButton
          :disabled="rows4d.length === 0 && rowsOther.length === 0"
          :loading="exporting"
          size="small"
          type="success"
          @click="handleExport"
        >
          {{ tl('excel') }}
        </ElButton>
      </div>
      <!-- 4D -->
      <div class="mb-2 font-bold text-blue-600">{{ tl('4D') }}</div>
      <ElTable
        :data="rows4d"
        :show-summary="rows4d.length > 0"
        :summary-method="summaryMethod4d"
        border
        size="small"
        stripe
      >
        <ElTableColumn
          :label="tl('agent_account')"
          prop="agent_username"
          min-width="100"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_name')"
          prop="agent_nickname"
          min-width="100"
        />
        <ElTableColumn :label="tl('platform')" min-width="90">
          <template #default="{ row }">
            <span class="text-info font-bold">{{ vendorText(row) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('game_type')"
          prop="game_type"
          min-width="90"
        />
        <ElTableColumn :label="tl('total_bet')" align="right" width="120">
          <template #default="{ row }">
            <span v-if="Number(row.vendor_id) === 36">--</span>
            <span
              v-else
              :class="{ 'text-red-500': Number(row.total_bet) < 0 }"
              >{{ fmt(row.total_bet) }}</span
            >
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('total_winlose')" align="right" width="120">
          <template #default="{ row }">
            <span v-if="Number(row.vendor_id) === 36">--</span>
            <span
              v-else
              :class="{ 'text-red-500': Number(row.win_lose) < 0 }"
              >{{ fmt(row.win_lose) }}</span
            >
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('commission')" align="right" width="110">
          <template #default="{ row }">{{ fmt(row.commission) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('jackpot_event')" align="right" width="110">
          <template #default="{ row }">{{ fmt(row.lottery) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="costLabel" align="right" width="110">
          <template #default="{ row }">{{ fmt(row.cost) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="jackpotLabel" align="right" width="110">
          <template #default="{ row }">{{ fmt(row.v_jackpot) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('total_score')" align="right" width="110">
          <template #default="{ row }">{{ fmt(row.score) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('proportion')" align="right" width="110">
          <template #default="{ row }">{{ fmt(row.proportion) }}</template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>

      <!-- Other -->
      <div class="mb-2 mt-4 font-bold text-blue-600">{{ tl('game') }}</div>
      <ElTable
        :data="rowsOther"
        :show-summary="rowsOther.length > 0"
        :summary-method="summaryMethodOther"
        border
        size="small"
        stripe
      >
        <ElTableColumn
          :label="tl('agent_account')"
          prop="agent_username"
          min-width="100"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_name')"
          prop="agent_nickname"
          min-width="100"
        />
        <ElTableColumn :label="tl('platform')" min-width="90">
          <template #default="{ row }">
            <span class="text-info font-bold">{{ vendorText(row) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('game_type')"
          prop="game_type"
          min-width="90"
        />
        <ElTableColumn :label="tl('total_bet')" align="right" width="120">
          <template #default="{ row }">
            <span v-if="Number(row.vendor_id) === 36">--</span>
            <span
              v-else
              :class="{ 'text-red-500': Number(row.total_bet) < 0 }"
              >{{ fmt(row.total_bet) }}</span
            >
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('total_winlose')" align="right" width="120">
          <template #default="{ row }">
            <span v-if="Number(row.vendor_id) === 36">--</span>
            <span
              v-else
              :class="{ 'text-red-500': Number(row.win_lose) < 0 }"
              >{{ fmt(row.win_lose) }}</span
            >
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('commission')" align="right" width="110">
          <template #default="{ row }">{{ fmt(row.commission) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('jackpot_event')" align="right" width="110">
          <template #default="{ row }">{{ fmt(row.lottery) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="costLabel" align="right" width="110">
          <template #default="{ row }">{{ fmt(row.cost) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="jackpotLabel" align="right" width="110">
          <template #default="{ row }">{{ fmt(row.v_jackpot) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('total_score')" align="right" width="110">
          <template #default="{ row }">{{ fmt(row.score) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('proportion')" align="right" width="110">
          <template #default="{ row }">{{ fmt(row.proportion) }}</template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>

      <!-- 新增雜項：手動加項目/金額，計入結算合計 -->
      <div class="mt-4 flex flex-wrap items-start justify-between gap-6">
        <div class="flex flex-wrap items-end gap-2">
          <div>
            <div class="text-muted-foreground mb-1 text-xs">
              {{ tl('entry') }}
            </div>
            <ElInput v-model="feeName" class="!w-40" />
          </div>
          <div>
            <div class="text-muted-foreground mb-1 text-xs">
              {{ tl('amount') }}
            </div>
            <ElInputNumber
              v-model="feeAmount"
              :controls="false"
              class="!w-40"
            />
          </div>
          <ElButton circle size="small" type="primary" @click="addFeeEntry">
            +
          </ElButton>
        </div>
        <div class="min-w-64 flex-1">
          <div class="mb-1 text-center text-xs font-medium">
            {{ tl('add_entry') }}
          </div>
          <div
            v-if="feeEntries.length === 0"
            class="text-muted-foreground text-center text-xs"
          >
            --
          </div>
          <div
            v-for="(entry, i) in feeEntries"
            :key="i"
            class="mb-1 flex items-center justify-center gap-2 text-sm"
          >
            <span class="w-32 truncate text-right">{{ entry.name }}</span>
            <span
              class="w-28 text-right"
              :class="{ 'text-red-500': entry.amount < 0 }"
              >{{ fmt(entry.amount) }}</span
            >
            <ElButton
              circle
              size="small"
              type="danger"
              @click="removeFeeEntry(i)"
            >
              -
            </ElButton>
          </div>
        </div>
      </div>

      <!-- 結算 -->
      <div class="mt-4 flex justify-end gap-8 text-sm">
        <div>
          <b>{{ tl('4D') }}:</b> {{ fmt(sumOf(rows4d, 'proportion')) }}
        </div>
        <div>
          <b>{{ tl('game') }}:</b> {{ fmt(sumOf(rowsOther, 'proportion')) }}
        </div>
        <div>
          <b>{{ tl('settle') }}:</b> {{ fmt(settleTotal) }}
        </div>
      </div>
    </div>
  </ElDialog>
</template>
