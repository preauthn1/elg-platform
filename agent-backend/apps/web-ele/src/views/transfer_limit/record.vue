<script lang="ts" setup>
import type { TransferLimitRecordRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getTransferLimitDropdownApi, getTransferLimitRecordApi } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands, buildPlainAmounts } from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'TransferLimitRecord' });

const PER_PAGE = 20;

const groups = ref<Array<{ id: number | string; name: string }>>([]);
const filters = reactive({
  agent_group_id: 0 as number | string,
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59:59'),
  ] as string[],
  type: 0 as number | string,
});

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<TransferLimitRecordRow>(
    ({ page }) => {
      const [start, end] = filters.time_range ?? [];
      return getTransferLimitRecordApi({
        agent_group_id: filters.agent_group_id,
        // 送出前 -8h
        end_time: toApiTime(end, 0), // 端點 raw 比對 UTC（原 -8h）
        page,
        start_time: toApiTime(start, 0),
        type: filters.type,
      });
    },
    {
      guard: () => {
        const [start, end] = filters.time_range ?? [];
        return Boolean(start && end);
      },
      perPage: PER_PAGE,
    },
  );

const fmt = (v?: number | string) => amountThousands(v, 2);
/** 顯示時間 +8h */
const showTime = (v?: string) => fromApiTime(v, 0); // 端點輸出裸 UTC（原 +8h）;

const exporting = ref(false);
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) return;
  exporting.value = true;
  try {
    const params = {
      agent_group_id: filters.agent_group_id,
      end_time: toApiTime(end, 0), // 端點 raw 比對 UTC（原 -8h）
      page: 1,
      per_page: 100_000,
      start_time: toApiTime(start, 0),
      type: filters.type,
    };
    const res = await getTransferLimitRecordApi(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await buildPlainAmounts(() =>
      exportExcel(
        `transfer_limit_record_${Date.now()}`,
        [
          tl('time'),
          tl('group_name'),
          tl('amount'),
          tl('before_point'),
          tl('change_point'),
          tl('memo'),
        ],
        rows.map((r) => [
          showTime(r.created_at),
          r.agent_group_name,
          fmt(r.amount),
          fmt(r.before_limit),
          fmt(r.after_limit),
          r.memo,
        ]),
        {
          audit: { conditions: params },
          dateRange: [start, end],
          title: tl('menu_point_record'),
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}

onMounted(async () => {
  groups.value = (await getTransferLimitDropdownApi().catch(() => [])) ?? [];
});
</script>

<template>
  <Page
    :title="`${tl('menu_agent_transfer_limit')} > ${tl('menu_point_record')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('group_name')">
            <ElSelect
              v-model="filters.agent_group_id"
              class="w-full"
              filterable
            >
              <ElOption :label="tl('all')" :value="0" />
              <ElOption
                v-for="g in groups"
                :key="g.id"
                :label="g.name"
                :value="g.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('type')">
            <ElSelect v-model="filters.type" class="w-full">
              <ElOption :label="tl('all')" :value="0" />
              <ElOption :label="tl('plus')" :value="1" />
              <ElOption :label="tl('reduce')" :value="2" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm:ss"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
            />
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
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('time')" min-width="160">
          <template #default="{ row }">{{ showTime(row.created_at) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('group_name')" min-width="140">
          <template #default="{ row }">
            <span :title="row.id">{{ row.agent_group_name }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('amount')" align="right" min-width="120">
          <template #default="{ row }">
            <span
              :class="
                Number(row.amount) < 0 ? 'text-red-500' : 'text-green-600'
              "
            >
              {{ fmt(row.amount) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('before_point')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">{{ fmt(row.before_limit) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('change_point')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">{{ fmt(row.after_limit) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" prop="memo" min-width="150" />
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
