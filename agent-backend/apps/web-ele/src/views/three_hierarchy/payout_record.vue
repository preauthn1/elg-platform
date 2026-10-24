<script lang="ts" setup>
import type {
  ThreeHierarchyPayoutDetailRow,
  ThreeHierarchyPayoutRow,
  ThreeHierarchyPayoutSummary,
} from '#/api';

import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElLink,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import {
  getThreeHierarchyPayoutRecordApi,
  getThreeHierarchyPayoutRecordDetailApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'ThreeHierarchyPayoutRecord' });

const PER_PAGE = 20;
const DETAIL_PER_PAGE = 10;
/** 工單4407：匯出金額欄的 Excel 數字格式（保留 4 位，與後端原始值同位數） */
const AMOUNT_FMT = '0.0000';

const router = useRouter();

const filters = reactive({
  agent: '',
  identity: '',
  member: '',
  time_range: [
    dayjs().format('YYYY-MM-DD 00:00'),
    dayjs().format('YYYY-MM-DD 23:59'),
  ] as string[],
});

const summary = ref<ThreeHierarchyPayoutSummary>({});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<ThreeHierarchyPayoutRow>(
    ({ page }) => {
      const [start, end] = filters.time_range ?? [];
      return getThreeHierarchyPayoutRecordApi({
        agent: filters.agent,
        end_time: end ? `${end}:59` : '',
        identity: filters.identity,
        member: filters.member,
        page,
        start_time: start ? `${start}:00` : '',
      });
    },
    {
      guard: () => {
        const [start, end] = filters.time_range ?? [];
        return Boolean(start && end);
      },
      onLoaded: (res) => {
        // apis 後端成功碼 200（4199 起由 API_Agent 移植）
        summary.value =
          Number(res.code) === 200
            ? ((res as { summary?: ThreeHierarchyPayoutSummary }).summary ?? {})
            : {};
      },
      perPage: PER_PAGE,
    },
  );

/** 工單4199：金額小數位 4 → 2 */
const fmt = (v?: null | number | string) => amountThousands(v ?? 0, 2);

/** 計算基數：null＝混到輸分佔成資料（防呆），顯示「混合，見明細」 */
const calcBaseText = (v?: null | string) =>
  v === null || v === undefined ? tl('th_mixed_see_detail') : fmt(v);

/** 工單4407：匯出用 4 位原始值、數值型別（頁面顯示仍走 fmt 捨去到 2 位） */
const amountCell = (v?: null | number | string) => {
  const n = Number(v ?? 0);
  return Number.isFinite(n) ? n : 0;
};

/** 工單4407：匯出的計算基數欄——混到輸分佔成時無單一基數，維持文字 */
const calcBaseCell = (v?: null | string) => {
  if (v === null || v === undefined) return tl('th_mixed_see_detail');
  return amountCell(v);
};

const memberCard = computed(() => summary.value.member);
const agentCard = computed(() => summary.value.agent);

const title = computed(
  () =>
    `${tl('menu_reward_mode_management')} > ${tl('menu_three_layers_agents_mode_including_agent')} > ${tl('menu_three_layers_payout_record')}`,
);

function handleReset() {
  filters.agent = '';
  filters.identity = '';
  filters.member = '';
  filters.time_range = [
    dayjs().format('YYYY-MM-DD 00:00'),
    dayjs().format('YYYY-MM-DD 23:59'),
  ];
  search();
}

/** 提現單號（＝withdraw_logs.id）跳轉提現紀錄頁；期間帶派發日起 7 天（收據時間必在派發之後） */
function goWithdrawRecord(row: ThreeHierarchyPayoutRow) {
  if (!row.withdraw_log_id) return;
  const payoutDay = dayjs(row.created_at).format('YYYY-MM-DD');
  router.push({
    path: '/three_hierarchy/withdrawal_record',
    query: {
      end_time: dayjs(payoutDay).add(7, 'day').format('YYYY-MM-DD 23:59'),
      order_no: String(row.withdraw_log_id),
      start_time: `${payoutDay} 00:00`,
    },
  });
}

// ── 下鑽明細彈窗（工單4199 場景 F：源頭粒度，與前台「我的業績」同拆解） ──
const detailVisible = ref(false);
const detailLoading = ref(false);
const detailRow = ref<null | ThreeHierarchyPayoutRow>(null);
const detailList = ref<ThreeHierarchyPayoutDetailRow[]>([]);
const detailTotal = ref(0);
const detailPage = ref(1);
const detailSummary = ref<{
  base?: string;
  by_rebate_type?: Array<{
    base?: string;
    rebate_type?: number;
    reward?: string;
  }>;
  reward?: string;
}>({});

/** 混到輸分佔成（rebate_type=2）時明細加「反水類別」欄與分類小計 */
const detailMixed = computed(
  () =>
    (detailSummary.value.by_rebate_type?.length ?? 0) > 1 ||
    detailSummary.value.by_rebate_type?.some((t) => t.rebate_type === 2),
);

const tierText = (tier?: null | string) => {
  if (tier === 'self') return tl('th_tier_self');
  if (tier === 'down') return tl('th_tier_down');
  if (tier === 'down2') return tl('th_tier_down2');
  return '—';
};

const rebateTypeText = (t?: number) =>
  t === 2 ? tl('th_rebate_type_lose') : tl('th_rebate_type_bet');

/** 比例＝返利 ÷ 基數 反算（後端不存佔成快照）；基數 0 無法反算顯示「—」 */
function proportionText(row: ThreeHierarchyPayoutDetailRow) {
  const base = Number(row.base ?? 0);
  const reward = Number(row.reward ?? 0);
  if (!base) return '—';
  return `${((reward / base) * 100).toFixed(2)}%`;
}

async function loadDetail() {
  if (!detailRow.value) return;
  detailLoading.value = true;
  try {
    const res = await getThreeHierarchyPayoutRecordDetailApi({
      id: detailRow.value.target_id ?? 0,
      identity: detailRow.value.identity ?? 'member',
      page: detailPage.value,
      per_page: DETAIL_PER_PAGE,
      summary_time: detailRow.value.summary_time ?? '',
    });
    if (Number(res.code) === 200) {
      detailList.value = res.data;
      detailTotal.value = res.pagination?.total ?? 0;
      detailSummary.value = res.summary ?? {};
    } else {
      detailList.value = [];
      detailTotal.value = 0;
      detailSummary.value = {};
    }
  } finally {
    detailLoading.value = false;
  }
}

function openDetail(row: ThreeHierarchyPayoutRow) {
  detailRow.value = row;
  detailPage.value = 1;
  detailVisible.value = true;
  loadDetail();
}

function handleDetailPageChange(p: number) {
  detailPage.value = p;
  loadDetail();
}

const exporting = ref(false);
/**
 * 匯出 Excel：抓全部符合的列（欄序與畫面一致，含新增欄位）。
 * 工單4407：金額欄 4 位數值、檔尾附會員／代理合計列（取後端 summary）。
 */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) return;
  exporting.value = true;
  try {
    const params = {
      agent: filters.agent,
      end_time: `${end}:59`,
      identity: filters.identity,
      member: filters.member,
      page: 1,
      per_page: 100_000,
      start_time: `${start}:00`,
    };
    const res = await getThreeHierarchyPayoutRecordApi(params);
    const rows = Number(res.code) === 200 ? (res.data ?? []) : [];
    const s: ThreeHierarchyPayoutSummary =
      Number(res.code) === 200 ? (res.summary ?? {}) : {};
    // 工單4407：與後端 effectiveIdentity 同口徑——會員帳號非空時鎖會員，只出會員合計
    const effectiveIdentity = filters.member ? 'member' : filters.identity;

    const dataRows = rows.map((r) => [
      r.created_at,
      r.summary_time,
      r.identity === 'agent' ? tl('agent') : tl('member'),
      r.username,
      calcBaseCell(r.calc_base),
      amountCell(r.rebate),
      amountCell(r.down_rebate),
      amountCell(r.down2_rebate),
      amountCell(r.reward),
      r.identity === 'agent'
        ? tl('th_schedule_payout')
        : (r.withdraw_log_id ?? '—'),
    ]);

    const summaryRows: (number | string)[][] = [];
    if (effectiveIdentity !== 'agent') {
      summaryRows.push([
        `${tl('th_member_summary')}（${tl('th_member_count')}: ${s.member?.member_count ?? 0}）`,
        '',
        '',
        '',
        '—',
        amountCell(s.member?.rebate),
        amountCell(s.member?.down_rebate),
        amountCell(s.member?.down2_rebate),
        amountCell(s.member?.reward),
        '',
      ]);
    }
    if (effectiveIdentity !== 'member') {
      summaryRows.push([
        `${tl('th_agent_summary')}（${tl('agent_count')}: ${s.agent?.agent_count ?? 0}）`,
        '',
        '',
        '',
        '—',
        amountCell(s.agent?.rebate),
        amountCell(s.agent?.down_rebate),
        amountCell(s.agent?.down2_rebate),
        amountCell(s.agent?.reward),
        '',
      ]);
    }

    await exportExcel({
      // 稽核筆數只算資料列，不含檔尾合計列
      audit: { conditions: params, recordCount: rows.length },
      columns: [
        tl('th_payout_time'),
        tl('th_settle_period'),
        tl('identity'),
        tl('username'),
        { numFmt: AMOUNT_FMT, title: tl('th_calc_base') },
        { numFmt: AMOUNT_FMT, title: tl('self_rebate') },
        { numFmt: AMOUNT_FMT, title: tl('down_rebate') },
        { numFmt: AMOUNT_FMT, title: tl('down2_rebate') },
        { numFmt: AMOUNT_FMT, title: tl('th_rebate_total') },
        tl('withdraw_no'),
      ],
      dateRange: [start, end],
      filename: `payout_record_${Date.now()}`,
      rows: [...dataRows, ...summaryRows],
      title: tl('menu_three_layers_payout_record'),
      tzOffset: 8,
    });
  } finally {
    exporting.value = false;
  }
}

loadData();
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.agent" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.member" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('identity')">
            <ElSelect v-model="filters.identity" class="w-full">
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('member')" value="member" />
              <ElOption :label="tl('agent')" value="agent" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('between')" class="md:col-span-2">
            <DateRangeFields
              v-model="filters.time_range"
              :fixed-offset="8"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
            <FixedTimezoneHint />
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="handleReset">{{ tl('clear') }}</ElButton>
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('excel') }}
          </ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <!-- 工單4199：統計卡由 1 組拆 2 組（會員合計／代理合計），與列表同一組篩選條件 -->
    <div class="mb-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
      <ElCard shadow="never">
        <template #header>
          <span class="font-medium">{{ tl('th_member_summary') }}</span>
        </template>
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-5">
          <div>
            <div class="text-xs opacity-60">{{ tl('self_rebate') }}</div>
            <div class="text-lg font-bold">{{ fmt(memberCard?.rebate) }}</div>
          </div>
          <div>
            <div class="text-xs opacity-60">{{ tl('down_rebate') }}</div>
            <div class="text-lg font-bold">
              {{ fmt(memberCard?.down_rebate) }}
            </div>
          </div>
          <div>
            <div class="text-xs opacity-60">{{ tl('down2_rebate') }}</div>
            <div class="text-lg font-bold">
              {{ fmt(memberCard?.down2_rebate) }}
            </div>
          </div>
          <div>
            <div class="text-xs opacity-60">{{ tl('th_rebate_total') }}</div>
            <div class="text-lg font-bold">{{ fmt(memberCard?.reward) }}</div>
          </div>
          <div>
            <div class="text-xs opacity-60">{{ tl('th_member_count') }}</div>
            <div class="text-lg font-bold">
              {{ memberCard?.member_count ?? 0 }}
            </div>
          </div>
        </div>
      </ElCard>
      <ElCard shadow="never">
        <template #header>
          <span class="font-medium">{{ tl('th_agent_summary') }}</span>
        </template>
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-5">
          <div>
            <div class="text-xs opacity-60">{{ tl('self_rebate') }}</div>
            <div class="text-lg font-bold">{{ fmt(agentCard?.rebate) }}</div>
          </div>
          <div>
            <div class="text-xs opacity-60">{{ tl('down_rebate') }}</div>
            <div class="text-lg font-bold">
              {{ fmt(agentCard?.down_rebate) }}
            </div>
          </div>
          <div>
            <div class="text-xs opacity-60">{{ tl('down2_rebate') }}</div>
            <div class="text-lg font-bold">
              {{ fmt(agentCard?.down2_rebate) }}
            </div>
          </div>
          <div>
            <div class="text-xs opacity-60">{{ tl('th_rebate_total') }}</div>
            <div class="text-lg font-bold">{{ fmt(agentCard?.reward) }}</div>
          </div>
          <div>
            <div class="text-xs opacity-60">{{ tl('agent_count') }}</div>
            <div class="text-lg font-bold">
              {{ agentCard?.agent_count ?? 0 }}
            </div>
          </div>
        </div>
      </ElCard>
    </div>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('th_payout_time')"
          prop="created_at"
          min-width="150"
        />
        <ElTableColumn
          :label="tl('th_settle_period')"
          align="center"
          prop="summary_time"
          min-width="110"
        />
        <ElTableColumn :label="tl('identity')" align="center" min-width="80">
          <template #default="{ row }">
            <ElTag :type="row.identity === 'agent' ? 'warning' : 'primary'">
              {{ row.identity === 'agent' ? tl('agent') : tl('member') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('username')" min-width="120">
          <template #default="{ row }">
            <!-- 工單4274：派發紀錄同時列會員與代理，只有會員列可點開詳情抽屜（4199 新版以 identity 分辨） -->
            <MemberAccountLink
              v-if="row.identity !== 'agent'"
              :username="row.username"
            />
            <AgentAccount v-else :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('th_calc_base')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            {{ calcBaseText(row.calc_base) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('self_rebate')" align="right" min-width="110">
          <template #default="{ row }">{{ fmt(row.rebate) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('down_rebate')" align="right" min-width="110">
          <template #default="{ row }">{{ fmt(row.down_rebate) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('down2_rebate')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">{{ fmt(row.down2_rebate) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('th_rebate_total')"
          align="right"
          min-width="110"
        >
          <template #default="{ row }">
            <span class="font-semibold">{{ fmt(row.reward) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('withdraw_no')"
          align="center"
          min-width="130"
        >
          <template #default="{ row }">
            <span v-if="row.identity === 'agent'" class="text-xs opacity-50">
              {{ tl('th_schedule_payout') }}
            </span>
            <ElLink
              v-else-if="row.withdraw_log_id"
              type="primary"
              @click="goWithdrawRecord(row)"
            >
              {{ tl('withdraw_no') }} #{{ row.withdraw_log_id }}
            </ElLink>
            <span v-else>—</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('operate')" align="center" min-width="80">
          <template #default="{ row }">
            <ElLink type="primary" @click="openDetail(row)">
              {{ tl('detail') }}
            </ElLink>
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

    <!-- 工單4199：下鑽明細——源頭粒度，明細合計＝主表該列（可驗算） -->
    <ElDialog
      v-model="detailVisible"
      :title="`${detailRow?.username ?? ''} ｜ ${tl('th_settle_period')} ${detailRow?.summary_time ?? ''} ｜ ${tl('th_rebate_total')} ${fmt(detailRow?.reward)}`"
      width="860px"
    >
      <ElTable
        v-loading="detailLoading"
        :data="detailList"
        border
        size="small"
        stripe
      >
        <ElTableColumn :label="tl('th_from_member')" min-width="110">
          <template #default="{ row }">{{ row.from_username ?? '—' }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('th_tier')" align="center" min-width="80">
          <template #default="{ row }">{{ tierText(row.tier) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('vendor')" min-width="120">
          <template #default="{ row }">{{ row.vendor_name ?? '—' }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('game_type')" min-width="100">
          <template #default="{ row }">
            {{ row.game_type_name ?? '—' }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          v-if="detailMixed"
          :label="tl('th_rebate_type')"
          align="center"
          min-width="90"
        >
          <template #default="{ row }">
            {{ rebateTypeText(row.rebate_type) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('th_base')" align="right" min-width="110">
          <template #default="{ row }">{{ fmt(row.base) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('proportion')" align="right" min-width="80">
          <template #default="{ row }">{{ proportionText(row) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('reward')" align="right" min-width="100">
          <template #default="{ row }">{{ fmt(row.reward) }}</template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <div class="mt-2 flex justify-between text-sm">
        <div v-if="detailMixed" class="opacity-70">
          <span
            v-for="t in detailSummary.by_rebate_type"
            :key="t.rebate_type"
            class="mr-4"
          >
            {{ rebateTypeText(t.rebate_type) }}：{{ fmt(t.base) }} /
            {{ fmt(t.reward) }}
          </span>
        </div>
        <div class="ml-auto flex gap-4 font-semibold">
          <span>{{ tl('total') }}</span>
          <span>{{ fmt(detailSummary.base) }}</span>
          <span>{{ fmt(detailSummary.reward) }}</span>
        </div>
      </div>
      <TablePagination
        :current-page="detailPage"
        :page-size="DETAIL_PER_PAGE"
        :total="detailTotal"
        @current-change="handleDetailPageChange"
      />
    </ElDialog>
  </Page>
</template>
