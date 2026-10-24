<script lang="ts" setup>
import type { Sr13Row } from '#/api';

import { reactive, ref } from 'vue';

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

import { getReportSr13Api } from '#/api';
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

defineOptions({ name: 'ReportSr13' });

const PER_PAGE = 20;

const filters = reactive({
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
  username: '',
});

const searched = ref(false);

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<Sr13Row>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      searched.value = true;
      // ⚠ 端點（NewReportController@sr13）自帶 -8hour，前端這裡又減一次 ——
      // 既有雙重扣減缺陷（4095-defects.md F5），查詢窗恆偏 -8h。依「缺陷另冊」
      // 原則以 basis 0 維持 T=+8 恆等；修復＝改 basis 8，會位移查詢結果需另行簽核。
      return getReportSr13Api({
        end_time: toApiTime(end, 0).slice(0, 16),
        page,
        per_page,
        start_time: toApiTime(start, 0).slice(0, 16),
        username: toHalfWidth(filters.username),
      });
    },
    {
      guard: () => {
        if (!filters.username) {
          ElMessage.warning(
            `${tl('please_input_required_column')}(${tl('agent_account')})`,
          );
          return false;
        }
        const [start, end] = filters.time_range ?? [];
        if (!start || !end) {
          ElMessage.warning(tl('between'));
          return false;
        }
        return true;
      },
      perPage: PER_PAGE,
      showError: true,
    },
  );

const fmt4 = (v?: null | number | string) => amountThousands(v, 4);

/** 是否代理對代理（source=1；否則為玩家交易） */
const isAgentToAgent = (row: Sr13Row) => Number(row.source) === 1;

/** 狀態文字。玩家交易的 status 是 API 回應碼（1000=成功），查無對照就留白不吐 key */
function statusText(row: Sr13Row) {
  if (!isAgentToAgent(row) && Number(row.status) === 1000) return tl('success');
  const key = isAgentToAgent(row)
    ? `status_${row.status}`
    : `chat_transaction_status_${row.status}`;
  const text = tl(key);
  return text === key ? '' : text;
}

/** 變動前後餘額 */
function balanceText(row: Sr13Row, v?: null | number | string) {
  return Number(row.source) === 2 ? '--' : fmt4(v);
}

const exporting = ref(false);
/** 匯出 Excel：對齊畫面 sr13 明細（11 欄）。 */
async function handleExport() {
  if (!filters.username) {
    ElMessage.warning(
      `${tl('please_input_required_column')}(${tl('agent_account')})`,
    );
    return;
  }
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return;
  }
  exporting.value = true;
  try {
    const params = {
      end_time: toApiTime(end, 0).slice(0, 16),
      page: 1,
      per_page: 100_000,
      start_time: toApiTime(start, 0).slice(0, 16),
      username: toHalfWidth(filters.username),
    };
    const res = await getReportSr13Api(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await buildPlainAmounts(() =>
      exportExcel(
        `sr13_${Date.now()}`,
        [
          tl('time'),
          tl('ordernum'),
          tl('type'),
          `${tl('agent_account')}(from)`,
          `${tl('agent_account')}(to)`,
          tl('member_account'),
          tl('before_balance'),
          tl('change_balance'),
          tl('after_balance'),
          tl('status'),
          tl('memo'),
        ],
        rows.map((r) => [
          fromApiTime(r.created_at),
          r.transaction_no,
          r.type_name,
          r.from_username,
          isAgentToAgent(r) ? r.to_username : '-',
          isAgentToAgent(r) ? '-' : r.member_username,
          balanceText(r, r.before_balance),
          fmt4(r.amount),
          balanceText(r, r.after_balance),
          statusText(r),
          r.remark,
        ]),
        {
          audit: { conditions: params },
          dateRange: filters.time_range as [string, string],
          title: tl('menu_report_sr13'),
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('menu_report_sr13')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('agent_account')" required>
            <ElInput
              v-model="filters.username"
              :placeholder="tl('required_column')"
              clearable
            />
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
            <div class="text-muted-foreground mt-1 text-xs">
              {{ tl('query_period_max_days').replaceAll('{value}', '60') }}
            </div>
          </ElFormItem>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
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

    <ElCard v-if="searched" shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :formatter="(row: Sr13Row) => fromApiTime(row.created_at)"
          :label="tl('time')"
          width="160"
        />
        <ElTableColumn :label="tl('ordernum')" min-width="170">
          <template #default="{ row }">
            <!-- 後端只回 transaction_no（無獨立 id 欄位），title 沿用同值以顯示完整字串 -->
            <span :title="String(row.transaction_no ?? '')">
              {{ row.transaction_no }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('type')" prop="type_name" min-width="110" />
        <ElTableColumn
          :label="`${tl('agent_account')}(from)`"
          prop="from_username"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.from_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="`${tl('agent_account')}(to)`" min-width="110">
          <template #default="{ row }">
            <AgentAccount
              v-if="isAgentToAgent(row as Sr13Row)"
              :username="row.to_username"
            />
            <span v-else>-</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="110">
          <template #default="{ row }">
            <span v-if="isAgentToAgent(row as Sr13Row)">-</span>
            <MemberAccountLink v-else :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('before_balance')" align="right" width="120">
          <template #default="{ row }">
            <span :title="String(row.before_balance ?? '')">
              {{ balanceText(row as Sr13Row, row.before_balance) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('change_balance')" align="right" width="120">
          <template #default="{ row }">
            <span
              :class="Number(row.amount) < 0 ? 'text-red-500' : 'text-blue-500'"
            >
              {{ fmt4(row.amount) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('after_balance')" align="right" width="120">
          <template #default="{ row }">
            <span :title="String(row.after_balance ?? '')">
              {{ balanceText(row as Sr13Row, row.after_balance) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" width="90">
          <template #default="{ row }">
            <span :title="String(row.status)">
              {{ statusText(row as Sr13Row) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" prop="remark" min-width="120" />
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
