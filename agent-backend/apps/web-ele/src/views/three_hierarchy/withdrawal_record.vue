<script lang="ts" setup>
import type {
  ThreeHierarchyWithdrawRow,
  ThreeHierarchyWithdrawSummary,
} from '#/api';

import { computed, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import { getThreeHierarchyWithdrawRecordApi } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands, buildPlainAmounts } from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'ThreeHierarchyWithdrawalRecord' });

const PER_PAGE = 20;

/** 工單4199：派發紀錄頁點提現單號跳轉時，由 query 帶入單號與期間直接定位該筆 */
const route = useRoute();

const filters = reactive({
  agent: '',
  member: '',
  order_no: String(route.query.order_no ?? ''),
  time_range: [
    String(route.query.start_time ?? nowInUserTz().format('YYYY-MM-DD 00:00')),
    String(route.query.end_time ?? nowInUserTz().format('YYYY-MM-DD 23:59')),
  ] as string[],
});

const summary = ref<ThreeHierarchyWithdrawSummary>({});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<ThreeHierarchyWithdrawRow>(
    ({ page }) => {
      const [start, end] = filters.time_range ?? [];
      // 時間元件為分鐘級，apis 端驗證要求到秒，補上 :00／:59
      return getThreeHierarchyWithdrawRecordApi({
        agent: filters.agent,
        end_time: `${toApiTime(end ?? '')}:59`,
        member: filters.member,
        order_no: filters.order_no,
        page,
        start_time: `${toApiTime(start ?? '')}:00`,
      });
    },
    {
      guard: () => {
        const [start, end] = filters.time_range ?? [];
        return Boolean(start && end);
      },
      onLoaded: (res) => {
        // apis 後端成功碼 200（4214 起由 API_Agent 移植）
        summary.value =
          Number(res.code) === 200
            ? ((res as { summary?: ThreeHierarchyWithdrawSummary }).summary ??
              {})
            : {};
      },
      perPage: PER_PAGE,
    },
  );

const fmt = (v?: number | string) => amountThousands(v, 2);

/** 來源欄（工單4214 場景 B）：依 admin 判斷（後端已轉語意值） */
const sourceText = (s?: string) =>
  s === 'manual' ? tl('th_source_manual') : tl('th_source_schedule');

/** 狀態文案改執行語意（工單4214）：已通過 → 已入帳；失敗實際不會出現（寫入點寫死 1） */
const statusText = (s?: number) =>
  Number(s) === 1 ? tl('th_status_paid') : tl('th_status_failed');

/** 頁面合計＝當頁各列提現獎勵加總（翻頁會變；總合計與統計卡不變） */
const pageTotal = computed(() =>
  list.value.reduce((acc, r) => acc + Number(r.withdraw_point ?? 0), 0),
);

/** ElTable 原生合計列＝頁面合計；總合計另以表尾列呈現 */
function summaryMethod() {
  const row: string[] = ['', '', '', '', '', '', '', ''];
  row[0] = tl('th_page_total');
  row[3] = fmt(pageTotal.value);
  return row;
}

const title = computed(
  () =>
    `${tl('menu_reward_mode_management')} > ${tl('menu_three_layers_agents_mode_including_agent')} > ${tl('menu_three_layers_withdrawal_record')}`,
);

function handleReset() {
  filters.agent = '';
  filters.member = '';
  filters.order_no = '';
  filters.time_range = [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ];
  search();
}

const exporting = ref(false);
/** 匯出 Excel：抓全部符合的列（含來源欄、合計列與統計卡數字） */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) return;
  exporting.value = true;
  try {
    const params = {
      agent: filters.agent,
      end_time: `${toApiTime(end)}:59`,
      member: filters.member,
      order_no: filters.order_no,
      page: 1,
      per_page: 100_000,
      start_time: `${toApiTime(start)}:00`,
    };
    const res = await getThreeHierarchyWithdrawRecordApi(params);
    const rows = Number(res.code) === 200 ? (res.data ?? []) : [];
    const s = Number(res.code) === 200 ? (res.summary ?? {}) : {};
    await buildPlainAmounts(() =>
      exportExcel(
        `withdrawal_record_${Date.now()}`,
        [
          tl('withdraw_no'),
          tl('member_account'),
          tl('th_source'),
          tl('withdraw_point'),
          tl('remaining_point'),
          tl('status'),
          tl('withdraw_application_time'),
        ],
        [
          ...rows.map((r) => [
            r.id,
            r.username,
            sourceText(r.source),
            fmt(r.withdraw_point),
            fmt(r.remaining_point),
            statusText(r.status),
            fromApiTime(r.created_at),
          ]),
          // 工單4214：匯出含合計列與統計卡數字
          [tl('th_grand_total'), '', '', fmt(s.total_point), '', '', ''],
          [
            `${tl('th_wd_count')}: ${s.total_count ?? 0}`,
            `${tl('th_wd_members')}: ${s.member_count ?? 0}`,
            '',
            '',
            '',
            '',
            '',
          ],
        ],
        {
          audit: { conditions: params },
          dateRange: [start, end],
          title: tl('menu_three_layers_withdrawal_record'),
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
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-3">
          <!-- 工單4214：代理商帳號篩選，與派發紀錄同版位（解場景 J） -->
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.agent" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.member" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('withdraw_no')">
            <ElInput v-model="filters.order_no" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')" class="md:col-span-2">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="handleReset">{{ tl('clear') }}</ElButton>
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('excel') }}
          </ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <!-- 工單4214：統計卡（解場景 F）——全期間、翻頁不變；人數為去重會員數 -->
    <div class="mb-3 grid grid-cols-1 gap-3 md:grid-cols-3">
      <ElCard shadow="never">
        <div class="text-xs opacity-60">{{ tl('th_wd_total') }}</div>
        <div class="text-xl font-bold">{{ fmt(summary.total_point) }}</div>
      </ElCard>
      <ElCard shadow="never">
        <div class="text-xs opacity-60">{{ tl('th_wd_count') }}</div>
        <div class="text-xl font-bold">{{ summary.total_count ?? 0 }}</div>
      </ElCard>
      <ElCard shadow="never">
        <div class="text-xs opacity-60">{{ tl('th_wd_members') }}</div>
        <div class="text-xl font-bold">{{ summary.member_count ?? 0 }}</div>
        <div class="text-xs opacity-50">{{ tl('th_wd_members_note') }}</div>
      </ElCard>
    </div>

    <ElCard shadow="never">
      <ElTable
        v-loading="loading"
        :data="list"
        :summary-method="summaryMethod"
        border
        show-summary
        size="small"
        stripe
      >
        <ElTableColumn :label="tl('withdraw_no')" prop="id" min-width="90" />
        <ElTableColumn :label="tl('member_account')" min-width="120">
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <!-- 工單4214：來源欄（解場景 B），位置在會員帳號之後 -->
        <ElTableColumn :label="tl('th_source')" align="center" min-width="90">
          <template #default="{ row }">
            <ElTag :type="row.source === 'manual' ? 'warning' : 'primary'">
              {{ sourceText(row.source) }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('withdraw_point')"
          align="right"
          min-width="110"
        >
          <template #default="{ row }">{{ fmt(row.withdraw_point) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('remaining_point')"
          align="right"
          min-width="110"
        >
          <template #default="{ row }">{{ fmt(row.remaining_point) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" align="center" min-width="100">
          <template #default="{ row }">
            <ElTag :type="Number(row.status) === 1 ? 'success' : 'danger'">
              {{ statusText(row.status) }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('withdraw_application_time')"
          align="right"
          :formatter="
            (row: ThreeHierarchyWithdrawRow) => fromApiTime(row.created_at)
          "
          min-width="160"
        />
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <!-- 總合計＝全期間加總（與統計卡同源），翻頁不變 -->
      <div
        class="border-el-border flex justify-between border border-t-0 px-3 py-2 text-sm font-semibold"
      >
        <span>{{ tl('th_grand_total') }}</span>
        <span>{{ fmt(summary.total_point) }}</span>
      </div>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>
  </Page>
</template>
