<script lang="ts" setup>
import type { OrderCashFlow, OrderRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getAgentSelfDepositOrderListApi, getOrderCashFlowApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useExcelExport } from '#/composables/use-excel-export';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';

import OrderDetailDialog from '../order/components/order-detail-dialog.vue';
import QueryOrderDialog from '../order/components/query-order-dialog.vue';

defineOptions({ name: 'AgentSelfDepositIndex' });

const PER_PAGE = 20;

const detailRef = ref<InstanceType<typeof OrderDetailDialog>>();
const queryOrderRef = ref<InstanceType<typeof QueryOrderDialog>>();

/** 帳號搜尋類型 → API 參數名 */
const TYPE_PARAM: Record<number, string> = {
  11: 'order_no',
  12: 'third_party_order_no',
  22: 'agent_username',
};

function defaultFilters() {
  return {
    cash_flow_id: ' ' as number | string,
    status: ' ' as number | string,
    time_range: [
      nowInUserTz().subtract(24, 'hour').format('YYYY-MM-DD 00:00'),
      nowInUserTz().format('YYYY-MM-DD 23:59'),
    ] as string[],
    type: 11,
    username: '',
  };
}

const filters = reactive(defaultFilters());
const cashFlows = ref<OrderCashFlow[]>([]);

const summary = reactive({ totalAmount: 0, totalPoint: 0 });

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<OrderRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      const params: Record<string, unknown> = {
        cash_flow_id: filters.cash_flow_id,
        end_time: toApiTime(end ?? ''),
        page,
        per_page,
        start_time: toApiTime(start ?? ''),
        status: filters.status,
        type: filters.type,
        [TYPE_PARAM[filters.type] ?? '']: filters.username,
      };
      return getAgentSelfDepositOrderListApi(params);
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
        const ok = Number(res.code) === 1000;
        const ts = (
          res as {
            total_summary?: {
              total_amount?: number | string;
              total_point?: number | string;
            };
          }
        ).total_summary;
        summary.totalAmount = ok ? Number(ts?.total_amount ?? 0) : 0;
        summary.totalPoint = ok ? Number(ts?.total_point ?? 0) : 0;
      },
      perPage: PER_PAGE,
      showError: true,
    },
  );

const fmtAmount = (v?: null | number | string) => amountThousands(v, 2);

/** created_at 顯示 +8 小時 */
function displayTime(v?: string) {
  return fromApiTime(v, 0) || '-'; // 端點輸出裸 UTC（原 +8h）
}

/** 訂單狀態文字 */
function statusText(row: OrderRow) {
  if (Number(row.cash_flow_id) === 5) {
    switch (Number(row.status)) {
      case 0: {
        return tl('order_create');
      }
      case 1: {
        return tl('pending_review');
      }
      case 2: {
        return tl('rejected');
      }
      default: {
        return tl('deposit_success');
      }
    }
  }
  return row.status_name ?? '';
}

function statusClass(row: OrderRow) {
  if (Number(row.status) === 3) return 'text-green-600';
  if (Number(row.status) === 2) return 'text-red-500';
  return '';
}

function canQueryOrder(row: OrderRow) {
  return row.third_party_code === 'D_ALL' && row.third_party_order_no === '';
}

const { exporting, runExport } = useExcelExport();
/** 匯出 Excel：欄位對齊舊版 ajax_excel.php excelAgentOrders（10 欄） */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return;
  }
  const params: Record<string, unknown> = {
    cash_flow_id: filters.cash_flow_id,
    end_time: toApiTime(end),
    page: 1,
    per_page: 100_000,
    start_time: toApiTime(start),
    status: filters.status,
    type: filters.type,
    [TYPE_PARAM[filters.type] ?? '']: filters.username,
  };
  await runExport(
    () => getAgentSelfDepositOrderListApi(params),
    `agent_self_deposit_${Date.now()}`,
    [
      tl('ordernum'),
      `${tl('ordernum')}(${tl('deposit_vendor_name')})`,
      tl('time'),
      tl('deposit_vendor_name'),
      tl('deposit_order_type_name'),
      tl('agent'),
      tl('real_name'),
      tl('amount'),
      tl('point'),
      tl('order_status'),
    ],
    (r: OrderRow) => [
      r.order_no,
      r.third_party_order_no,
      displayTime(r.created_at),
      r.cf_name,
      r.order_type_name,
      r.agent_username,
      r.agent_real_name,
      fmtAmount(r.amount),
      fmtAmount(r.point),
      statusText(r),
    ],
    {
      audit: { conditions: params },
      title: tl('menu_agent_self_deposit_index'),
    },
  );
}

function handleClear() {
  Object.assign(filters, defaultFilters());
  page.value = 1;
  loadData();
}

/** 合計列（金額／點數欄） */
function summaryMethod() {
  const sums: string[] = Array.from({ length: 9 }).fill('') as string[];
  sums[5] = fmtAmount(summary.totalAmount);
  sums[6] = fmtAmount(summary.totalPoint);
  return sums;
}

onMounted(() => {
  loadData();
  getOrderCashFlowApi()
    .then((d) => {
      // 後端以 type=[1,2] 查詢會回傳同一金流商多筆，依 id 去重
      cashFlows.value = [
        ...new Map((d ?? []).map((cf) => [cf.id, cf])).values(),
      ];
    })
    .catch(() => {});
});
</script>

<template>
  <Page
    :title="`${tl('menu_auto_deposit')} > ${tl('menu_agent_self_deposit_index')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem>
            <template #label>
              <ElSelect v-model="filters.type" class="!w-48">
                <ElOption :label="tl('ordernumber')" :value="11" />
                <ElOption
                  :label="`${tl('ordernumber')}(${tl('deposit_vendor_name')})`"
                  :value="12"
                />
                <ElOption :label="tl('agent_account')" :value="22" />
              </ElSelect>
            </template>
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
          <ElFormItem :label="tl('deposit_vendor_name')">
            <ElSelect v-model="filters.cash_flow_id" class="w-full" filterable>
              <ElOption :label="tl('all')" value=" " />
              <ElOption
                v-for="cf in cashFlows"
                :key="cf.id"
                :label="cf.name"
                :value="cf.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="w-full">
              <ElOption :label="tl('all')" value=" " />
              <ElOption :label="tl('order_create')" :value="0" />
              <ElOption :label="tl('success')" :value="1" />
              <ElOption :label="tl('failure')" :value="2" />
              <ElOption :label="tl('deposit_success')" :value="3" />
              <ElOption :label="tl('pending_manual_confirmation')" :value="4" />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <div>
            <ElButton :loading="exporting" type="success" @click="handleExport">
              {{ tl('excel') }}
            </ElButton>
            <ElButton :loading="loading" native-type="submit" type="primary">
              {{ tl('search') }}
            </ElButton>
            <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
          </div>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable
        v-loading="loading"
        :data="list"
        :summary-method="summaryMethod"
        :show-summary="list.length > 0"
        border
        size="small"
        stripe
      >
        <ElTableColumn
          :label="`${tl('ordernum')} / ${tl('ordernum')}（${tl('deposit_vendor_name')}）`"
          min-width="200"
        >
          <template #default="{ row }">
            <div :title="String(row.id)">
              <div>{{ row.order_no }}</div>
              <div class="text-muted-foreground text-xs">
                {{ row.third_party_order_no }}
              </div>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('time')" width="160">
          <template #default="{ row }">
            {{ displayTime(row.created_at) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('deposit_vendor_name')} / ${tl('deposit_order_type_name')}`"
          min-width="150"
        >
          <template #default="{ row }">
            <div>{{ row.cf_name }}</div>
            <div
              :title="String(row.order_type_id ?? '')"
              class="text-muted-foreground text-xs"
            >
              {{ row.order_type_name }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent')"
          prop="agent_username"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('real_name')"
          prop="agent_real_name"
          min-width="110"
        />
        <ElTableColumn :label="tl('amount')" align="right" width="110">
          <template #default="{ row }">{{ fmtAmount(row.amount) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('point')" align="right" width="110">
          <template #default="{ row }">{{ fmtAmount(row.point) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('order_status')" align="center" width="110">
          <template #default="{ row }">
            <span :class="statusClass(row as OrderRow)">
              {{ statusText(row as OrderRow) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="90" fixed="right">
          <template #default="{ row }">
            <ElDropdown>
              <ElButton size="small">{{ tl('features') }}</ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem @click="detailRef?.open(row as OrderRow, 1)">
                    {{ tl('watch_data') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    v-if="canQueryOrder(row as OrderRow)"
                    @click="queryOrderRef?.open(row.id)"
                  >
                    {{ tl('third_ordernum') }}
                  </ElDropdownItem>
                </ElDropdownMenu>
              </template>
            </ElDropdown>
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

    <OrderDetailDialog ref="detailRef" @saved="loadData" />
    <QueryOrderDialog ref="queryOrderRef" />
  </Page>
</template>
