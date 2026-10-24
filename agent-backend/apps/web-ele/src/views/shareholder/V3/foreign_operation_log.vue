<script lang="ts" setup>
import type { ShareholderV3OperationLogRow, ShareholderV3SiteRow } from '#/api';

import { computed, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import {
  getShareholderV3OperationLogsApi,
  getShareholderV3SiteListApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';

defineOptions({ name: 'ShareholderV3ForeignOperationLog' });

const PER_PAGE = 20;

/** 動作下拉與 badge 色 */
const ACTIONS: Array<{
  effect: 'dark' | 'light' | 'plain';
  labelKey: string;
  type: 'info' | 'primary' | 'warning';
  value: string;
}> = [
  { effect: 'light', labelKey: 'action_sync', type: 'primary', value: 'sync' },
  {
    effect: 'light',
    labelKey: 'action_exclude',
    type: 'warning',
    value: 'exclude',
  },
  {
    effect: 'plain',
    labelKey: 'action_cancel_exclude',
    type: 'info',
    value: 'cancel_exclude',
  },
  { effect: 'dark', labelKey: 'action_push', type: 'primary', value: 'push' },
];

const filters = reactive({
  action: '',
  site_id: '' as number | string,
  time_range: [
    dayjs().format('YYYY-MM-DD 00:00'),
    dayjs().format('YYYY-MM-DD 23:59'),
  ] as string[],
});

const sites = ref<ShareholderV3SiteRow[]>([]);

// foreign_operation_logs 一次回全部、沒有 pagination，分頁在前端做
const loading = ref(false);
const rows = ref<ShareholderV3OperationLogRow[]>([]);
const { handlePageChange, page, pagedRows, total } = useClientTable(rows, {
  perPage: PER_PAGE,
});

async function loadData() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) return;
  loading.value = true;
  try {
    const res = await getShareholderV3OperationLogsApi({
      end_time: `${end}:59`,
      start_time: `${start}:00`,
      ...(filters.action ? { action: filters.action } : {}),
      ...(filters.site_id ? { site_id: filters.site_id } : {}),
    });
    if (Number(res.code) === 1000) {
      rows.value = res.data ?? [];
    } else {
      rows.value = [];
      ElMessage.error(res.message);
    }
  } finally {
    loading.value = false;
  }
}

/** 查詢：回到第 1 頁再重載 */
function search() {
  handlePageChange(1);
  return loadData();
}

const title = computed(
  () =>
    `${tl('menu_shareholder')} > ${tl('menu_shareholder_foreign_operation_logV3')}`,
);

function actionLabel(action?: string) {
  const found = ACTIONS.find((a) => a.value === action);
  return found ? tl(found.labelKey) : (action ?? '');
}
function actionTagType(action?: string) {
  return ACTIONS.find((a) => a.value === action)?.type ?? 'info';
}
function actionTagEffect(action?: string) {
  return ACTIONS.find((a) => a.value === action)?.effect ?? 'plain';
}

/** 排除的原因寫在 request.remark，直接顯示不開彈窗 */
function excludeRemark(row: ShareholderV3OperationLogRow) {
  const req = row.request;
  if (!req || typeof req !== 'object' || Array.isArray(req)) return '';
  return String((req as Record<string, unknown>).remark ?? '');
}

interface DetailBlock {
  label: string;
  text: string;
}

/** 詳情區塊：cancel_exclude 不給、exclude 走 remark，其餘組 URL/狀態碼/req/res */
function detailBlocks(row: ShareholderV3OperationLogRow): DetailBlock[] {
  if (row.action === 'cancel_exclude' || row.action === 'exclude') return [];
  const asText = (v: unknown) =>
    typeof v === 'object' ? JSON.stringify(v, null, 2) : String(v);
  const raw: Array<[string, unknown]> = [
    ['URL', row.url],
    ['HTTP Status Code', row.http_status],
    ['Request', row.request],
    ['Response', row.response],
  ];
  return raw
    .filter(([, v]) => v !== null && v !== undefined && v !== '' && v !== false)
    .map(([label, v]) => ({ label, text: asText(v) }));
}

const detailVisible = ref(false);
const detail = ref<DetailBlock[]>([]);

function openDetail(row: ShareholderV3OperationLogRow) {
  detail.value = detailBlocks(row);
  detailVisible.value = true;
}

async function loadSites() {
  const res = await getShareholderV3SiteListApi({ per_page: 999 }).catch(
    () => null,
  );
  sites.value = res && Number(res.code) === 1000 ? (res.data ?? []) : [];
}

loadSites();
loadData();
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('between')">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
          <ElFormItem :label="tl('site_name')">
            <ElSelect
              v-model="filters.site_id"
              :placeholder="tl('please_select')"
              class="!w-64"
              clearable
            >
              <ElOption
                v-for="s in sites"
                :key="s.id"
                :label="s.site_name"
                :value="s.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('action')">
            <ElSelect
              v-model="filters.action"
              :placeholder="tl('please_select')"
              class="!w-64"
              clearable
            >
              <ElOption
                v-for="a in ACTIONS"
                :key="a.value"
                :label="tl(a.labelKey)"
                :value="a.value"
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

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="pagedRows" border size="small" stripe>
        <ElTableColumn :label="tl('time')" align="center" min-width="160">
          <template #default="{ row }">
            <span class="whitespace-nowrap">
              {{ row.created_at || row.time || '' }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('site_name')"
          prop="site_name"
          min-width="140"
        />
        <ElTableColumn :label="tl('action')" align="center" min-width="120">
          <template #default="{ row }">
            <ElTag
              :effect="actionTagEffect(row.action)"
              :type="actionTagType(row.action)"
              size="small"
            >
              {{ actionLabel(row.action) }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('http_status_code')"
          align="center"
          min-width="140"
        >
          <template #default="{ row }">
            <ElTag
              v-if="row.http_status"
              :type="Number(row.http_status) === 200 ? 'success' : 'danger'"
              size="small"
            >
              {{ Number(row.http_status) }}
            </ElTag>
            <span v-else class="text-muted-foreground">-</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('operator')" min-width="120">
          <template #default="{ row }">
            <AgentAccount :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('detail')" align="center" min-width="120">
          <template #default="{ row }">
            <span
              v-if="row.action === 'exclude'"
              class="text-muted-foreground text-xs"
            >
              {{ excludeRemark(row as ShareholderV3OperationLogRow) || '-' }}
            </span>
            <ElButton
              v-else-if="
                detailBlocks(row as ShareholderV3OperationLogRow).length > 0
              "
              size="small"
              @click="openDetail(row as ShareholderV3OperationLogRow)"
            >
              {{ tl('detail') }}
            </ElButton>
            <span v-else class="text-muted-foreground">-</span>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_data') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>

    <ElDialog v-model="detailVisible" :title="tl('detail')" width="650px">
      <div class="space-y-3">
        <div v-for="b in detail" :key="b.label">
          <div class="text-muted-foreground mb-1 text-xs font-bold">
            {{ b.label }}
          </div>
          <pre
            class="max-h-52 overflow-auto whitespace-pre-wrap break-all rounded bg-neutral-900 p-2 text-xs text-neutral-200"
            >{{ b.text }}</pre
          >
        </div>
      </div>
      <template #footer>
        <ElButton type="primary" @click="detailVisible = false">
          {{ tl('confirm') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
