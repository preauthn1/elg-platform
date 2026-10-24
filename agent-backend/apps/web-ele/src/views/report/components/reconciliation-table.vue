<script lang="ts" setup>
import type { VNode } from 'vue';

import type { ReconciliationRow } from '#/api';
import type { ExportAuditInfo } from '#/utils/export-audit';

import { computed, h } from 'vue';

import { ElButton, ElTable, ElTableColumn } from 'element-plus';

import AgentAccount from '#/components/agent-account.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands, buildPlainAmounts, sumAmounts } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'ReconciliationTable' });

const props = defineProps<{
  costLabel: string;
  /** total 模式且顯示=總結時，遊戲類別一律顯示「全部」 */
  gameTypeAll?: boolean;
  jackpotLabel: string;
  /** member=會員明細（前三欄不同、無下鑽） */
  mode: 'detail' | 'member' | 'total';
  payFeeLabel: string;
  rows: ReconciliationRow[];
  showCost: boolean;
  showRecycle: boolean;
  showVJackpot: boolean;
  smsFeeLabel: string;
  withdrawalFeeLabel: string;
}>();

const emit = defineEmits<{
  drill: [kind: 'agent' | 'member', username: string];
}>();

const fmt = (v?: null | number | string) => amountThousands(v, 4);

function vendorText(row: ReconciliationRow) {
  if (row.vendor === 'payfee') return props.payFeeLabel;
  if (row.vendor === 'withdrawalfee') return props.withdrawalFeeLabel;
  if (row.vendor === 'smsfee') return props.smsFeeLabel;
  return row.vendor ?? '';
}

function gameTypeText(row: ReconciliationRow) {
  if (props.gameTypeAll && row.game_type !== '-') return tl('all');
  return row.game_type;
}

const is36 = (row: ReconciliationRow) => Number(row.vendor_id) === 36;

/** 總咬分：彩金活動 +（可見欄位）+ vendor 36 用佣金、否則用輸贏 */
function biteScore(row: ReconciliationRow) {
  let s = Number(row.lottery ?? 0);
  if (props.showVJackpot) s += Number(row.v_jackpot ?? 0);
  if (props.showCost) s += Number(row.cost ?? 0);
  if (props.showRecycle) s += Number(row.recycle_amount ?? 0);
  s += is36(row) ? Number(row.commission ?? 0) : Number(row.win_lose ?? 0);
  return s;
}

const signClass = (v: number | string) =>
  Number(v) < 0 ? 'text-red-500' : 'text-blue-500';

const sums = computed(() => ({
  bet: Number(sumAmounts(props.rows.map((r) => r.total_bet))),
  commission: Number(sumAmounts(props.rows.map((r) => r.commission))),
  cost: Number(sumAmounts(props.rows.map((r) => r.cost))),
  lottery: Number(sumAmounts(props.rows.map((r) => r.lottery))),
  recycle: Number(sumAmounts(props.rows.map((r) => r.recycle_amount))),
  vJackpot: Number(sumAmounts(props.rows.map((r) => r.v_jackpot))),
  winLose: Number(sumAmounts(props.rows.map((r) => r.win_lose))),
}));

/** 表尾總計：輸贏+佣金+彩金活動（+可見欄位）；數值 number_format 4 位 */
function summaryMethod(): Array<string | VNode> {
  const s = sums.value;
  const cell = (v: number, colored = false) =>
    h(
      'b',
      { class: colored && v < 0 ? 'text-red-500' : '' },
      amountThousands(v, 4),
    ) as any;
  let bite = s.winLose + s.commission + s.lottery;
  if (props.showVJackpot) bite += s.vJackpot;
  if (props.showCost) bite += s.cost;
  if (props.showRecycle) bite += s.recycle;
  const out: Array<string | VNode> = [
    '',
    '',
    '',
    '',
    tl('total_count'),
    cell(s.bet),
    cell(s.winLose, true),
    cell(s.commission),
    cell(s.lottery),
  ];
  if (props.showRecycle) out.push(cell(s.recycle, true));
  if (props.showCost) out.push(cell(s.cost));
  if (props.showVJackpot) out.push(cell(s.vJackpot));
  out.push(cell(bite, true));
  return out;
}

/**
 * 匯出用咬分： excel（不含回收欄，與畫面 biteScore 不同）。
 */
function exportBite(row: ReconciliationRow) {
  let s = Number(row.lottery ?? 0);
  if (props.showVJackpot) s += Number(row.v_jackpot ?? 0);
  if (props.showCost) s += Number(row.cost ?? 0);
  s += is36(row) ? Number(row.commission ?? 0) : Number(row.win_lose ?? 0);
  return s;
}

/** 下階明細欄文字（vendor -2 且 self → '-'，否則 tl(lower_type)） */
function lowerTypeText(row: ReconciliationRow) {
  if (Number(row.vendor_id) === -2 && row.lower_type === 'self') return '-';
  return row.lower_type ? tl(String(row.lower_type)) : '';
}

/** 下階明細可下鑽的種類；all（總結模式加總列）等同會員下鑽（對齊舊後台） */
function drillKind(row: ReconciliationRow): 'agent' | 'member' | undefined {
  const t = String(row.lower_type ?? '');
  if (t === 'agent' || t === 'member') return t;
  if (t === 'all') return 'member';
  return undefined;
}

/** 匯出 Excel：欄位 1:1 /newsr11Member（依模式與 cost/v_jackpot 可見性動態）。 */
async function downloadExcel(
  prefix: string,
  layout?: {
    audit?: ExportAuditInfo;
    dateRange?: [string, string];
    title?: string;
  },
) {
  const tail: string[] = [];
  if (props.showCost) tail.push(props.costLabel);
  if (props.showVJackpot) tail.push(props.jackpotLabel);
  tail.push(tl('total_score'));
  const lead =
    props.mode === 'member'
      ? [tl('num'), tl('member_account'), tl('member_name')]
      : [tl('nextlevel_detail'), tl('agent_account'), tl('agent_name')];
  const headers = [
    ...lead,
    tl('platform'),
    tl('game_type'),
    tl('total_bet'),
    tl('total_winlose'),
    tl('commission'),
    tl('win_jackpot'),
    ...tail,
  ];
  const rows = buildPlainAmounts(() =>
    props.rows.map((row, i) => {
      const leadCells =
        props.mode === 'member'
          ? [i + 1, row.member_username, row.member_nickname]
          : [lowerTypeText(row), row.agent_username, row.agent_nickname];
      const cells: Array<null | number | string | undefined> = [
        ...leadCells,
        vendorText(row),
        gameTypeText(row),
        is36(row) ? '--' : fmt(row.total_bet),
        is36(row) ? '--' : fmt(row.win_lose),
        fmt(row.commission),
        fmt(row.lottery),
      ];
      if (props.showCost) cells.push(fmt(row.cost));
      if (props.showVJackpot) cells.push(fmt(row.v_jackpot));
      cells.push(fmt(exportBite(row)));
      return cells;
    }),
  );
  await exportExcel(`${prefix}_${Date.now()}`, headers, rows, layout);
}

defineExpose({ downloadExcel });
</script>

<template>
  <ElTable
    :data="rows"
    :show-summary="rows.length > 0"
    :summary-method="summaryMethod"
    border
    size="small"
    stripe
  >
    <template v-if="mode === 'member'">
      <ElTableColumn :label="tl('num')" type="index" width="60" />
      <ElTableColumn :label="tl('member_account')" min-width="110">
        <template #default="{ row }">
          <MemberAccountLink :username="row.member_username" />
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('member_name')"
        prop="member_nickname"
        min-width="100"
      />
    </template>
    <template v-else>
      <ElTableColumn :label="tl('nextlevel_detail')" width="90">
        <template #default="{ row }">
          <span v-if="!drillKind(row)"> - </span>
          <ElButton
            v-else
            link
            type="primary"
            @click="
              emit(
                'drill',
                drillKind(row) as 'agent' | 'member',
                String(row.agent_username ?? ''),
              )
            "
          >
            {{ tl(row.lower_type) }}
          </ElButton>
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
      <ElTableColumn
        :label="tl('agent_name')"
        prop="agent_nickname"
        min-width="100"
      />
    </template>

    <ElTableColumn :label="tl('platform')" min-width="100">
      <template #default="{ row }">
        <span class="text-info font-bold">{{ vendorText(row) }}</span>
      </template>
    </ElTableColumn>
    <ElTableColumn :label="tl('game_type')" min-width="90">
      <template #default="{ row }">{{ gameTypeText(row) }}</template>
    </ElTableColumn>
    <ElTableColumn :label="tl('total_bet')" align="right" width="120">
      <template #default="{ row }">
        <span v-if="is36(row)">--</span>
        <span v-else>{{ fmt(row.total_bet) }}</span>
      </template>
    </ElTableColumn>
    <ElTableColumn :label="tl('total_winlose')" align="right" width="120">
      <template #default="{ row }">
        <span v-if="is36(row)">--</span>
        <span v-else :class="signClass(row.win_lose ?? 0)">
          {{ fmt(row.win_lose) }}
        </span>
      </template>
    </ElTableColumn>
    <ElTableColumn :label="tl('commission')" align="right" width="110">
      <template #default="{ row }">
        <span :class="signClass(row.commission ?? 0)">
          {{ fmt(row.commission) }}
        </span>
      </template>
    </ElTableColumn>
    <ElTableColumn :label="tl('jackpot_event')" align="right" width="110">
      <template #default="{ row }">{{ fmt(row.lottery) }}</template>
    </ElTableColumn>
    <ElTableColumn
      v-if="showRecycle"
      :label="tl('recycle_amount')"
      align="right"
      width="110"
    >
      <template #default="{ row }">
        <span :class="{ 'text-red-500': Number(row.recycle_amount) < 0 }">
          {{ fmt(row.recycle_amount) }}
        </span>
      </template>
    </ElTableColumn>
    <ElTableColumn v-if="showCost" :label="costLabel" align="right" width="110">
      <template #default="{ row }">
        <span :class="signClass(row.cost ?? 0)">{{ fmt(row.cost) }}</span>
      </template>
    </ElTableColumn>
    <ElTableColumn
      v-if="showVJackpot"
      :label="jackpotLabel"
      align="right"
      width="110"
    >
      <template #default="{ row }">{{ fmt(row.v_jackpot) }}</template>
    </ElTableColumn>
    <ElTableColumn :label="tl('total_score')" align="right" width="120">
      <template #default="{ row }">
        <span :class="signClass(biteScore(row))">
          {{ fmt(biteScore(row)) }}
        </span>
      </template>
    </ElTableColumn>
    <template #empty>{{ tl('no_record') }}</template>
  </ElTable>
</template>
