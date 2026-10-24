<script lang="ts" setup>
import type {
  AgentDashboardDetail,
  AgentDashboardDetailGroup,
  AgentDashboardDetailRow,
  AgentDashboardParams,
} from '#/api';

import { computed, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElMessage,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import {
  getAgentDashboardDetailApi,
  getAgentDashboardDetailExportApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcelWorkbook } from '#/utils/export-excel';
import { amountThousands, numberFormat } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

/**
 * 代理數據看板 — 指標明細（target/tickets#4180 第二階段）。
 *
 * 一列＝一位會員的期間彙總，同一會員只出現一次；欄位由後端的 `columns` 決定，
 * 所以這裡不為每組寫一套表頭。時間欄已是使用者所選時區，**不再呼叫** fromApiTime。
 */
defineOptions({ name: 'AgentDashboardDetailDialog' });

const PER_PAGE = 25;

const visible = ref(false);
const loading = ref(false);
const title = ref('');
const data = ref<AgentDashboardDetail | null>(null);
const page = ref(1);

let baseParams: AgentDashboardParams | null = null;
let group: AgentDashboardDetailGroup = 'registration';
let retentionDay: number | undefined;

const columns = computed(() => data.value?.columns ?? []);

/** 加總列只有金額與筆數欄有值；沒有這類欄位就不顯示加總 */
const summaryColumns = computed(() =>
  columns.value.filter((column) => ['int', 'money'].includes(column.type)),
);

function summaryRow(
  labelKey: string,
  values: Record<string, number | string>,
): AgentDashboardDetailRow {
  return {
    agent_username: null,
    hierarchy: 0,
    is_summary: true,
    member_id: 0,
    registered_at: null,
    username: tl(labelKey),
    ...values,
  };
}

/**
 * 加總併進主表格的最後兩列。
 *
 * 另開一張表會有兩個問題：欄寬各自算所以跟上面對不齊，而且它疊在資料表下方，
 * 小螢幕時會把表格能用的高度吃掉、看起來像蓋住最後一列資料。
 */
const tableRows = computed<AgentDashboardDetailRow[]>(() => {
  const detail = data.value;
  if (!detail) return [];
  if (detail.list.length === 0 || summaryColumns.value.length === 0) {
    return detail.list;
  }

  return [
    ...detail.list,
    summaryRow('ad_page_summary', detail.page_summary),
    summaryRow('ad_total_summary', detail.total_summary),
  ];
});

function cellText(value: unknown, type: string): string {
  if (value === null || value === undefined || value === '') return '—';
  if (type === 'money') return amountThousands(value as string, 2);
  if (type === 'int') return numberFormat(value as number, 0);

  return String(value);
}

async function load() {
  if (!baseParams) return;
  loading.value = true;
  try {
    const res = await getAgentDashboardDetailApi({
      ...baseParams,
      group,
      page: page.value,
      per_page: PER_PAGE,
      ...(retentionDay ? { retention_day: retentionDay } : {}),
    });
    if (Number(res.code) === 200) {
      data.value = res.data ?? null;
    } else {
      data.value = null;
      if (res.message) ElMessage.error(res.message);
    }
  } finally {
    loading.value = false;
  }
}

/** 由指標格子呼叫：帶入該格所屬的明細分組 */
async function open(payload: {
  group: AgentDashboardDetailGroup;
  params: AgentDashboardParams;
  retentionDay?: number;
  title: string;
}) {
  baseParams = payload.params;
  group = payload.group;
  retentionDay = payload.retentionDay;
  title.value = payload.title;
  page.value = 1;
  data.value = null;
  visible.value = true;
  await load();
}

function handlePageChange(next: number) {
  page.value = next;
  load();
}

const exporting = ref(false);

/**
 * 匯出當前這組明細的全部會員。
 *
 * 走 detail-export（不分頁、不算總筆數、不做加總），欄位沿用後端給的 columns，
 * 不再寫第二份表頭。列數頂到後端上限就提示被截斷。
 *
 * 產檔走 exportExcelWorkbook（而非 runExport）是為了多帶一個「查詢條件」分頁——
 * 拿到檔案的人要看得出這份名單是哪個代理、哪種統計範圍跑出來的。
 */
async function handleExport() {
  if (!baseParams || !data.value) return;
  exporting.value = true;
  try {
    const params = {
      ...baseParams,
      group,
      ...(retentionDay ? { retention_day: retentionDay } : {}),
    };
    const res = await getAgentDashboardDetailExportApi(params);
    if (Number(res.code) !== 200) {
      if (res.message) ElMessage.error(res.message);

      return;
    }
    const list = res.data?.list ?? [];
    if (list.length >= (res.data?.limit ?? Infinity)) {
      ElMessage.warning(tl('ad_export_capped'));
    }

    await exportExcelWorkbook({
      audit: {
        action: 'agent_dashboard_detail',
        conditions: params,
        recordCount: list.length,
      },
      sheets: [
        {
          name: title.value,
          title: `${tl('ad_detail_title')} ${title.value}`,
          dateRange: [baseParams.start_time, baseParams.end_time],
          columns: [
            tl('member_account'),
            tl('ad_col_belong_agent'),
            tl('level'),
            tl('register_time'),
            ...columns.value.map((column) => tl(column.label_key)),
          ],
          rows: list.map((row) => [
            row.username,
            row.agent_username ?? '',
            row.hierarchy,
            row.registered_at ?? '',
            ...columns.value.map((column) =>
              exportCell(row[column.key], column.type),
            ),
          ]),
        },
        {
          name: tl('search_criteria'),
          title: tl('search_criteria'),
          columns: [tl('entry'), tl('content')],
          rows: [
            [
              tl('report_between'),
              `${baseParams.start_time} ~ ${baseParams.end_time}`,
            ],
            [tl('label_agent_account'), baseParams.agent_username || tl('all')],
            [
              tl('ad_stat_scope'),
              baseParams.scope === 'self'
                ? tl('ad_scope_self')
                : tl('agent_line'),
            ],
          ],
        },
      ],
      title: `${tl('ad_detail_title')} ${title.value}`,
      tzOffset: baseParams.tz_offset,
    });
  } finally {
    exporting.value = false;
  }
}

/** 匯出儲存格：金額與筆數保持數字，是/否轉文字，其餘照原樣 */
function exportCell(
  value: unknown,
  type: string,
): null | number | string | undefined {
  if (value === null || value === undefined || value === '') return '';
  if (type === 'bool') return value ? tl('yes') : tl('no');

  return ['int', 'money'].includes(type) ? Number(value) : String(value);
}

defineExpose({ open });
</script>

<template>
  <!-- 寬度隨內容（欄位數各組不同），上限 92vw；表格 w-auto 才不會被撐滿留白 -->
  <ElDialog
    v-model="visible"
    :title="`${tl('ad_detail_title')} — ${title}`"
    align-center
    class="!max-w-[92vw]"
    width="fit-content"
  >
    <div class="text-muted-foreground mb-2 text-xs leading-5">
      {{ tl('ad_detail_note') }}
      <span v-if="data">（{{ data.time_basis }}）</span>
    </div>

    <ElTable
      v-loading="loading"
      :data="tableRows"
      :row-class-name="({ row }) => (row.is_summary ? 'is-summary-row' : '')"
      border
      class="w-auto"
      size="small"
    >
      <ElTableColumn :label="tl('member_account')" width="150">
        <template #default="{ row }">
          <MemberAccountLink :username="row.username" />
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('ad_col_belong_agent')"
        prop="agent_username"
        width="130"
      >
        <template #default="{ row }">
          <AgentAccount :username="row.agent_username" />
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('level')" width="80">
        <template #default="{ row }">
          <ElTag v-if="!row.is_summary" size="small">{{ row.hierarchy }}</ElTag>
          <span v-else>—</span>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('register_time')" width="170">
        <template #default="{ row }">{{ row.registered_at ?? '—' }}</template>
      </ElTableColumn>
      <ElTableColumn
        v-for="column in columns"
        :key="column.key"
        :align="['int', 'money'].includes(column.type) ? 'right' : 'left'"
        :label="tl(column.label_key)"
        :width="column.type === 'datetime' ? 170 : 130"
      >
        <template #default="{ row }">
          <template v-if="column.type === 'bool' && !row.is_summary">
            <ElTag :type="row[column.key] ? 'success' : 'info'" size="small">
              {{ row[column.key] ? tl('yes') : tl('no') }}
            </ElTag>
          </template>
          <template v-else>
            {{ cellText(row[column.key], column.type) }}
          </template>
        </template>
      </ElTableColumn>
    </ElTable>

    <TablePagination
      v-if="data"
      :current-page="data.pagination.current_page"
      :page-size="data.pagination.per_page"
      :total="data.pagination.total"
      @current-change="handlePageChange"
    />

    <template #footer>
      <ElButton @click="visible = false">{{ tl('close') }}</ElButton>
      <ElButton
        :disabled="!data || data.pagination.total === 0"
        :loading="exporting"
        type="success"
        @click="handleExport"
      >
        {{ tl('excel') }}
      </ElButton>
    </template>
  </ElDialog>
</template>

<style scoped>
/* 加總列：跟資料列同一張表，靠底色與粗體區分 */
:deep(.el-table .is-summary-row > td.el-table__cell) {
  font-weight: 600;
  background: var(--el-fill-color-light);
}
</style>
