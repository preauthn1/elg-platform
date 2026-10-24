<script lang="ts" setup>
import type { RollingChipDayRow } from '#/api';

import { computed, h, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getRollingChipDayReportApi } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import {
  amountThousands,
  buildPlainAmounts,
  toHalfWidth,
} from '#/utils/format';

defineOptions({ name: 'RollingChipDayReport' });

const PER_PAGE = 20;

const filters = reactive({
  time_range: [
    dayjs().subtract(1, 'day').format('YYYY-MM-DD'),
    dayjs().format('YYYY-MM-DD'),
  ] as string[],
  username: '',
});

const summary = reactive({ pay: '0', use: '0' });

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<RollingChipDayRow>(
    ({ page }) => {
      const [start, end] = filters.time_range ?? [];
      return getRollingChipDayReportApi({
        end_time: end ?? '',
        page,
        start_time: start ?? '',
        username: toHalfWidth(filters.username),
      });
    },
    {
      guard: () => {
        const [start, end] = filters.time_range ?? [];
        return Boolean(start && end);
      },
      onLoaded: (res) => {
        if (Number(res.code) === 1000) {
          const s = (
            res as {
              summary?: {
                pay_rolling_chip?: unknown;
                use_rolling_chip?: unknown;
              };
            }
          ).summary;
          summary.pay = String(s?.pay_rolling_chip ?? 0);
          summary.use = String(s?.use_rolling_chip ?? 0);
        }
      },
      perPage: PER_PAGE,
    },
  );

const fmt = (v?: number | string) => amountThousands(v, 4);

/** 表尾合計列 */
function summaryMethod() {
  return [
    tl('total_count'),
    h('span', { class: 'font-bold' }, fmt(summary.pay)),
    h('span', { class: 'font-bold' }, fmt(summary.use)),
  ];
}

const showSummary = computed(() => list.value.length > 0);

const exporting = ref(false);
/** 匯出 Excel：抓全部符合的列 */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) return;
  exporting.value = true;
  try {
    const params = {
      end_time: end,
      page: 1,
      per_page: 100_000,
      start_time: start,
      username: toHalfWidth(filters.username),
    };
    const res = await getRollingChipDayReportApi(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await buildPlainAmounts(() =>
      exportExcel(
        `rolling_chip_day_report_${Date.now()}`,
        [tl('date'), tl('pay_rolling_chip'), tl('use_rolling_chip')],
        rows.map((r) => [
          r.summary_time,
          fmt(r.pay_rolling_chip),
          fmt(r.use_rolling_chip),
        ]),
        {
          audit: { conditions: params },
          dateRange: [start, end],
          title: tl('menu_rolling_chip_day_report'),
          tzOffset: 8,
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}

loadData();
</script>

<template>
  <Page
    :title="`${tl('menu_rolling_chip')} > ${tl('menu_rolling_chip_day_report')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields v-model="filters.time_range" :fixed-offset="8" />
            <FixedTimezoneHint />
          </ElFormItem>
        </div>
        <div class="flex items-center justify-end">
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

    <ElCard shadow="never">
      <ElTable
        v-loading="loading"
        :data="list"
        :show-summary="showSummary"
        :summary-method="summaryMethod"
        border
        size="small"
        stripe
      >
        <ElTableColumn
          :label="tl('date')"
          prop="summary_time"
          min-width="140"
        />
        <ElTableColumn
          :label="tl('pay_rolling_chip')"
          align="right"
          min-width="150"
        >
          <template #default="{ row }">
            {{ fmt(row.pay_rolling_chip) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('use_rolling_chip')"
          align="right"
          min-width="150"
        >
          <template #default="{ row }">
            {{ fmt(row.use_rolling_chip) }}
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
  </Page>
</template>
