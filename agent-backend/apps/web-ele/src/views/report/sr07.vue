<script lang="ts" setup>
import type { Sr07Row } from '#/api';

import { onMounted, reactive } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getReportSr07Api } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useExcelExport } from '#/composables/use-excel-export';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands, toHalfWidth } from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'ReportSr07' });

const PER_PAGE = 20;

const filters = reactive({
  time_range: [
    nowInUserTz().format('YYYY-MM-DD'),
    nowInUserTz().format('YYYY-MM-DD'),
  ] as string[],
  username: '',
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<Sr07Row>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      return getReportSr07Api({
        end_time: toApiTime(`${end ?? ''} 23:59:59`),
        page,
        per_page,
        start_time: toApiTime(`${start ?? ''} 00:00:00`),
        username: toHalfWidth(filters.username),
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

const fmt = (v?: null | number | string) => amountThousands(v, 2);

/** 狀態：0失敗 / 4過期 / 2已領取 / 3已取消 / 1未領取 */
function statusInfo(s?: number | string) {
  switch (Number(s)) {
    case 0: {
      return { cls: 'text-red-500', txt: tl('failure') };
    }
    case 1: {
      return { cls: 'text-muted-foreground', txt: tl('unaccalimed') };
    }
    case 2: {
      return { cls: 'text-green-600', txt: tl('success_received') };
    }
    case 3: {
      return { cls: 'text-muted-foreground', txt: tl('cancel') };
    }
    case 4: {
      return { cls: 'text-red-500', txt: tl('expired') };
    }
    default: {
      return { cls: '', txt: '' };
    }
  }
}

const { exporting, runExport } = useExcelExport();
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return;
  }
  const params = {
    end_time: toApiTime(`${end} 23:59:59`),
    page: 1,
    per_page: 100_000,
    start_time: toApiTime(`${start} 00:00:00`),
    username: toHalfWidth(filters.username),
  };
  await runExport(
    () => getReportSr07Api(params),
    `sr07_${Date.now()}`,
    [
      tl('num'),
      tl('date'),
      tl('send_account'),
      tl('accept_account'),
      tl('point'),
      tl('handling_fee'),
      tl('status'),
    ],
    (r) => [
      r.id,
      fromApiTime(r.created_at),
      r.self_username,
      r.other_username,
      fmt(r.amount),
      fmt(r.tax),
      statusInfo(r.status).txt,
    ],
    {
      audit: { conditions: params },
      dateRange: [start, end],
      title: tl('sr07'),
    },
  );
}

onMounted(loadData);
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('sr07')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields v-model="filters.time_range" />
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
      <template #header>
        <span class="font-medium">{{ tl('detaillist') }}</span>
      </template>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('num')" prop="id" width="90" />
        <ElTableColumn
          :formatter="(row: Sr07Row) => fromApiTime(row.created_at)"
          :label="tl('date')"
          width="160"
        />
        <ElTableColumn :label="tl('send_account')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink :username="row.self_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('accept_account')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink :username="row.other_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('point')" align="right" width="120">
          <template #default="{ row }">{{ fmt(row.amount) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('handling_fee')" align="right" width="120">
          <template #default="{ row }">{{ fmt(row.tax) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" width="110">
          <template #default="{ row }">
            <span :class="statusInfo(row.status).cls">
              {{ statusInfo(row.status).txt }}
            </span>
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
