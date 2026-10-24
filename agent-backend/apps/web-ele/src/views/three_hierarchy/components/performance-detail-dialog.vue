<script lang="ts" setup>
import type { ThreeHierarchyPerformanceRow } from '#/api';

import { reactive, ref } from 'vue';

import { ElButton, ElDialog, ElTable, ElTableColumn } from 'element-plus';

import { getThreeHierarchyPerformanceDetailApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands, buildPlainAmounts } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

const props = defineProps<{ showLose: boolean }>();

const PER_PAGE = 10;

const visible = ref(false);
const loading = ref(false);
const list = ref<ThreeHierarchyPerformanceRow[]>([]);
const total = ref(0);
const page = ref(1);
const ctx = reactive({
  agent_username: '',
  game_type: '' as number | string,
  summary_time: '',
  vendor_id: '' as number | string,
});

const fmt = (v?: number | string) => amountThousands(v, 2);
/** 彈窗只查單一遊戲商，ID 用開彈窗時帶入的 */
const vendorLabel = (name?: string) => `(${ctx.vendor_id}) ${name}`;

async function load() {
  loading.value = true;
  try {
    const res = await getThreeHierarchyPerformanceDetailApi({
      agent_username: ctx.agent_username,
      game_type: ctx.game_type,
      page: page.value,
      summary_time: ctx.summary_time,
      vendor_id: ctx.vendor_id,
    });
    if (Number(res.code) === 1000) {
      list.value = res.data ?? [];
      total.value = res.pagination?.total ?? 0;
    } else {
      list.value = [];
      total.value = 0;
    }
  } finally {
    loading.value = false;
  }
}

function open(args: {
  agent_username: string;
  game_type: number | string;
  summary_time: string;
  vendor_id: number | string;
}) {
  ctx.agent_username = args.agent_username;
  ctx.summary_time = args.summary_time;
  ctx.vendor_id = args.vendor_id;
  ctx.game_type = args.game_type;
  page.value = 1;
  visible.value = true;
  load();
}

function handlePageChange(p: number) {
  page.value = p;
  load();
}

const exporting = ref(false);
/** 匯出 Excel：欄位 performance_detail（依 showLose 動態 7/8 欄，首欄為 time=summary_time） */
async function handleExport() {
  exporting.value = true;
  try {
    const params = {
      agent_username: ctx.agent_username,
      game_type: ctx.game_type,
      page: 1,
      per_page: 100_000,
      summary_time: ctx.summary_time,
      vendor_id: ctx.vendor_id,
    };
    const res = await getThreeHierarchyPerformanceDetailApi(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    const headers = props.showLose
      ? [
          tl('time'),
          tl('username'),
          tl('vendor_announcement'),
          tl('game_type'),
          tl('event_action.bet'),
          `${tl('lose_point')}${tl('amount')}`,
          tl('point'),
          tl('rolling_chip'),
        ]
      : [
          tl('time'),
          tl('username'),
          tl('vendor_announcement'),
          tl('game_type'),
          tl('event_action.bet'),
          tl('point'),
          tl('rolling_chip'),
        ];
    await buildPlainAmounts(() =>
      exportExcel(
        `performance_detail_${Date.now()}`,
        headers,
        rows.map((r) =>
          props.showLose
            ? [
                ctx.summary_time,
                r.username,
                vendorLabel(r.vendor),
                r.game_type,
                fmt(r.bet),
                fmt(r.lose),
                fmt(r.point_reward),
                fmt(r.rolling_chip_reward),
              ]
            : [
                ctx.summary_time,
                r.username,
                vendorLabel(r.vendor),
                r.game_type,
                fmt(r.bet),
                fmt(r.point_reward),
                fmt(r.rolling_chip_reward),
              ],
        ),
        {
          audit: {
            // 業績查詢頁自己也有匯出，action 要分得開
            action: 'performance_detail',
            conditions: params,
          },
          title: tl('detail'),
          tzOffset: 8,
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog v-model="visible" :title="tl('detail')" width="1000">
    <div class="mb-2 flex justify-end">
      <ElButton
        :disabled="list.length === 0"
        :loading="exporting"
        size="small"
        type="success"
        @click="handleExport"
      >
        {{ tl('excel') }}
      </ElButton>
    </div>
    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn :label="tl('username')" min-width="110">
        <template #default="{ row }">
          <MemberAccountLink :username="row.username" />
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('vendor_announcement')" min-width="120">
        <template #default="{ row }">{{ vendorLabel(row.vendor) }}</template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('game_type')"
        prop="game_type"
        min-width="110"
      />
      <ElTableColumn
        :label="tl('event_action.bet')"
        align="right"
        min-width="110"
      >
        <template #default="{ row }">{{ fmt(row.bet) }}</template>
      </ElTableColumn>
      <ElTableColumn
        v-if="showLose"
        :label="`${tl('lose_point')}${tl('amount')}`"
        align="right"
        min-width="110"
      >
        <template #default="{ row }">{{ fmt(row.lose) }}</template>
      </ElTableColumn>
      <ElTableColumn :label="tl('point')" align="right" min-width="110">
        <template #default="{ row }">{{ fmt(row.point_reward) }}</template>
      </ElTableColumn>
      <ElTableColumn :label="tl('rolling_chip')" align="right" min-width="110">
        <template #default="{ row }">
          {{ fmt(row.rolling_chip_reward) }}
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
  </ElDialog>
</template>
