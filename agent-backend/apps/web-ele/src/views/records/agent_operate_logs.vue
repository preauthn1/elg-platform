<script lang="ts" setup>
import type { OperateLogRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

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

import { getAgentOperateLogsApi, getOperateLogTypesApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';

import { orderStatusChangeText } from './order-status-change';

defineOptions({ name: 'AgentOperateLogs' });

const PER_PAGE = 10;

const types = ref<Array<{ text: string; value: string }>>([]);

function defaultFilters() {
  return {
    operator_username: '',
    search_ip: '',
    target_username: '',
    time_range: [
      nowInUserTz().format('YYYY-MM-DD 00:00'),
      nowInUserTz().format('YYYY-MM-DD 23:59'),
    ] as string[],
    type: ' ' as number | string,
  };
}

const filters = reactive(defaultFilters());

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<OperateLogRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      return getAgentOperateLogsApi({
        end_time: toApiTime(end ?? ''),
        operator_username: filters.operator_username,
        page,
        per_page,
        search_ip: filters.search_ip,
        start_time: toApiTime(start ?? ''),
        target_username: filters.target_username,
        type: filters.type,
      });
    },
    {
      guard: () => {
        const [start, end] = filters.time_range ?? [];
        return Boolean(start && end);
      },
      perPage: PER_PAGE,
      showError: true,
    },
  );

/** created_at 顯示 +8 小時 */
function displayTime(v?: string) {
  return fromApiTime(v, 0) || '-'; // 端點輸出裸 UTC（原 +8h）
}

/**
 * 修改對象：匯出紀錄指向的是被匯出的選單，顯示由根到該選單的整條層級。
 *
 * 只顯示末層分不出 SR16 與 SR17 底下同名的「會員查詢」。翻譯用左側選單同一組
 * menu_* key，所以跟著查看者的語系走；沒有對應語系 key 時顯示 code。
 */
function targetText(row: OperateLogRow) {
  const path = row.targetMenuCodePath ?? [];
  const codes =
    path.length > 0 ? path : [row.targetMenuCode ?? ''].filter(Boolean);

  return codes.length > 0
    ? codes.map((code) => tl(`menu_${code}`, code)).join(' > ')
    : (row.targetUserName ?? '');
}

/** 匯出紀錄的備註：筆數與查詢條件（選單名稱已在修改對象欄） */
function exportMemoText(row: OperateLogRow) {
  let debug: Record<string, unknown>;
  try {
    debug = JSON.parse(row.debug_info ?? '') as Record<string, unknown>;
  } catch {
    return '';
  }

  const parts: string[] = [];

  // 對不到選單的紀錄（頁面不在選單裡、或本次變更之前的舊資料）才退回顯示
  // 匯出當下凍結的選單名稱
  if (
    !row.targetMenuCode &&
    typeof debug.menu_name === 'string' &&
    debug.menu_name
  ) {
    parts.push(debug.menu_name);
  }

  if (debug.record_count !== undefined) {
    parts.push(`${tl('export_record_count')}: ${Number(debug.record_count)}`);
  }

  const conditions = debug.conditions;
  if (conditions && typeof conditions === 'object') {
    const pairs = Object.entries(conditions).map(
      ([key, value]) =>
        `${key}=${value === null || typeof value === 'object' ? '' : String(value)}`,
    );
    if (pairs.length > 0) parts.push(pairs.join('; '));
  }

  return parts.join(' | ').slice(0, 500);
}

/** 備註：匯出紀錄顯示筆數與條件，訂單狀態異動顯示異動前後的狀態，IP 變更驗證與出款審核顯示 debug_info */
function memoText(row: OperateLogRow) {
  if (row.type === 'export_data') {
    return exportMemoText(row);
  }

  const statusChange = orderStatusChangeText(row);
  if (statusChange) {
    return `${tl('status')}: ${statusChange}`;
  }

  // 工單4498：帳密正確但登入 IP 不在白名單，舊後端在 debug_info 寫 reason=ip_not_in_whitelist
  if (
    row.type === 'agent_login_fail' &&
    (row.debug_info ?? '').includes('reason=ip_not_in_whitelist')
  ) {
    return tl('login_ip_not_in_whitelist');
  }

  return [
    'update_ip_change_email_verify',
    'update_ip_change_mobile_verify',
    'withdrawal_review',
  ].includes(row.type ?? '')
    ? (row.debug_info ?? '')
    : '';
}

function handleClear() {
  Object.assign(filters, defaultFilters());
}

const exporting = ref(false);
/** 匯出 Excel：欄位對齊舊版 ajax_excel.php excelAgentOperationLogs（6 欄） */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) return;
  exporting.value = true;
  try {
    const params = {
      end_time: toApiTime(end),
      operator_username: filters.operator_username,
      page: 1,
      per_page: 5000,
      search_ip: filters.search_ip,
      start_time: toApiTime(start),
      target_username: filters.target_username,
      type: filters.type,
    };
    const res = await getAgentOperateLogsApi(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await exportExcel({
      audit: { conditions: params },
      columns: [
        { title: tl('time'), width: 30 },
        { title: tl('type'), width: 30 },
        { title: tl('operator'), width: 30 },
        { title: tl('update_target'), width: 30 },
        { title: tl('ip'), width: 30 },
        { title: tl('memo'), width: 60 },
      ],
      rows: rows.map((r) => [
        displayTime(r.created_at),
        r.type_name,
        r.operator,
        targetText(r),
        r.ip,
        memoText(r),
      ]),
      title: tl('menu_agent_operate_logs'),
    });
  } finally {
    exporting.value = false;
  }
}

// 頁面載入即查詢
onMounted(() => {
  loadData();
  getOperateLogTypesApi()
    .then((d) => {
      types.value = d ?? [];
    })
    .catch(() => {});
});
</script>

<template>
  <Page :title="`${tl('agnet_management')} > ${tl('menu_agent_operate_logs')}`">
    <!-- 查詢 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('type')">
            <ElSelect v-model="filters.type" class="w-full" filterable>
              <ElOption :label="tl('all')" value=" " />
              <ElOption
                v-for="t in types"
                :key="t.value"
                :label="t.text"
                :value="t.value"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('ip')">
            <div class="w-full">
              <ElInput v-model="filters.search_ip" clearable />
              <div class="mt-1 text-xs text-red-500">* {{ tl('use_ip') }}</div>
            </div>
          </ElFormItem>
          <ElFormItem :label="`${tl('operator')} (${tl('agent')})`">
            <ElInput v-model="filters.operator_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('update_target')">
            <ElInput v-model="filters.target_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('search_time')">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
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

    <!-- 列表 -->
    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('id')" prop="id" width="90" />
        <ElTableColumn :label="tl('time')" width="170">
          <template #default="{ row }">
            {{ displayTime(row.created_at) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('type')" prop="type_name" min-width="140" />
        <ElTableColumn :label="tl('operator')" prop="operator" min-width="110">
          <template #default="{ row }">
            <AgentAccount :username="row.operator" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('update_target')" min-width="140">
          <template #default="{ row }">
            <span class="break-words">{{
              targetText(row as OperateLogRow)
            }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('ip')" prop="ip" width="130" />
        <ElTableColumn :label="tl('memo')" min-width="150">
          <template #default="{ row }">
            <span class="break-words">{{
              memoText(row as OperateLogRow)
            }}</span>
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
