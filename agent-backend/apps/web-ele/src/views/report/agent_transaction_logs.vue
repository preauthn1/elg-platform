<script lang="ts" setup>
import type { AgentTxLogRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getAgentTransactionReportApi, getCustomizedSettingApi } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'AgentTransactionLogs' });

/** 交易類型下拉固定選項 */
const ALL_TYPE_OPTIONS = [0, 1001, 1, 2, 3, 5, 6, 7, 8, 9, 10, 11];

/** 佔成錢包相關類型（主錢包轉至佔成錢包／退還、佔成錢包轉回主錢包、佔成結算），
 * 僅於代理帳號管理佔成錢包顯示開關（4057）啟用時顯示 */
const PROFIT_SHARE_TYPES = new Set([6, 7, 8, 9]);

/** 代理設定旗標：佔成錢包顯示開關 4057 */
const profitShareWalletVisible = ref(false);

const TYPE_OPTIONS = computed(() =>
  profitShareWalletVisible.value
    ? ALL_TYPE_OPTIONS
    : ALL_TYPE_OPTIONS.filter((t) => !PROFIT_SHARE_TYPES.has(t)),
);

/** 付款方顯示總代理名的類型（custom_type 3/5/9） */
const GENERAL_FROM_TYPES = new Set([3, 5, 9]);

const PAGE_SIZES = [10, 25, 50, 100];

const filters = reactive({
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
  type: 0 as number | string,
});

const loading = ref(false);
const list = ref<AgentTxLogRow[]>([]);
const pageSize = ref(10);

const fmt = (v?: null | number | string) => amountThousands(v, 4);

/**
 * created_at 顯示：端點（TransactionController@getTransactionReport）輸入輸出
 * 皆為裸 UTC（無 -8hour 也無 DATE_ADD），前端雙側以 basis 0 補償 ——
 * 顯示側原寫死 +8，改為隨所選時區換算（預設 GMT+8 行為相同）。 */
function displayTime(v?: string) {
  return fromApiTime(v, 0) || '-';
}

/** API 回傳的總代理帳號（getTransactionReport.general_agent_name） */
const generalAgentName = ref('');

/** 付款方：custom_type 3/5/9 顯示總代理名，其餘 display_from */
function payer(row: AgentTxLogRow) {
  return GENERAL_FROM_TYPES.has(Number(row.custom_type))
    ? generalAgentName.value || (row.display_from ?? '')
    : (row.display_from ?? '');
}

// 前端分頁（API 一次最多回 5000 筆、不回分頁資訊）
const {
  handlePageChange,
  handleSearch: handleQuickSearch,
  handleSortChange,
  keyword,
  page,
  pagedRows: pagedList,
  total,
} = useClientTable(list, {
  perPage: pageSize,
  searchFields: (r) => [
    displayTime(r.created_at),
    payer(r),
    r.display_to,
    r.amount,
  ],
});

function handleSizeChange(size: number) {
  pageSize.value = size;
  page.value = 1;
}

async function loadData() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return;
  }
  loading.value = true;
  try {
    // 端點比對裸 UTC：以 basis 0 換算（原寫死 -8h）＋補 :00/:59 送出
    const res = await getAgentTransactionReportApi({
      end_time: `${toApiTime(end, 0).slice(0, 16)}:59`,
      page: 1,
      per_page: 5000,
      start_time: `${toApiTime(start, 0).slice(0, 16)}:00`,
      type: filters.type,
    });
    if (Number(res.code) === 1000) {
      generalAgentName.value = res.general_agent_name ?? '';
      // created_at desc
      list.value = (res.data ?? []).toSorted((a, b) =>
        String(b.created_at).localeCompare(String(a.created_at)),
      );
    } else {
      list.value = [];
      ElMessage.warning(res.message);
    }
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadData();
}

// 頁面載入即查詢
onMounted(() => {
  loadData();
  getCustomizedSettingApi()
    .then((s) => {
      const map = new Map(s.map((i) => [Number(i.setting_key), i.value]));
      profitShareWalletVisible.value = Number(map.get(4057) ?? 0) === 1;
    })
    .catch(() => {});
});
</script>

<template>
  <Page
    :title="`${tl('menu_cj7_cash')} > ${tl('menu_shareholder_agent_transaction_logs')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('between')">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
          <ElFormItem :label="tl('agent_transaction_type')">
            <ElSelect v-model="filters.type" class="w-full">
              <ElOption
                v-for="t in TYPE_OPTIONS"
                :key="t"
                :label="t === 0 ? tl('all') : tl(`agent_transaction_type.${t}`)"
                :value="t"
              />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-3">
        <ElInput
          v-model="keyword"
          :placeholder="tl('search')"
          class="!w-56"
          clearable
          @input="handleQuickSearch"
        />
      </div>
      <ElTable
        v-loading="loading"
        :data="pagedList"
        border
        size="small"
        stripe
        @sort-change="handleSortChange"
      >
        <ElTableColumn
          :label="tl('time')"
          prop="created_at"
          sortable="custom"
          width="170"
        >
          <template #default="{ row }">
            {{ displayTime(row.created_at) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_transaction_payer')"
          min-width="120"
          prop="display_from"
          sortable="custom"
        >
          <template #default="{ row }">{{ payer(row) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_transaction_receiver')"
          prop="display_to"
          min-width="120"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('agent_transaction_type')"
          min-width="130"
          prop="custom_type"
          sortable="custom"
        >
          <template #default="{ row }">
            {{ tl(`agent_transaction_type.${row.custom_type}`) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_transaction_amount')"
          align="right"
          prop="amount"
          sortable="custom"
          width="150"
        >
          <template #default="{ row }">
            <span
              :class="Number(row.amount) < 0 ? 'text-red-500' : 'text-primary'"
            >
              {{ fmt(row.amount) }}
            </span>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="pageSize"
        :total="total"
        :page-sizes="PAGE_SIZES"
        layout="total, sizes, prev, pager, next, jumper"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </ElCard>
  </Page>
</template>
