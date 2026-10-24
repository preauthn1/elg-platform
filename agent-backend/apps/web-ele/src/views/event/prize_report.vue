<script lang="ts" setup>
import type { EventDropdownRow, PrizeReportRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getEventDropdownApi, getPrizeReportApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'EventPrizeReport' });

const PER_PAGE = 50;

const events = ref<EventDropdownRow[]>([]);
const filters = reactive({
  event_id: 0 as number | string,
  status: 1 as number | string,
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
  time_type: 1 as number | string,
  type: 2 as number | string,
  username: '',
});

/** 查詢當下是否彙總模式（event_id=0） */
const aggregate = ref(true);

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<PrizeReportRow>(
    ({ page }) => {
      const [start, end] = filters.time_range ?? [];
      aggregate.value = Number(filters.event_id) === 0;
      return getPrizeReportApi({
        end_time: toApiTime(`${end}:59`),
        event_id: filters.event_id,
        page,
        start_time: toApiTime(`${start}:00`),
        status: filters.status,
        time_type: filters.time_type,
        type: filters.type,
        username: filters.username,
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

const rewardType = (row: PrizeReportRow) =>
  Number(row.point_type) === 1 ? tl('platform_point') : tl('rolling_chip');

/** 彙總列點活動 → 下鑽該活動明細 */
function drillEvent(row: PrizeReportRow) {
  filters.event_id = row.event_id ?? 0;
  search();
}

const exporting = ref(false);
/** 匯出 Excel：依當前模式（彙總/明細）匯出全部符合列 */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) return;
  exporting.value = true;
  try {
    const isAgg = Number(filters.event_id) === 0;
    const params = {
      end_time: toApiTime(`${end}:59`),
      event_id: filters.event_id,
      page: 1,
      per_page: 100_000,
      start_time: toApiTime(`${start}:00`),
      status: filters.status,
      time_type: filters.time_type,
      type: filters.type,
      username: filters.username,
    };
    const res = await getPrizeReportApi(params);
    const data = Number(res.code) === 1000 ? (res.data ?? []) : [];
    const headers = isAgg
      ? [
          tl('event_name'),
          tl('status'),
          tl('reward_type'),
          tl('total_count'),
          tl('point'),
        ]
      : [
          tl('event_name'),
          tl('created_time'),
          tl('exchange_time'),
          tl('agent_account'),
          tl('member_account'),
          tl('reward_type'),
          tl('reward_name'),
          tl('point'),
          tl('status'),
        ];
    const rows = data.map((r) =>
      isAgg
        ? [
            r.event_title,
            Number(r.event_status) === 1 ? tl('enable') : tl('disable'),
            rewardType(r),
            r.cnt,
            r.prize_point,
          ]
        : [
            r.event_title,
            fromApiTime(r.created_at),
            Number(r.status) === 1 ? r.updated_at : '---',
            r.agent_username,
            r.member_username,
            rewardType(r),
            r.prize_name,
            r.prize_point,
            Number(r.status) === 1 ? tl('received') : tl('not_receive'),
          ],
    );
    await exportExcel(`prize_report_${Date.now()}`, headers, rows, {
      audit: { conditions: params },
      dateRange: filters.time_range as [string, string],
      title: tl('menu_event_report'),
    });
  } finally {
    exporting.value = false;
  }
}

const title = computed(
  () => `${tl('event_exclusive_offers')} > ${tl('menu_event_report')}`,
);

onMounted(async () => {
  events.value = (await getEventDropdownApi().catch(() => [])) ?? [];
  loadData();
});
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div
          class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2 lg:grid-cols-4"
        >
          <ElFormItem>
            <ElSelect v-model="filters.type" class="w-full">
              <ElOption :label="tl('agent_account')" :value="2" />
              <ElOption :label="tl('member_account')" :value="3" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem>
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('event_name')" class="lg:col-span-2">
            <ElSelect v-model="filters.event_id" class="w-full" filterable>
              <ElOption :label="tl('all')" :value="0" />
              <ElOption
                v-for="e in events"
                :key="e.id"
                :label="`${Number(e.status) === 0 ? `(${tl('disable')}) ` : ''}${e.id} - ${e.title}`"
                :value="e.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem>
            <ElSelect v-model="filters.time_type" class="w-full">
              <ElOption :label="tl('exchange_time')" :value="1" />
              <ElOption :label="tl('created_time')" :value="2" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('between')" class="lg:col-span-2">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
            <div class="text-muted-foreground mt-1 text-xs">
              {{ tl('query_period_max_days').replace('{value}', '60') }}
            </div>
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="w-full">
              <ElOption :label="tl('all')" :value="-1" />
              <ElOption :label="tl('success_received')" :value="1" />
              <ElOption :label="tl('unaccalimed')" :value="0" />
            </ElSelect>
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
      <!-- 彙總模式（event_id=0） -->
      <ElTable
        v-if="aggregate"
        v-loading="loading"
        :data="list"
        border
        size="small"
        stripe
      >
        <ElTableColumn :label="tl('event_name')" min-width="220">
          <template #default="{ row }">
            <ElButton
              link
              type="primary"
              @click="drillEvent(row as PrizeReportRow)"
            >
              {{ row.event_title }}
            </ElButton>
            <div class="text-muted-foreground text-xs">
              {{ tl('id') }}:{{ row.event_id }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" min-width="90">
          <template #default="{ row }">
            <span
              :class="
                Number(row.event_status) === 1
                  ? 'text-green-600'
                  : 'text-red-500'
              "
            >
              {{
                Number(row.event_status) === 1 ? tl('enable') : tl('disable')
              }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('reward_type')" min-width="110">
          <template #default="{ row }">
            {{ rewardType(row as PrizeReportRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('total_count')"
          align="right"
          prop="cnt"
          min-width="90"
        />
        <ElTableColumn
          :label="tl('point')"
          align="right"
          prop="prize_point"
          min-width="120"
        />
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>

      <!-- 明細模式（指定活動） -->
      <ElTable
        v-else
        v-loading="loading"
        :data="list"
        border
        size="small"
        stripe
      >
        <ElTableColumn
          :label="tl('event_name')"
          prop="event_title"
          min-width="160"
        />
        <ElTableColumn
          :formatter="(row: PrizeReportRow) => fromApiTime(row.created_at)"
          :label="tl('created_time')"
          min-width="150"
        />
        <ElTableColumn :label="tl('exchange_time')" min-width="150">
          <template #default="{ row }">
            {{ Number(row.status) === 1 ? row.updated_at : '---' }}
          </template>
        </ElTableColumn>
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
        <ElTableColumn :label="tl('reward_type')" min-width="110">
          <template #default="{ row }">
            {{ rewardType(row as PrizeReportRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('reward_name')"
          prop="prize_name"
          min-width="120"
        />
        <ElTableColumn
          :label="tl('point')"
          align="right"
          prop="prize_point"
          min-width="110"
        />
        <ElTableColumn :label="tl('status')" min-width="100">
          <template #default="{ row }">
            <span
              :class="
                Number(row.status) === 1 ? 'text-green-600' : 'text-amber-500'
              "
            >
              {{
                Number(row.status) === 1 ? tl('received') : tl('not_receive')
              }}
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
