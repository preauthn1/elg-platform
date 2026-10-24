<script lang="ts" setup>
import type { EventProgressiveJackpotEventLogRow } from '#/api';

import { ref } from 'vue';

import { ElDialog, ElTable, ElTableColumn } from 'element-plus';

import {
  getEventProgressiveJackpotAllGameTypesApi,
  getEventProgressiveJackpotEventLogApi,
} from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

import { eventLogTypeLabel, joinUserStatusLabel } from '../status-map';

defineOptions({ name: 'EventProgressiveJackpotEventLogDialog' });

const PER_PAGE = 20;

const visible = ref(false);
const loading = ref(false);
const memberId = ref<number | string>(0);
const level = ref<number | string>(0);
const list = ref<EventProgressiveJackpotEventLogRow[]>([]);
const page = ref(1);
const total = ref(0);
/** 不帶 status 篩選，避免舊紀錄對到已停用的類別查無名稱 */
const gameTypeMap = ref<Record<string, string>>({});

const isUnlock = (row: EventProgressiveJackpotEventLogRow) =>
  Number(row.type) === 1;

/** type=3（修改狀態）的變更前／後是狀態碼，其餘類型是金額 */
function changeValue(
  row: EventProgressiveJackpotEventLogRow,
  value?: null | number | string,
) {
  if (value === null || value === undefined) return '-';
  if (Number(row.type) === 3) {
    // 前後都是 0（進行中）代表新建立／開啟，本來就沒有變更前的狀態
    return Number(row.before_value) === 0 && Number(row.after_value) === 0
      ? '-'
      : joinUserStatusLabel(value);
  }
  return amountThousands(value, 2);
}

function beforeValue(row: EventProgressiveJackpotEventLogRow) {
  if (
    Number(row.type) === 3 &&
    Number(row.before_value) === 0 &&
    Number(row.after_value) === 0
  ) {
    return '-';
  }
  return changeValue(row, row.before_value);
}

async function load() {
  loading.value = true;
  try {
    const res = await getEventProgressiveJackpotEventLogApi({
      level: level.value,
      member_id: memberId.value,
      page: page.value,
      per_page: PER_PAGE,
    });
    list.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
    total.value = res.pagination?.total ?? 0;
  } finally {
    loading.value = false;
  }
}

async function open(id: number | string, tierLevel: number | string) {
  memberId.value = id;
  level.value = tierLevel;
  page.value = 1;
  visible.value = true;
  if (Object.keys(gameTypeMap.value).length === 0) {
    const types = await getEventProgressiveJackpotAllGameTypesApi().catch(
      () => [],
    );
    gameTypeMap.value = Object.fromEntries(
      (types ?? []).map((t) => [String(t.id), t.name]),
    );
  }
  await load();
}

function handlePage(p: number) {
  page.value = p;
  load();
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('menu_event_progressive_jackpot_record')"
    width="900"
    align-center
  >
    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn :label="tl('time')" prop="created_at" width="170" />
      <ElTableColumn :label="tl('type')" min-width="120">
        <template #default="{ row }">
          {{ eventLogTypeLabel(row.type) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('game_type')" min-width="110">
        <template #default="{ row }">
          {{
            isUnlock(row)
              ? (gameTypeMap[String(row.game_type)] ?? row.game_type)
              : '-'
          }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('bet')" align="right" min-width="110">
        <template #default="{ row }">
          {{ isUnlock(row) ? amountThousands(row.bet, 2) : '-' }}
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('event_progressive_jackpot_rate')"
        align="right"
        width="90"
      >
        <template #default="{ row }">
          {{
            isUnlock(row)
              ? `${amountThousands(Number(row.rate) * 100, 2)}%`
              : '-'
          }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('point')" align="right" min-width="110">
        <template #default="{ row }">
          <span
            v-if="row.point === null || row.point === undefined"
            class="text-muted-foreground"
          >
            -
          </span>
          <span
            v-else
            :class="Number(row.point) >= 0 ? 'text-green-600' : 'text-red-500'"
          >
            {{ Number(row.point) >= 0 ? '+' : ''
            }}{{ amountThousands(row.point, 2) }}
          </span>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('before_change')" align="right" min-width="110">
        <template #default="{ row }">{{ beforeValue(row) }}</template>
      </ElTableColumn>
      <ElTableColumn :label="tl('after_change')" align="right" min-width="110">
        <template #default="{ row }">
          {{ changeValue(row, row.after_value) }}
        </template>
      </ElTableColumn>
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>
    <TablePagination
      :current-page="page"
      :page-size="PER_PAGE"
      :total="total"
      layout="total, prev, pager, next"
      @current-change="handlePage"
    />
  </ElDialog>
</template>
