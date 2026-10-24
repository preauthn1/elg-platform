<script lang="ts" setup>
import type {
  ShareholderV3PushResultRow,
  ShareholderV3SiteRow,
  ShareholderV3SyncStatusRow,
} from '#/api';

import { ref } from 'vue';

import dayjs from 'dayjs';
import { ElButton, ElDialog, ElMessage, ElTag } from 'element-plus';

import {
  getShareholderV3SiteListApi,
  getShareholderV3SyncStatusApi,
  pushShareholderV3ForeignPayoutApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'ShareholderV3PushDialog' });

const props = defineProps<{
  agentId: number | string;
  payoutRecordId: number | string;
  summaryDate: string;
}>();
const visible = defineModel<boolean>({ default: false });

/** 推送狀態：0=未推送、1=推送成功、2=失敗 */
const NOT_PUSHED = 0;
const PUSH_SUCCESS = 1;
const PUSH_FAILED = 2;
/** 同步狀態 3=排除，排除的子站不推送 */
const SYNC_EXCLUDED = 3;

interface PushRow {
  busy: boolean;
  excluded: boolean;
  id: number | string;
  pushStatus: number;
  pushTime: string;
  siteName: string;
}

interface DetailBlock {
  label: string;
  text: string;
}

const loading = ref(false);
const pushingAll = ref(false);
const rows = ref<PushRow[]>([]);
const details = ref<Map<string, DetailBlock[]>>(new Map());
const expanded = ref<Set<string>>(new Set());

function asText(v: unknown) {
  return typeof v === 'object' ? JSON.stringify(v, null, 2) : String(v);
}

function blocksOf(pairs: Array<[string, unknown]>): DetailBlock[] {
  return pairs
    .filter(([, v]) => v !== null && v !== undefined && v !== '')
    .map(([label, v]) => ({ label, text: asText(v) }));
}

async function load() {
  loading.value = true;
  expanded.value = new Set();
  try {
    const [siteRes, syncRes] = await Promise.all([
      getShareholderV3SiteListApi({
        agent_id: props.agentId,
        per_page: 999,
      }).catch(() => null),
      getShareholderV3SyncStatusApi({
        agent_id: props.agentId,
        date: dayjs(props.summaryDate).format('YYYY-MM-DD'),
        payout_record_id: props.payoutRecordId,
      }).catch(() => null),
    ]);
    const sites: ShareholderV3SiteRow[] =
      siteRes && Number(siteRes.code) === 1000 ? (siteRes.data ?? []) : [];
    const syncMap = new Map<string, ShareholderV3SyncStatusRow>(
      (syncRes && Number(syncRes.code) === 1000
        ? (syncRes.data ?? [])
        : []
      ).map((r) => [String(r.id), r]),
    );
    const nextDetails = new Map<string, DetailBlock[]>();
    rows.value = sites
      .filter((s) => Number(s.status) === 1)
      .map((s) => {
        const info = syncMap.get(String(s.id));
        nextDetails.set(
          String(s.id),
          blocksOf([
            ['Request URL', info?.push_url],
            ['Request Body', info?.push_req],
            ['Response', info?.push_res],
          ]),
        );
        return {
          busy: false,
          excluded: Number(info?.sync_status) === SYNC_EXCLUDED,
          id: s.id,
          pushStatus: Number(info?.push_status ?? NOT_PUSHED),
          pushTime: info?.push_time ?? '',
          siteName: s.site_name ?? '',
        };
      });
    details.value = nextDetails;
  } finally {
    loading.value = false;
  }
}

function statusText(row: PushRow) {
  switch (row.pushStatus) {
    case PUSH_FAILED: {
      return tl('failure');
    }
    case PUSH_SUCCESS: {
      return tl('push_success');
    }
    default: {
      return tl('not_pushed');
    }
  }
}
function statusType(row: PushRow) {
  switch (row.pushStatus) {
    case PUSH_FAILED: {
      return 'danger' as const;
    }
    case PUSH_SUCCESS: {
      return 'success' as const;
    }
    default: {
      return 'info' as const;
    }
  }
}

function applyResult(row: PushRow, item: ShareholderV3PushResultRow) {
  row.pushStatus =
    Number(item.status) === PUSH_SUCCESS ? PUSH_SUCCESS : PUSH_FAILED;
  if (item.end_time) row.pushTime = item.end_time;
  const blocks = blocksOf([
    ['Message', item.message],
    ['Request URL', item.request_url],
    ['Request Body', item.request_body],
    ['Response', item.response],
  ]);
  if (blocks.length > 0) {
    details.value = new Map(details.value).set(String(row.id), blocks);
  }
}

function applyFailure(row: PushRow, message: string) {
  row.pushStatus = PUSH_FAILED;
  if (message) {
    details.value = new Map(details.value).set(
      String(row.id),
      blocksOf([['Error', message]]),
    );
  }
}

async function doPush(row: PushRow) {
  row.busy = true;
  try {
    const res = await pushShareholderV3ForeignPayoutApi({
      site_id: row.id,
      summary_time: props.summaryDate,
    });
    const item =
      Number(res.code) === 1000
        ? (res.data ?? []).find((d) => String(d.site_id) === String(row.id))
        : undefined;
    if (item) applyResult(row, item);
    else applyFailure(row, res.message ?? '');
  } catch {
    applyFailure(row, 'Request failed');
  } finally {
    row.busy = false;
  }
}

async function pushAll() {
  pushingAll.value = true;
  const targets = rows.value.filter((r) => !r.excluded);
  for (const r of targets) r.busy = true;
  try {
    const res = await pushShareholderV3ForeignPayoutApi({
      site_id: 0,
      summary_time: props.summaryDate,
    });
    if (Number(res.code) === 1000) {
      const byId = new Map((res.data ?? []).map((d) => [String(d.site_id), d]));
      for (const r of targets) {
        const item = byId.get(String(r.id));
        if (item) applyResult(r, item);
        else applyFailure(r, '');
      }
    } else {
      for (const r of targets) applyFailure(r, res.message ?? '');
      if (res.message) ElMessage.error(res.message);
    }
  } catch {
    for (const r of targets) applyFailure(r, 'Request failed');
    ElMessage.error(tl('failure'));
  } finally {
    for (const r of targets) r.busy = false;
    pushingAll.value = false;
  }
}

function toggleDetail(row: PushRow) {
  const key = String(row.id);
  const next = new Set(expanded.value);
  if (next.has(key)) next.delete(key);
  else next.add(key);
  expanded.value = next;
}
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('push')} - ${summaryDate}`"
    width="900px"
    @open="load"
  >
    <div class="mb-3">
      <ElButton
        :loading="pushingAll"
        class="!w-full"
        type="primary"
        @click="pushAll"
      >
        {{ tl('push_all') }}
      </ElButton>
    </div>
    <div v-loading="loading" class="overflow-x-auto">
      <table
        class="w-full border-collapse border text-sm [&_td]:border [&_td]:p-2 [&_th]:border [&_th]:p-2"
      >
        <thead>
          <tr>
            <th>{{ tl('site') }}</th>
            <th>{{ tl('last_push_time') }}</th>
            <th>{{ tl('status') }}</th>
            <th>{{ tl('features') }}</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="row in rows" :key="row.id">
            <tr>
              <td>{{ row.siteName }}</td>
              <td class="text-center">{{ row.pushTime || '-' }}</td>
              <td class="text-center">
                <ElTag v-if="row.busy" size="small" type="warning">
                  {{ tl('pushing') }}
                </ElTag>
                <ElTag v-else :type="statusType(row)" size="small">
                  {{ statusText(row) }}
                </ElTag>
              </td>
              <td class="text-center">
                <div class="flex flex-wrap justify-center gap-2">
                  <ElTag v-if="row.excluded" size="small" type="warning">
                    {{ tl('excluded') }}
                  </ElTag>
                  <ElButton
                    v-else
                    :loading="row.busy"
                    size="small"
                    type="primary"
                    @click="doPush(row)"
                  >
                    {{ tl('push') }}
                  </ElButton>
                  <ElButton
                    v-if="(details.get(String(row.id)) ?? []).length > 0"
                    size="small"
                    @click="toggleDetail(row)"
                  >
                    {{ tl('view_response') }}
                  </ElButton>
                </div>
              </td>
            </tr>
            <tr v-if="expanded.has(String(row.id))">
              <td colspan="4">
                <div class="space-y-2">
                  <div
                    v-for="b in details.get(String(row.id)) ?? []"
                    :key="b.label"
                  >
                    <div class="text-muted-foreground mb-1 text-xs font-bold">
                      {{ b.label }}
                    </div>
                    <pre
                      class="max-h-80 overflow-auto whitespace-pre-wrap break-all rounded bg-neutral-900 p-2 text-xs text-neutral-200"
                      >{{ b.text }}</pre
                    >
                  </div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </ElDialog>
</template>
