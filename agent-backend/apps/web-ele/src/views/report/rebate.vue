<script lang="ts" setup>
import type { RebateRow } from '#/api';

import { reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
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

import { getRebateReportApi, getVendorListApi } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

defineOptions({ name: 'ReportRebate' });

const PAGE_SIZES = [10, 25, 50, 100];

/** 日期區間預設選項（選了就帶入日期） */
const vendors = ref<Array<{ id: number; name: string }>>([]);

const filters = reactive({
  time_range: [
    dayjs().format('YYYY-MM-DD'),
    dayjs().format('YYYY-MM-DD'),
  ] as string[],
  vendor_id: 0 as number | string,
});

const loading = ref(false);
const searched = ref(false);
const list = ref<RebateRow[]>([]);
const pageSize = ref(10);

// 前端分頁（API 一次最多回 5000 筆）
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
  searchFields: (r) => [r.vendor_name, r.game_type_name],
});

function handleSizeChange(size: number) {
  pageSize.value = size;
  page.value = 1;
}

const fmt = (v?: null | number | string) => amountThousands(v, 2);

async function loadData() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('date'));
    return;
  }
  loading.value = true;
  searched.value = true;
  try {
    const res = await getRebateReportApi({
      end_date: end,
      start_date: start,
      vendor_id: filters.vendor_id,
    });
    if (Number(res.code) === 1000) {
      // 平台名稱升冪
      list.value = (res.data ?? []).toSorted((a, b) =>
        String(a.vendor_name ?? '').localeCompare(String(b.vendor_name ?? '')),
      );
    } else {
      list.value = [];
      ElMessage.warning(
        Number(res.code) === 2002 ? String(res.message) : res.message,
      );
    }
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadData();
}

getVendorListApi()
  .then((d) => {
    vendors.value = d ?? [];
  })
  .catch(() => {});
</script>

<template>
  <Page :title="tl('menu_cj7_rebate_report')">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('date')">
            <DateRangeFields v-model="filters.time_range" :fixed-offset="8" />
            <FixedTimezoneHint />
          </ElFormItem>
          <ElFormItem :label="tl('gaming_platform')">
            <ElSelect v-model="filters.vendor_id" class="w-full" filterable>
              <ElOption :label="tl('all')" :value="0" />
              <ElOption
                v-for="v in vendors"
                :key="v.id"
                :label="v.name"
                :value="v.id"
              />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex justify-end">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard v-if="searched" shadow="never">
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
          :label="tl('gaming_platform')"
          prop="vendor_name"
          min-width="140"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('game_type')"
          prop="game_type_name"
          min-width="120"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('turnover')"
          align="right"
          min-width="130"
          prop="bet_amount"
          sortable="custom"
        >
          <template #default="{ row }">
            <span
              :class="{ 'text-red-500': Number(row.bet_amount) < 0 }"
              class="font-medium"
            >
              {{ fmt(row.bet_amount) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('earn_commission')"
          align="right"
          min-width="130"
          prop="rebate"
          sortable="custom"
        >
          <template #default="{ row }">
            <span
              :class="{ 'text-red-500': Number(row.rebate) < 0 }"
              class="font-medium"
            >
              {{ fmt(row.rebate) }}
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
