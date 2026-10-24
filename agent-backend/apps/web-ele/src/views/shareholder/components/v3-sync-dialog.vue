<script lang="ts" setup>
import type {
  ShareholderV3DailyDataRow,
  ShareholderV3SyncStatusRow,
} from '#/api';

import { computed, ref } from 'vue';

import dayjs from 'dayjs';
import {
  ElButton,
  ElDialog,
  ElMessage,
  ElMessageBox,
  ElTag,
} from 'element-plus';

import {
  getShareholderV3ForeignDailyDataApi,
  getShareholderV3SyncStatusApi,
  syncShareholderV3ForeignPerformanceApi,
  toggleShareholderV3SiteExclusionApi,
} from '#/api';
import { tl } from '#/locales';
import { looseThousands } from '#/utils/format';

defineOptions({ name: 'ShareholderV3SyncDialog' });

const props = defineProps<{
  agentId: number | string;
  payoutRecordId: number | string;
  summaryDate: string;
}>();
const emit = defineEmits<{ success: [] }>();
const visible = defineModel<boolean>({ default: false });

/** 同步狀態：0=未同步、1=已同步、2=失敗、3=排除 */
const NOT_SYNCED = 0;
const SYNCED = 1;
const FAILED = 2;
const EXCLUDED = 3;

interface SyncRow extends ShareholderV3SyncStatusRow {
  busy?: boolean;
}

const loading = ref(false);
const syncingAll = ref(false);
const rows = ref<SyncRow[]>([]);
/** 已展開「查看數據」的站 */
const expanded = ref<Set<string>>(new Set());
const dataLoading = ref<Set<string>>(new Set());
const dataRows = ref<Map<string, ShareholderV3DailyDataRow[]>>(new Map());
const dataError = ref<Map<string, string>>(new Map());
/** 本次開啟是否有成功動作，關閉時通知父層重載 */
let dirty = false;

const DATA_COLS: Array<{
  key: keyof ShareholderV3DailyDataRow;
  num: boolean;
  suffix?: string;
  titleKey: string;
}> = [
  { key: 'currency', num: false, titleKey: 'currency' },
  { key: 'category_name', num: false, titleKey: 'category' },
  { key: 'turnover', num: true, titleKey: 'total_bet' },
  { key: 'win', num: true, titleKey: 'total_lose' },
  { key: 'win_lose', num: true, titleKey: 'net_profit' },
  { key: 'turnover_usd', num: true, suffix: '(USD)', titleKey: 'total_bet' },
  { key: 'win_usd', num: true, suffix: '(USD)', titleKey: 'total_lose' },
  { key: 'win_lose_usd', num: true, suffix: '(USD)', titleKey: 'net_profit' },
];

const dialogTitle = computed(
  () => `${tl('sync_fetch_data')} - ${props.summaryDate}`,
);

function statusOf(row: SyncRow) {
  return Number(row.sync_status ?? NOT_SYNCED);
}
function siteLabel(row: SyncRow) {
  return row.site_name === 'local' ? tl('local_site') : (row.site_name ?? '');
}
function statusText(row: SyncRow) {
  switch (statusOf(row)) {
    case EXCLUDED: {
      return tl('excluded');
    }
    case FAILED: {
      return tl('failure');
    }
    case SYNCED: {
      return tl('synced');
    }
    default: {
      return tl('not_synced');
    }
  }
}
function statusType(row: SyncRow) {
  switch (statusOf(row)) {
    case EXCLUDED: {
      return 'warning' as const;
    }
    case FAILED: {
      return 'danger' as const;
    }
    case SYNCED: {
      return 'success' as const;
    }
    default: {
      return 'info' as const;
    }
  }
}

const showFetch = (row: SyncRow) =>
  [FAILED, NOT_SYNCED, SYNCED].includes(statusOf(row));
const showViewData = (row: SyncRow) =>
  statusOf(row) === SYNCED ||
  (statusOf(row) === EXCLUDED && Boolean(row.sync_time));
const showExclude = (row: SyncRow) => statusOf(row) === SYNCED;
const showUnexclude = (row: SyncRow) => statusOf(row) === EXCLUDED;

async function load() {
  loading.value = true;
  dirty = false;
  expanded.value = new Set();
  dataRows.value = new Map();
  dataError.value = new Map();
  try {
    const res = await getShareholderV3SyncStatusApi({
      agent_id: props.agentId,
      date: dayjs(props.summaryDate).format('YYYY-MM-DD'),
      payout_record_id: props.payoutRecordId,
    });
    if (Number(res.code) === 1000) {
      rows.value = (res.data ?? []).map((r) => ({ ...r }));
    } else {
      rows.value = [];
      ElMessage.error(res.message);
    }
  } catch {
    // 別留著上一個日期的子站狀態
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

/** 開始同步：收合已展開的明細，免得留著同步前的舊數字 */
function collapseDetail(row: SyncRow) {
  const key = String(row.id);
  if (!expanded.value.has(key)) return;
  const next = new Set(expanded.value);
  next.delete(key);
  expanded.value = next;
}

/** 依同步結果就地更新該列（排除時收合明細） */
function applyResult(row: SyncRow, status: number, syncTime?: null | string) {
  row.sync_status = status;
  if (syncTime) row.sync_time = syncTime;
  if (status === EXCLUDED) expanded.value.delete(String(row.id));
}

function nowText() {
  return dayjs().format('YYYY-MM-DD HH:mm:ss');
}

async function doSync(row: SyncRow) {
  row.busy = true;
  collapseDetail(row);
  try {
    const res = await syncShareholderV3ForeignPerformanceApi({
      date: props.summaryDate,
      site_id: row.id,
    });
    const item = Number(res.code) === 1000 ? res.data?.[0] : undefined;
    const ok = Number(item?.status) === SYNCED;
    applyResult(
      row,
      ok ? SYNCED : FAILED,
      item?.end_time ?? (ok ? nowText() : null),
    );
    if (ok) dirty = true;
    else if (res.message) ElMessage.error(res.message);
  } catch {
    applyResult(row, FAILED);
  } finally {
    row.busy = false;
  }
}

async function syncAll() {
  syncingAll.value = true;
  const targets = rows.value.filter((r) => showFetch(r));
  for (const r of targets) {
    r.busy = true;
    collapseDetail(r);
  }
  try {
    const res = await syncShareholderV3ForeignPerformanceApi({
      date: props.summaryDate,
      site_id: 0,
    });
    const byId = new Map(
      (Number(res.code) === 1000 ? (res.data ?? []) : []).map((d) => [
        String(d.site_id),
        d,
      ]),
    );
    for (const r of targets) {
      const item = byId.get(String(r.id));
      const ok = Number(item?.status) === SYNCED;
      applyResult(
        r,
        ok ? SYNCED : FAILED,
        item?.end_time ?? (ok ? nowText() : null),
      );
    }
    if (Number(res.code) === 1000) dirty = true;
    else ElMessage.error(res.message);
  } catch {
    for (const r of targets) applyResult(r, FAILED);
    ElMessage.error(tl('failure'));
  } finally {
    for (const r of targets) r.busy = false;
    syncingAll.value = false;
  }
}

async function setExclude(row: SyncRow, exclude: boolean, remark = '') {
  row.busy = true;
  try {
    const res = await toggleShareholderV3SiteExclusionApi({
      date: props.summaryDate,
      exclude: exclude ? 1 : 0,
      remark,
      site_id: row.id,
    });
    if (Number(res.code) === 1000) {
      // 取消排除後回到已同步（後端資料仍在）
      applyResult(row, exclude ? EXCLUDED : SYNCED);
      dirty = true;
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    row.busy = false;
  }
}

async function doExclude(row: SyncRow) {
  try {
    const { value } = await ElMessageBox.prompt(
      tl('exclude_reason'),
      `${tl('exclude')} - ${siteLabel(row)}`,
      {
        inputErrorMessage: tl('please_enter_reason'),
        inputPlaceholder: tl('please_enter_reason'),
        inputType: 'textarea',
        inputValidator: (v) =>
          Boolean((v ?? '').trim()) && (v ?? '').length <= 200,
      },
    );
    await setExclude(row, true, (value ?? '').trim());
  } catch {
    /* cancelled */
  }
}

async function toggleData(row: SyncRow) {
  const key = String(row.id);
  if (expanded.value.has(key)) {
    expanded.value.delete(key);
    expanded.value = new Set(expanded.value);
    return;
  }
  expanded.value = new Set(expanded.value).add(key);
  dataLoading.value = new Set(dataLoading.value).add(key);
  dataError.value.delete(key);
  try {
    const res = await getShareholderV3ForeignDailyDataApi({
      date: props.summaryDate,
      site_id: row.id,
    });
    if (Number(res.code) === 1000) {
      dataRows.value = new Map(dataRows.value).set(key, res.data ?? []);
    } else {
      dataError.value = new Map(dataError.value).set(
        key,
        res.message || tl('failure'),
      );
    }
  } catch {
    dataError.value = new Map(dataError.value).set(key, tl('failure'));
  } finally {
    dataLoading.value.delete(key);
    dataLoading.value = new Set(dataLoading.value);
  }
}

function onOpen() {
  load();
}
function onClosed() {
  if (dirty) emit('success');
}
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="dialogTitle"
    width="90%"
    @closed="onClosed"
    @open="onOpen"
  >
    <div class="mb-3">
      <ElButton
        :loading="syncingAll"
        class="!w-full"
        type="warning"
        @click="syncAll"
      >
        {{ tl('sync_all') }}
      </ElButton>
    </div>
    <div v-loading="loading" class="overflow-x-auto">
      <table
        class="w-full border-collapse border text-sm [&_td]:border [&_td]:p-2 [&_th]:border [&_th]:p-2"
      >
        <thead>
          <tr>
            <th>{{ tl('site') }}</th>
            <th>{{ tl('last_sync_time') }}</th>
            <th>{{ tl('status') }}</th>
            <th>{{ tl('features') }}</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="row in rows" :key="row.id">
            <tr>
              <td>{{ siteLabel(row) }}</td>
              <td class="text-center">
                {{ row.sync_time || '-' }}
              </td>
              <td class="text-center">
                <ElTag v-if="row.busy" size="small" type="warning">
                  {{ tl('syncing') }}
                </ElTag>
                <ElTag v-else :type="statusType(row)" size="small">
                  {{ statusText(row) }}
                </ElTag>
              </td>
              <td class="text-center">
                <div class="flex flex-wrap justify-center gap-2">
                  <ElButton
                    v-if="showFetch(row)"
                    :loading="row.busy"
                    size="small"
                    type="primary"
                    @click="doSync(row)"
                  >
                    {{ tl('fetch') }}
                  </ElButton>
                  <ElButton
                    v-if="showViewData(row)"
                    size="small"
                    type="info"
                    @click="toggleData(row)"
                  >
                    {{ tl('view_data') }}
                  </ElButton>
                  <ElButton
                    v-if="showExclude(row)"
                    :loading="row.busy"
                    size="small"
                    type="warning"
                    @click="doExclude(row)"
                  >
                    {{ tl('exclude') }}
                  </ElButton>
                  <ElButton
                    v-if="showUnexclude(row)"
                    :loading="row.busy"
                    size="small"
                    @click="setExclude(row, false)"
                  >
                    {{ tl('unexclude') }}
                  </ElButton>
                </div>
              </td>
            </tr>
            <tr v-if="expanded.has(String(row.id))">
              <td colspan="4">
                <div
                  v-loading="dataLoading.has(String(row.id))"
                  class="min-h-8 overflow-x-auto"
                >
                  <p
                    v-if="dataError.get(String(row.id))"
                    class="p-2 text-center text-red-500"
                  >
                    {{ dataError.get(String(row.id)) }}
                  </p>
                  <p
                    v-else-if="
                      (dataRows.get(String(row.id)) ?? []).length === 0
                    "
                    class="text-muted-foreground p-2 text-center"
                  >
                    {{ tl('no_data') }}
                  </p>
                  <table
                    v-else
                    class="w-full min-w-[600px] border-collapse border text-xs [&_td]:border [&_td]:p-1 [&_th]:border [&_th]:p-1"
                  >
                    <thead>
                      <tr>
                        <th
                          v-for="c in DATA_COLS"
                          :key="c.key"
                          class="text-center"
                        >
                          {{ tl(c.titleKey) }}{{ c.suffix ?? '' }}
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr
                        v-for="(d, i) in dataRows.get(String(row.id)) ?? []"
                        :key="i"
                      >
                        <td
                          v-for="c in DATA_COLS"
                          :key="c.key"
                          class="text-right"
                        >
                          <span
                            v-if="c.num"
                            :class="
                              Number(d[c.key]) < 0
                                ? 'text-red-500'
                                : Number(d[c.key]) > 0
                                  ? 'text-blue-500'
                                  : ''
                            "
                          >
                            {{ looseThousands(d[c.key], 4) }}
                          </span>
                          <span v-else>{{ d[c.key] ?? '-' }}</span>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </ElDialog>
</template>
