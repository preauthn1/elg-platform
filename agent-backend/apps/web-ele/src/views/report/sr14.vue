<script lang="ts" setup>
import type { Sr14Row } from '#/api';

import { onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getEventActionsApi, getSr14Api } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import {
  amountThousands,
  buildPlainAmounts,
  toHalfWidth,
} from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import Sr14BetDetailDialog from './components/sr14-bet-detail-dialog.vue';

defineOptions({ name: 'ReportSr14' });

const PER_PAGE = 20;

const betDetailRef = ref<InstanceType<typeof Sr14BetDetailDialog>>();

/** action_id -> event_action.* 翻譯 key（對齊舊版 sr14.php 的 $actions 對照表） */
const eventActionMap = ref<Record<string, string>>({});

const filters = reactive({
  member_username: '',
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<Sr14Row>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      // sr14 API 時間格式為 Y-m-d H:i（不帶秒）
      return getSr14Api({
        end_time: toApiTime(end ?? ''),
        member_username: toHalfWidth(filters.member_username),
        page,
        per_page,
        start_time: toApiTime(start ?? ''),
      });
    },
    {
      guard: () => {
        // 會員帳號必填
        if (!filters.member_username) {
          ElMessage.warning(tl('required_column'));
          return false;
        }
        const [start, end] = filters.time_range ?? [];
        return Boolean(start && end);
      },
      perPage: PER_PAGE,
      showError: true,
    },
  );

const fmtAmount = (v?: number | string) => amountThousands(v, 2, '-');

/** 異動點數：type 27 取負數 */
function changePoint(row: Sr14Row) {
  const p = Number(row.point ?? 0);
  return row.type === 27 ? p * -1 : p;
}

interface MemoLines {
  line1: string;
  line2?: string;
}

/** 備註欄位（畫面顯示，對齊舊版 sr14.php 的兩行格式） */
function memoLines(row: Sr14Row): MemoLines {
  if (row.type === 1 || row.type === 2) return { line1: row.vendor_name ?? '' };
  if (row.type === 17) return { line1: tl('reward_1') };
  const remark = row.remark ?? '';
  if (row.type === 13) {
    try {
      const info = JSON.parse(remark) as {
        action_id?: number | string;
        event_id?: number | string;
        point?: number | string;
        title?: string;
      };
      const actionKey = eventActionMap.value[String(info.action_id)];
      const actionText = actionKey ? tl(`event_action.${actionKey}`) : '';
      return {
        line1: info.title ?? remark,
        line2: `(ID:${info.event_id}) ${actionText} over ${info.point}`,
      };
    } catch {
      return { line1: remark };
    }
  }
  if (row.type === 70 || row.type === 71) {
    try {
      const info = JSON.parse(remark) as {
        event_v2_detail_id?: number | string;
        event_v2_name?: string;
        name?: string;
      };
      return {
        line1: info.name ?? remark,
        line2: `(ID:${info.event_v2_detail_id}) ${info.event_v2_name ?? ''}`,
      };
    } catch {
      return { line1: remark };
    }
  }
  return { line1: remark };
}

/** 備註欄位（Excel 匯出，對齊舊版 ajax.php sr14()：只有 type 1/2 顯示 vendor_name，其餘為原始 remark） */
function memoTextForExport(row: Sr14Row) {
  return row.type === 1 || row.type === 2
    ? (row.vendor_name ?? '')
    : (row.remark ?? '');
}

const exporting = ref(false);
/** 匯出 Excel：欄位（9 欄；值沿用畫面已驗格式） */
async function handleExport() {
  if (!filters.member_username) {
    ElMessage.warning(tl('required_column'));
    return;
  }
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) return;
  exporting.value = true;
  try {
    const params = {
      end_time: toApiTime(end),
      member_username: toHalfWidth(filters.member_username),
      page: 1,
      per_page: 100_000,
      start_time: toApiTime(start),
    };
    const res = await getSr14Api(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await buildPlainAmounts(() =>
      exportExcel(
        `sr14_${Date.now()}`,
        [
          tl('date'),
          tl('agent_account'),
          tl('member_account'),
          tl('before_point'),
          tl('change_point'),
          tl('moment_point'),
          tl('type'),
          tl('status'),
          tl('memo'),
        ],
        rows.map((r) => [
          fromApiTime(r.created_at),
          r.agent_username,
          r.member_username,
          fmtAmount(r.before_balance),
          fmtAmount(changePoint(r)),
          fmtAmount(r.balance),
          tl(`transaction_type.${r.type}`),
          r.status_text,
          memoTextForExport(r),
        ]),
        {
          audit: { conditions: params },
          dateRange: filters.time_range as [string, string],
          title: tl('menu_report_sr14'),
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}

// 支援帶 query 進入
const route = useRoute();
onMounted(async () => {
  try {
    const actions = await getEventActionsApi();
    eventActionMap.value = Object.fromEntries(
      (actions ?? []).map((a) => [String(a.value), a.parameter]),
    );
  } catch {
    // 動作對照表載入失敗不影響備註主要內容顯示
  }

  const q = route.query;
  if (q.member_username) {
    filters.member_username = String(q.member_username);
    if (q.start_time && q.end_time) {
      filters.time_range = [String(q.start_time), String(q.end_time)];
    }
    loadData();
  }
});
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('menu_report_sr14')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('member_account')" required>
            <ElInput v-model="filters.member_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('excel') }}
          </ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :formatter="(row: Sr14Row) => fromApiTime(row.created_at)"
          :label="tl('date')"
          width="160"
        />
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
        <ElTableColumn :label="tl('before_point')" align="right" width="120">
          <template #default="{ row }">
            {{ fmtAmount(row.before_balance) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('change_point')" align="right" width="120">
          <template #default="{ row }">
            <span :class="{ 'text-red-500': changePoint(row) < 0 }">
              {{ fmtAmount(changePoint(row)) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('moment_point')" align="right" width="120">
          <template #default="{ row }">{{ fmtAmount(row.balance) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('type')" width="130">
          <template #default="{ row }">
            {{ tl(`transaction_type.${row.type}`) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" width="110">
          <template #default="{ row }">
            <span
              :class="row.is_success_status ? '' : 'font-bold text-red-500'"
            >
              {{ row.status_text }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" min-width="180">
          <template #default="{ row }">
            <div class="break-words">
              <div>{{ memoLines(row).line1 }}</div>
              <div
                v-if="memoLines(row).line2"
                class="text-muted-foreground text-xs"
              >
                {{ memoLines(row).line2 }}
              </div>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="90" fixed="right">
          <template #default="{ row }">
            <ElButton
              v-if="row.type === 27"
              size="small"
              type="primary"
              @click="
                betDetailRef?.open(
                  row.transaction_id,
                  filters.time_range?.[0] ?? '',
                  filters.time_range?.[1] ?? '',
                )
              "
            >
              {{ tl('detail') }}
            </ElButton>
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

    <Sr14BetDetailDialog ref="betDetailRef" />
  </Page>
</template>
