<script lang="ts" setup>
import type {
  BehaviorBonusDetail,
  BehaviorDetailCell,
  BehaviorRow,
  BehaviorTotals,
} from '#/api';

import { h, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getAgentViewByIdApi,
  getMemberBehaviorDataApi,
  getMemberBehaviorDetailApi,
  getMemberBehaviorTotalApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useExcelExport } from '#/composables/use-excel-export';
import { tl } from '#/locales';
import { amountThousands, floorAmount, sumAmounts } from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'MemberBehaviorData' });

const PER_PAGE = 30;

const userStore = useUserStore();

const filters = reactive({
  hidden_zero_data: 1,
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
  username: '',
  username_type: 1,
});

const loading = ref(false);
const searched = ref(false);
const totals = ref<BehaviorTotals>({});
const list = ref<BehaviorRow[]>([]);
const total = ref(0);
const page = ref(1);

const fmt4 = (v?: null | number | string) => amountThousands(v, 4);

function fmtPlain4(v: number) {
  return floorAmount(v, 4).toFixed(4);
}

/** 當頁合計 */
function pageSum(key: keyof BehaviorTotals) {
  return Number(sumAmounts(list.value.map((r) => r[key])));
}

/** ElTable 合計列：跟隨欄位寬度自動對齊（對齊舊版 tbody 合計 tr） */
function summaryMethod({ columns }: { columns: any[] }) {
  const diff = pageSum('total_deposit') - pageSum('total_withdrawal');
  return columns.map((_col, i) => {
    switch (i) {
      case 0: {
        return tl('total');
      }
      case 3: {
        return fmt4(pageSum('total_deposit'));
      }
      case 4: {
        return fmt4(pageSum('total_withdrawal'));
      }
      case 5: {
        return h('span', { class: 'text-red-500' }, fmt4(diff));
      }
      case 6: {
        return fmt4(pageSum('total_bonus'));
      }
      case 7: {
        return fmt4(pageSum('total_rebate'));
      }
      case 8: {
        return fmt4(pageSum('total_bet'));
      }
      case 9: {
        return fmt4(pageSum('total_winlose'));
      }
      default: {
        return '';
      }
    }
  });
}

async function loadData(withTotals = true) {
  const [start, end] = filters.time_range ?? [];
  if (!filters.username || !start || !end) {
    ElMessage.warning(tl('required_column'));
    return;
  }
  loading.value = true;
  searched.value = true;
  try {
    const base = {
      end_time: toApiTime(end),
      search_username: filters.username,
      start_time: toApiTime(start),
      username_type: filters.username_type,
    };
    const [totalRes, listRes] = await Promise.all([
      withTotals ? getMemberBehaviorTotalApi(base) : Promise.resolve(null),
      getMemberBehaviorDataApi({
        ...base,
        hidden_zero_data: filters.hidden_zero_data,
        page: page.value,
        per_page: PER_PAGE,
      }),
    ]);
    if (totalRes) {
      totals.value =
        Number(totalRes.code) === 1000 ? (totalRes.data?.[0] ?? {}) : {};
    }
    if (Number(listRes.code) === 1000) {
      list.value = listRes.data ?? [];
      total.value = listRes.pagination?.total ?? 0;
    } else {
      list.value = [];
      total.value = 0;
      ElMessage.warning(listRes.message);
    }
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadData(true);
}

function handlePageChange(p: number) {
  page.value = p;
  loadData(false);
}

const { exporting, runExport } = useExcelExport();
/** 匯出 Excel：條件同查詢（帳號、日期必填） */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!filters.username || !start || !end) {
    ElMessage.warning(tl('required_column'));
    return;
  }
  const params = {
    end_time: toApiTime(end),
    hidden_zero_data: filters.hidden_zero_data,
    page: 1,
    per_page: 100_000,
    search_username: filters.username,
    start_time: toApiTime(start),
    username_type: filters.username_type,
  };
  await runExport(
    () => getMemberBehaviorDataApi(params),
    `member_behavior_data_${Date.now()}`,
    [
      tl('createdate'),
      tl('agent_account'),
      tl('member_account'),
      tl('deposit_amount'),
      tl('withdrawal_amount'),
      tl('deposit_withdrawal_difference'),
      tl('bind_reward_point'),
      `${tl('rebate')}${tl('amount')}`,
      tl('betting_amount'),
      tl('win_lose'),
    ],
    (r) => [
      fromApiTime(r.created_at),
      r.agent_username,
      r.member_username,
      r.total_deposit,
      r.total_withdrawal,
      Number(r.total_deposit ?? 0) - Number(r.total_withdrawal ?? 0),
      r.total_bonus,
      r.total_rebate,
      r.total_bet,
      r.total_winlose,
    ],
    {
      audit: { conditions: params },
      dateRange: [start, end],
      title: tl('menu_member_behavior_data'),
    },
  );
}

/* ---- 明細彈窗 ---- */
const detailVisible = ref(false);
const detailLoading = ref(false);
const detailKind = ref('');
const detailUsername = ref('');
const detailRows = ref<BehaviorBonusDetail | BehaviorDetailCell[] | null>(null);
const detailError = ref('');

/** 可點擊明細：deposit/withdrawal/bonus/rebate 且值 > 0 */
function clickable(v?: null | number | string) {
  return Number(v ?? 0) > 0;
}

async function openDetail(
  kind: string,
  username: string,
  memberId: number | string,
) {
  const [start, end] = filters.time_range ?? [];
  detailVisible.value = true;
  detailLoading.value = true;
  detailKind.value = kind;
  detailUsername.value = username;
  detailRows.value = null;
  detailError.value = '';
  try {
    const res = await getMemberBehaviorDetailApi({
      end_time: toApiTime(end ?? ''),
      member_id: memberId,
      search_username: filters.username,
      start_time: toApiTime(start ?? ''),
      total_kind: kind,
      username_type: filters.username_type,
    });
    if (Number(res.code) === 1000) {
      detailRows.value = res.data ?? null;
    } else {
      detailError.value = res.message;
    }
  } finally {
    detailLoading.value = false;
  }
}

interface DetailLine {
  amount?: string;
  header?: boolean;
  indent?: boolean;
  label: string;
}

/** 將明細整理成顯示列 */
function detailLines(): { lines: DetailLine[]; sum: number } {
  const rows = detailRows.value;
  const lines: DetailLine[] = [];
  const amounts: Array<null | number | string | undefined> = [];
  if (!rows) return { lines, sum: 0 };
  const pushCell = (
    cell: BehaviorDetailCell,
    labelOf: (t: string) => string,
    indent = false,
  ) => {
    const amt = Number(cell.amount ?? 0);
    amounts.push(cell.amount);
    if (amt > 0) {
      lines.push({
        amount: fmtPlain4(amt),
        indent,
        label: labelOf(String(cell.type ?? '')),
      });
    }
  };
  if (Array.isArray(rows)) {
    // deposit/withdrawal：lang[type] ?? type；rebate：lang[type]
    for (const cell of rows) pushCell(cell, (t) => tl(t));
  } else {
    const bonus = rows;
    const comp = bonus.compensate?.[0];
    if (comp && Number(comp.amount ?? 0) > 0) {
      pushCell(comp, () => tl('compensate'));
    }
    if ((bonus.event ?? []).length > 0) {
      lines.push({ header: true, label: tl('event_reward') });
      for (const cell of bonus.event ?? []) pushCell(cell, (t) => t, true);
    }
    if ((bonus.vip ?? []).length > 0) {
      lines.push({ header: true, label: tl('transaction_type.32') });
      for (const cell of bonus.vip ?? [])
        pushCell(cell, (t) => tl(`vip_reward_id_${t}`), true);
    }
    if ((bonus.mission ?? []).length > 0) {
      lines.push({ header: true, label: tl('rolling_chip_type.25') });
      for (const cell of bonus.mission ?? []) pushCell(cell, (t) => t, true);
    }
    for (const cell of bonus.other_reward ?? []) {
      pushCell(cell, (t) => tl(t));
    }
  }
  return { lines, sum: Number(sumAmounts(amounts)) };
}

onMounted(async () => {
  // 帳號預設為本人（總代 host_id=0）或上層代理；不自動查詢
  const info = userStore.userInfo as any;
  if (Number(info?.host_id) === 0) {
    filters.username = info?.username ?? '';
  } else if (info?.host_id) {
    const parent = await getAgentViewByIdApi(Number(info.host_id)).catch(
      () => null,
    );
    filters.username = (parent as any)?.username ?? info?.username ?? '';
  }
});
</script>

<template>
  <Page :title="tl('menu_member_behavior_data')">
    <!-- 查詢 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem>
            <template #label>
              <ElSelect v-model="filters.username_type" class="!w-36">
                <ElOption :label="tl('agent_account')" :value="1" />
                <ElOption :label="tl('member_account')" :value="2" />
                <ElOption :label="tl('agent_line')" :value="3" />
              </ElSelect>
            </template>
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <div class="w-full">
              <DateRangeFields
                v-model="filters.time_range"
                format="YYYY-MM-DD HH:mm"
                type="datetime"
                value-format="YYYY-MM-DD HH:mm"
              />
              <div class="text-muted-foreground mt-1 text-xs">
                {{ tl('query_period_max_days').replace('{value}', '60') }}
              </div>
            </div>
          </ElFormItem>
          <ElFormItem :label="tl('bet_is_zero')">
            <ElRadioGroup v-model="filters.hidden_zero_data">
              <ElRadio :value="1">{{ tl('hidden') }}</ElRadio>
              <ElRadio :value="0">{{ tl('show') }}</ElRadio>
            </ElRadioGroup>
          </ElFormItem>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('excel') }}
          </ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <!-- 合計卡 -->
    <ElCard class="mb-3" shadow="never">
      <div
        class="grid grid-cols-2 gap-y-4 text-center md:grid-cols-4 xl:grid-cols-7"
      >
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('total_deposit') }}
          </div>
          <div
            :class="
              clickable(totals.total_deposit)
                ? 'cursor-pointer text-blue-500 underline'
                : ''
            "
            class="text-lg font-semibold"
            @click="
              clickable(totals.total_deposit) &&
              openDetail('deposit', filters.username, 0)
            "
          >
            {{ fmt4(totals.total_deposit ?? 0) }}
          </div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('total_withdrawl') }}
          </div>
          <div
            :class="
              clickable(totals.total_withdrawal)
                ? 'cursor-pointer text-blue-500 underline'
                : ''
            "
            class="text-lg font-semibold"
            @click="
              clickable(totals.total_withdrawal) &&
              openDetail('withdrawal', filters.username, 0)
            "
          >
            {{ fmt4(totals.total_withdrawal ?? 0) }}
          </div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('deposit_withdrawal_difference') }}
          </div>
          <div class="text-lg font-semibold">
            {{
              fmt4(
                Number(totals.total_deposit ?? 0) -
                  Number(totals.total_withdrawal ?? 0),
              )
            }}
          </div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('total_reward') }}
          </div>
          <div
            :class="
              clickable(totals.total_bonus)
                ? 'cursor-pointer text-blue-500 underline'
                : ''
            "
            class="text-lg font-semibold"
            @click="
              clickable(totals.total_bonus) &&
              openDetail('bonus', filters.username, 0)
            "
          >
            {{ fmt4(totals.total_bonus ?? 0) }}
          </div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('total_rebate') }}
          </div>
          <div
            :class="
              clickable(totals.total_rebate)
                ? 'cursor-pointer text-blue-500 underline'
                : ''
            "
            class="text-lg font-semibold"
            @click="
              clickable(totals.total_rebate) &&
              openDetail('rebate', filters.username, 0)
            "
          >
            {{ fmt4(totals.total_rebate ?? 0) }}
          </div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">{{ tl('total_bet') }}</div>
          <div class="text-lg font-semibold">
            {{ fmt4(totals.total_bet ?? 0) }}
          </div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('total_winlose') }}
          </div>
          <div class="text-lg font-semibold">
            {{ fmt4(totals.total_winlose ?? 0) }}
          </div>
        </div>
      </div>
    </ElCard>

    <!-- 列表（查詢後顯示） -->
    <ElCard v-if="searched" shadow="never">
      <ElTable
        v-loading="loading"
        :data="list"
        :summary-method="summaryMethod"
        border
        show-summary
        size="small"
        stripe
      >
        <ElTableColumn
          :formatter="(row: BehaviorRow) => fromApiTime(row.created_at)"
          :label="tl('createdate')"
          width="120"
        />
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
        <ElTableColumn :label="tl('deposit_amount')" align="right" width="120">
          <template #default="{ row }">
            <span
              v-if="clickable(row.total_deposit)"
              class="cursor-pointer text-blue-500 underline"
              @click="openDetail('deposit', row.member_username, row.member_id)"
            >
              {{ row.total_deposit }}
            </span>
            <span v-else>{{ row.total_deposit }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('withdrawal_amount')"
          align="right"
          width="120"
        >
          <template #default="{ row }">
            <span
              v-if="clickable(row.total_withdrawal)"
              class="cursor-pointer text-blue-500 underline"
              @click="
                openDetail('withdrawal', row.member_username, row.member_id)
              "
            >
              {{ row.total_withdrawal }}
            </span>
            <span v-else>{{ row.total_withdrawal }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('deposit_withdrawal_difference')"
          align="right"
          width="120"
        >
          <template #default="{ row }">
            <span class="text-red-500">
              {{
                fmtPlain4(
                  Number(row.total_deposit ?? 0) -
                    Number(row.total_withdrawal ?? 0),
                )
              }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('bind_reward_point')"
          align="right"
          width="120"
        >
          <template #default="{ row }">
            <span
              v-if="clickable(row.total_bonus)"
              class="cursor-pointer text-blue-500 underline"
              @click="openDetail('bonus', row.member_username, row.member_id)"
            >
              {{ row.total_bonus }}
            </span>
            <span v-else>{{ row.total_bonus }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('rebate')}${tl('amount')}`"
          align="right"
          width="120"
        >
          <template #default="{ row }">
            <span
              v-if="clickable(row.total_rebate)"
              class="cursor-pointer text-blue-500 underline"
              @click="openDetail('rebate', row.member_username, row.member_id)"
            >
              {{ row.total_rebate }}
            </span>
            <span v-else>{{ row.total_rebate }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('betting_amount')" align="right" width="120">
          <template #default="{ row }">{{ fmt4(row.total_bet) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('win_lose')" align="right" width="120">
          <template #default="{ row }">{{ fmt4(row.total_winlose) }}</template>
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

    <!-- 明細彈窗 -->
    <ElDialog v-model="detailVisible" width="700" align-center>
      <div v-loading="detailLoading">
        <h4 class="mb-3 text-center font-bold">
          {{ tl(detailKind) }}{{ tl('detail') }}—{{ detailUsername }}
        </h4>
        <div v-if="detailError" class="py-4 text-center text-red-500">
          {{ detailError }}
        </div>
        <template v-else-if="detailRows">
          <template v-if="detailLines().lines.length > 0">
            <div
              v-for="(line, i) in detailLines().lines"
              :key="i"
              class="border-b py-1.5"
            >
              <div class="flex items-center justify-between">
                <span :class="line.indent ? 'pl-4' : ''">
                  {{ line.indent ? 'ㄴ' : '' }}{{ line.label }}
                </span>
                <span v-if="line.amount">{{ line.amount }}</span>
              </div>
            </div>
          </template>
          <p v-else class="py-4 text-center">{{ tl('no_data') }}</p>
          <div
            class="flex items-center justify-between py-2 font-medium text-green-600"
          >
            <span>{{ tl('summary') }}</span>
            <span>{{ fmtPlain4(detailLines().sum) }}</span>
          </div>
        </template>
        <p v-else-if="!detailLoading" class="py-4 text-center">
          {{ tl('no_data') }}
        </p>
      </div>
    </ElDialog>
  </Page>
</template>
