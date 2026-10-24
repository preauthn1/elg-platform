<script lang="ts" setup>
import type { Sr97Row } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getCurrencyListApi, getReportSr97Api } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { looseThousands } from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'ReportSr97' });

const PER_PAGE = 20;

const currencies = ref<Array<{ id: number; name: string }>>([]);

const filters = reactive({
  currency_id: 0 as number | string,
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
  time_type: 1,
});

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<Sr97Row>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      // 時間帶到分，API 需補 :00 / :59
      return getReportSr97Api({
        currency_id: filters.currency_id,
        end_time: toApiTime(`${end}:59`),
        page,
        per_page,
        start_time: toApiTime(`${start}:00`),
        time_type: filters.time_type,
      });
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
      perPage: PER_PAGE,
      showError: true,
    },
  );

/** 原值千分位、不固定小數 */
const fmt = (v?: null | number | string) => looseThousands(v, 4);

const exporting = ref(false);
/** 匯出 Excel：欄位（12 欄；status 為 API 回傳文字） */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return;
  }
  exporting.value = true;
  try {
    const params = {
      currency_id: filters.currency_id,
      end_time: toApiTime(`${end}:59`),
      page: 1,
      per_page: 100_000,
      start_time: toApiTime(`${start}:00`),
      time_type: filters.time_type,
    };
    const res = await getReportSr97Api(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await exportExcel(
      `sr97_${Date.now()}`,
      [
        tl('createdate'),
        tl('updated_at'),
        tl('currency'),
        tl('amount'),
        tl('rate'),
        tl('point'),
        tl('account'),
        tl('real_name'),
        tl('bank'),
        tl('account_no'),
        tl('account_name'),
        tl('order_status'),
      ],
      rows.map((r) => [
        fromApiTime(r.created_at),
        fromApiTime(r.pay_time),
        r.currency_name,
        fmt(r.amount),
        fmt(r.rate),
        fmt(r.point),
        r.username,
        r.real_name,
        r.bank_name,
        r.account_no,
        r.account_name,
        r.status,
      ]),
      {
        audit: { conditions: params },
        dateRange: filters.time_range as [string, string],
        title: tl('menu_report_sr97'),
      },
    );
  } finally {
    exporting.value = false;
  }
}

onMounted(() => {
  getCurrencyListApi()
    .then((d) => {
      currencies.value = d ?? [];
    })
    .catch(() => {});
});
</script>

<template>
  <Page
    :title="`${tl('menu_agent_self_deposit_withdrawal')} > ${tl('menu_report_sr97')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
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

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="`${tl('createdate')} / ${tl('updated_at')}`"
          width="170"
        >
          <template #default="{ row }">
            <div>{{ fromApiTime(row.created_at) }}</div>
            <div class="text-muted-foreground text-xs">
              {{ fromApiTime(row.pay_time) || '-' }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('amount')" align="right" width="110">
          <template #default="{ row }">{{ fmt(row.amount) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('rate')" align="right" width="90">
          <template #default="{ row }">{{ fmt(row.rate) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('point')" align="right" width="110">
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
          :label="`${tl('bank')} / ${tl('account_no')} / ${tl('account_name')}`"
          min-width="150"
        >
          <template #default="{ row }">
            <div>{{ row.bank_name || '-' }}</div>
            <div>{{ row.account_no || '-' }}</div>
            <div class="text-muted-foreground text-xs">
              {{ row.account_name || '-' }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('order_status')" prop="status" width="100" />
        <ElTableColumn
          :label="`${tl('deposit')}/${tl('withdrawal')}`"
          width="100"
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
