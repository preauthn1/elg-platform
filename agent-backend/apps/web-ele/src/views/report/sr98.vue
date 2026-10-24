<script lang="ts" setup>
import type { CashFlowForTpRow, ReportSummary, SrChargeRow } from '#/api';

import { onMounted, reactive, ref, watch } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElOptionGroup,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getAgentOrderTypesApi,
  getCashFlowsForTpApi,
  getCurrencyListApi,
  getReportSr98Api,
  getWithdrawalSubListApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands, looseThousands } from '#/utils/format';
import {
  buildSourceGroups,
  isDepositSource,
  isWithdrawalSource,
  splitSourceTemp,
} from '#/utils/report-source';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'ReportSr98' });

const PER_PAGE = 20;

const TYPE_PARAM: Record<number, string> = {
  1: 'order_no',
  2: 'third_party_order_no',
  3: 'agent_username',
};

const cashFlows = ref<CashFlowForTpRow[]>([]);
const currencies = ref<Array<{ id: number; name: string }>>([]);
const orderTypes = ref<Array<{ name: string; order_type_id: number }>>([]);

const filters = reactive({
  currency_id: 0 as number | string,
  order_type_id: 0 as number | string,
  searchType: 3,
  source_temp: '0',
  status: 1,
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
  time_type: 1,
  username: '',
});

const sourceGroups = ref(buildSourceGroups([]));

/** 選了金流來源時連動載入訂單類型 */
watch(
  () => filters.source_temp,
  async (v) => {
    filters.order_type_id = 0;
    orderTypes.value = [];
    const { agent_cash_flow_id, cash_flow_id } = splitSourceTemp(v);
    if (isDepositSource(v)) {
      const r = await getAgentOrderTypesApi(cash_flow_id, {
        agent_cash_flow_id,
      }).catch(() => null);
      if (r && Number(r.code) === 1000) orderTypes.value = r.data ?? [];
    } else if (isWithdrawalSource(v)) {
      const r = await getWithdrawalSubListApi(cash_flow_id).catch(() => null);
      if (r && Number(r.code) === 1000) orderTypes.value = r.data ?? [];
    }
  },
);

const summary = ref<ReportSummary>({});

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<SrChargeRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      const { agent_cash_flow_id, cash_flow_id, source } = splitSourceTemp(
        filters.source_temp,
      );
      const params: Record<string, unknown> = {
        agent_cash_flow_id,
        cash_flow_id,
        currency_id: filters.currency_id,
        end_time: toApiTime(`${end}:59`),
        order_type_id: filters.order_type_id,
        page,
        per_page,
        source,
        start_time: toApiTime(`${start}:00`),
        status: filters.status,
        time_type: filters.time_type,
      };
      if (filters.username) {
        params[TYPE_PARAM[filters.searchType] ?? ''] = filters.username;
      }
      return getReportSr98Api(params);
    },
    {
      guard: () => {
        const [start, end] = filters.time_range ?? [];
        if (!start || !end) {
          ElMessage.warning(tl('between'));
          return false;
        }
        return true;
      },
      onLoaded: (res) => {
        summary.value =
          Number(res.code) === 1000
            ? ((res as { summary?: ReportSummary }).summary ?? {})
            : {};
      },
      perPage: PER_PAGE,
      showError: true,
    },
  );

/** 原值千分位、最多 4 位 */
const fmt = (v?: null | number | string) => looseThousands(v, 4);
/** 合計卡 amount_thousands(..., 4)：固定 4 位 */
const fmt4 = (v?: null | number | string) => amountThousands(v, 4);

const exporting = ref(false);
/** 匯出 Excel：欄位（14 欄；status 為 API 回傳文字） */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return;
  }
  exporting.value = true;
  try {
    const { agent_cash_flow_id, cash_flow_id, source } = splitSourceTemp(
      filters.source_temp,
    );
    const params: Record<string, unknown> = {
      agent_cash_flow_id,
      cash_flow_id,
      currency_id: filters.currency_id,
      end_time: toApiTime(`${end}:59`),
      order_type_id: filters.order_type_id,
      page: 1,
      per_page: 100_000,
      source,
      start_time: toApiTime(`${start}:00`),
      status: filters.status,
      time_type: filters.time_type,
    };
    if (filters.username) {
      params[TYPE_PARAM[filters.searchType] ?? ''] = filters.username;
    }
    const res = await getReportSr98Api(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await exportExcel(
      `sr98_${Date.now()}`,
      [
        tl('createdate'),
        tl('updated_at'),
        tl('ordernum'),
        tl('third_ordernum'),
        tl('currency'),
        tl('amount'),
        tl('rate'),
        tl('point'),
        `${tl('deposit')}/${tl('withdrawal')}`,
        tl('type'),
        tl('account'),
        tl('real_name'),
        tl('source_channel'),
        tl('order_status'),
      ],
      rows.map((r) => [
        fromApiTime(r.created_at),
        fromApiTime(r.pay_time),
        r.order_no,
        r.third_party_order_no,
        r.currency_name,
        fmt(r.amount),
        fmt(r.rate),
        fmt(r.point),
        Number(r.payment_type) === 1 ? tl('deposit') : tl('withdrawal'),
        Number(r.user_type) === 1 ? tl('agent') : tl('member'),
        r.username,
        r.real_name,
        r.source,
        r.status,
      ]),
      {
        audit: { conditions: params },
        dateRange: filters.time_range as [string, string],
        title: tl('menu_report_sr98'),
      },
    );
  } finally {
    exporting.value = false;
  }
}

onMounted(() => {
  getCashFlowsForTpApi()
    .then((d) => {
      cashFlows.value = d ?? [];
      // 舊版 sr98.php 的來源下拉多了「線上支付/線下支付/代付/自助洗分」固定選項（SR99 沒有）
      sourceGroups.value = buildSourceGroups(cashFlows.value, true);
    })
    .catch(() => {});
  getCurrencyListApi()
    .then((d) => {
      const seen = new Set<number | string>();
      currencies.value = (d ?? []).filter((c) => {
        if (seen.has(c.id)) return false;
        seen.add(c.id);
        return true;
      });
    })
    .catch(() => {});
});
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('menu_report_sr98')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem>
            <template #label>
              <ElSelect v-model="filters.searchType" class="!w-36">
                <ElOption :label="tl('ordernum')" :value="1" />
                <ElOption :label="tl('third_ordernum')" :value="2" />
                <ElOption :label="tl('agent_account')" :value="3" />
              </ElSelect>
            </template>
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('source')">
            <ElSelect v-model="filters.source_temp" class="w-full" filterable>
              <template v-for="g in sourceGroups" :key="g.label || 'fixed'">
                <ElOptionGroup v-if="g.group !== 0" :label="g.label">
                  <ElOption
                    v-for="o in g.options"
                    :key="o.value"
                    :label="o.label"
                    :value="o.value"
                  />
                </ElOptionGroup>
                <ElOption
                  v-for="o in g.group === 0 ? g.options : []"
                  v-else
                  :key="o.value"
                  :label="o.label"
                  :value="o.value"
                />
              </template>
            </ElSelect>
          </ElFormItem>
          <ElFormItem
            v-if="orderTypes.length > 0"
            :label="tl('deposit_order_type_name')"
          >
            <ElSelect v-model="filters.order_type_id" class="w-full">
              <ElOption :label="tl('all')" :value="0" />
              <ElOption
                v-for="o in orderTypes"
                :key="o.order_type_id"
                :label="o.name"
                :value="o.order_type_id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="w-full">
              <ElOption :label="tl('success')" :value="1" />
              <ElOption :label="tl('all')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem>
            <template #label>
              <ElSelect v-model="filters.time_type" class="!w-32">
                <ElOption :label="tl('createdate')" :value="1" />
                <ElOption :label="tl('updated_at')" :value="2" />
              </ElSelect>
            </template>
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
          <ElFormItem :label="tl('currency')">
            <ElSelect v-model="filters.currency_id" class="w-full" filterable>
              <ElOption :label="tl('all')" :value="0" />
              <ElOption
                v-for="c in currencies"
                :key="c.id"
                :label="c.name"
                :value="c.id"
              />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <div class="flex gap-2">
            <ElButton :loading="exporting" type="success" @click="handleExport">
              {{ tl('excel') }}
            </ElButton>
            <ElButton :loading="loading" native-type="submit" type="primary">
              {{ tl('search') }}
            </ElButton>
          </div>
        </div>
      </ElForm>
    </ElCard>

    <!-- 合計卡 -->
    <ElCard class="mb-3" shadow="never">
      <div class="grid grid-cols-2 gap-y-3 text-center md:grid-cols-4">
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('deposit_amount') }}
          </div>
          <div class="text-lg font-semibold">
            {{ fmt4(summary.deposit_amount) }}
          </div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('deposit_point') }}
          </div>
          <div class="text-lg font-semibold">
            {{ fmt4(summary.deposit_point) }}
          </div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('withdrawal_amount') }}
          </div>
          <div class="text-lg font-semibold">
            {{ fmt4(summary.withdraw_amount) }}
          </div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('withdrawal_point') }}
          </div>
          <div class="text-lg font-semibold">
            {{ fmt4(summary.withdraw_point) }}
          </div>
        </div>
      </div>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="`${tl('createdate')} / ${tl('updated_at')}`"
          width="160"
        >
          <template #default="{ row }">
            <div>{{ fromApiTime(row.created_at) }}</div>
            <div class="text-muted-foreground text-xs">
              {{ fromApiTime(row.pay_time) || '-' }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('ordernum')} / ${tl('third_ordernum')}`"
          min-width="170"
        >
          <template #default="{ row }">
            <div>{{ row.order_no || '-' }}</div>
            <div class="text-muted-foreground text-xs">
              {{ row.third_party_order_no || '-' }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('amount')" align="right" width="100">
          <template #default="{ row }">{{ fmt(row.amount) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('rate')" align="right" width="80">
          <template #default="{ row }">{{ fmt(row.rate) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('point')" align="right" width="100">
          <template #default="{ row }">{{ fmt(row.point) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('account')} / ${tl('real_name')}`"
          min-width="120"
        >
          <template #default="{ row }">
            <div><AgentAccount :username="row.username" /></div>
            <div class="text-muted-foreground text-xs">
              {{ row.real_name || '-' }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('source_channel')"
          prop="source"
          min-width="100"
        />
        <ElTableColumn :label="tl('order_status')" prop="status" width="100" />
        <ElTableColumn :label="tl('type')" width="90">
          <template #default="{ row }">
            {{ Number(row.user_type) === 1 ? tl('agent') : tl('member') }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('deposit')}/${tl('withdrawal')}`"
          width="90"
        >
          <template #default="{ row }">
            {{
              Number(row.payment_type) === 1 ? tl('deposit') : tl('withdrawal')
            }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('currency')"
          prop="currency_name"
          width="90"
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
