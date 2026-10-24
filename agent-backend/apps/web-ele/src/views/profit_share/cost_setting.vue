<script lang="ts" setup>
import type { ProfitShareCostRow } from '#/api';

import { onMounted } from 'vue';
import { useRouter } from 'vue-router';

import { Page } from '@vben/common-ui';
import { IconifyIcon } from '@vben/icons';

import {
  ElButton,
  ElCard,
  ElMessage,
  ElMessageBox,
  ElTable,
  ElTableColumn,
  ElTooltip,
} from 'element-plus';

import {
  confirmAgentPasswordApi,
  getProfitShareCostApi,
  profitShareCalcApi,
  profitSharePayoutApi,
  profitShareReleaseApi,
  profitShareResetApi,
  profitShareRevokeReportApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

defineOptions({ name: 'ProfitShareCostSetting' });

const PER_PAGE = 10;
const router = useRouter();

const { handlePageChange, list, loadData, loading, page, total } =
  useListQuery<ProfitShareCostRow>(
    ({ page, per_page }) => getProfitShareCostApi({ page, per_page }),
    { perPage: PER_PAGE },
  );

/** status==1：開佔成報表（帶日期區間） */
function gotoReport(row: ProfitShareCostRow) {
  router.push({
    path: '/profit_share/report',
    query: { end_date: row.summary_time, start_date: row.summary_time },
  });
}

/** 確認對話框（顯示帳號+日期） */
async function confirmRow(title: string, row: ProfitShareCostRow) {
  try {
    await ElMessageBox.confirm(
      `${tl('vendor')}: ${row.agent_username ?? ''}${String.fromCodePoint(0x30_00)}${tl('date')}: ${
        row.summary_time ?? ''
      }`,
      `${title} - ${tl('are_you_sure')}`,
      {
        confirmButtonText: tl('confirm'),
        cancelButtonText: tl('cancel'),
        type: 'warning',
      },
    );
    return true;
  } catch {
    return false;
  }
}

/** 先驗證登入密碼，通過才回 true */
async function withPassword(): Promise<boolean> {
  try {
    const { value } = await ElMessageBox.prompt(
      `${tl('auth')}${tl('password')}`,
      `${tl('auth')}${tl('password')}`,
      {
        confirmButtonText: tl('confirm'),
        cancelButtonText: tl('cancel'),
        inputType: 'password',
      },
    );
    const res = await confirmAgentPasswordApi(value);
    if (Number(res.code) === 1000) return true;
    ElMessage.error(`${tl('password')}${tl('error')}`);
    return false;
  } catch {
    return false;
  }
}

async function handleSettle(row: ProfitShareCostRow) {
  if (!(await confirmRow(tl('settle'), row))) return;
  const res = await profitShareCalcApi({
    agent_id: row.agent_id ?? '',
    payout_record_id: row.payout_record_id ?? '',
    summary_time: row.summary_time ?? '',
  });
  reportResult(res, tl('settle'));
}

async function handleRelease(row: ProfitShareCostRow) {
  if (!(await withPassword())) return;
  if (!(await confirmRow(tl('release'), row))) return;
  const res = await profitShareReleaseApi({
    agent_id: row.agent_id ?? '',
    payout_record_id: row.payout_record_id ?? '',
    summary_time: row.summary_time ?? '',
  });
  reportResult(res, tl('release'));
}

async function handlePayout(row: ProfitShareCostRow) {
  if (!(await withPassword())) return;
  if (!(await confirmRow(tl('payout'), row))) return;
  const res = await profitSharePayoutApi({
    agent_id: row.agent_id ?? '',
    payout_record_id: row.payout_record_id ?? '',
    summary_time: row.summary_time ?? '',
  });
  reportResult(res, tl('payout'));
}

async function handleRevoke(row: ProfitShareCostRow) {
  if (!(await withPassword())) return;
  if (!(await confirmRow(tl('revoke'), row))) return;
  const res = await profitShareRevokeReportApi({
    payout_record_id: row.payout_record_id ?? '',
  });
  reportResult(res, tl('revoke'));
}

async function handleReset(row: ProfitShareCostRow) {
  if (!(await withPassword())) return;
  if (!(await confirmRow(tl('reset'), row))) return;
  const res = await profitShareResetApi({
    agent_id: row.agent_id ?? '',
    payout_record_id: row.payout_record_id ?? '',
    summary_time: row.summary_time ?? '',
  });
  reportResult(res, tl('reset'));
}

function reportResult(
  res: { code: number | string; message: string },
  title: string,
) {
  if (Number(res.code) === 1000) {
    ElMessage.success(`${title} ${tl('success')}`);
    loadData();
  } else {
    ElMessage.error(res.message || tl('failure'));
  }
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('menu_profit_share_management')} > ${tl('menu_profit_share_cost_setting')}`"
  >
    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small">
        <ElTableColumn
          :label="tl('performance_date')"
          align="center"
          prop="summary_time"
          min-width="140"
        />
        <ElTableColumn
          :label="tl('shareholder_account')"
          prop="agent_username"
          min-width="140"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('features')"
          align="center"
          min-width="240"
          fixed="right"
        >
          <template #default="{ row }">
            <div class="func-icons flex flex-wrap justify-center gap-2">
              <template v-if="Number((row as ProfitShareCostRow).status) === 1">
                <ElTooltip
                  :content="tl('shareholder_performance_query')"
                  placement="top"
                >
                  <ElButton
                    circle
                    class="btn-dark-red"
                    size="small"
                    @click="gotoReport(row as ProfitShareCostRow)"
                  >
                    <IconifyIcon icon="fa:search" />
                  </ElButton>
                </ElTooltip>
              </template>
              <template v-else>
                <ElTooltip
                  v-if="Number((row as ProfitShareCostRow).status) === 0"
                  :content="tl('settle')"
                  placement="top"
                >
                  <ElButton
                    circle
                    size="small"
                    type="primary"
                    @click="handleSettle(row as ProfitShareCostRow)"
                  >
                    <IconifyIcon icon="fa:calculator" />
                  </ElButton>
                </ElTooltip>
                <ElTooltip
                  v-if="Number((row as ProfitShareCostRow).status) === 3"
                  :content="tl('release')"
                  placement="top"
                >
                  <ElButton
                    circle
                    size="small"
                    type="success"
                    @click="handleRelease(row as ProfitShareCostRow)"
                  >
                    <IconifyIcon icon="fa:paper-plane" />
                  </ElButton>
                </ElTooltip>
                <template
                  v-if="Number((row as ProfitShareCostRow).status) === 4"
                >
                  <ElTooltip :content="tl('revoke')" placement="top">
                    <ElButton
                      circle
                      size="small"
                      type="danger"
                      @click="handleRevoke(row as ProfitShareCostRow)"
                    >
                      <IconifyIcon icon="fa:refresh" />
                    </ElButton>
                  </ElTooltip>
                  <ElTooltip :content="tl('payout')" placement="top">
                    <ElButton
                      circle
                      size="small"
                      type="danger"
                      @click="handlePayout(row as ProfitShareCostRow)"
                    >
                      <IconifyIcon icon="fa:money" />
                    </ElButton>
                  </ElTooltip>
                </template>
                <ElTooltip
                  v-if="
                    [2, 4, 5].includes(
                      Number((row as ProfitShareCostRow).status),
                    )
                  "
                  :content="tl('reset')"
                  placement="top"
                >
                  <ElButton
                    circle
                    size="small"
                    type="warning"
                    @click="handleReset(row as ProfitShareCostRow)"
                  >
                    <IconifyIcon icon="fa:undo" />
                  </ElButton>
                </ElTooltip>
              </template>
            </div>
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

<style scoped>
.func-icons :deep(.el-button) {
  margin: 0;
}

/** 業績查詢鈕：對齊舊版深紅色，非 ElButton 內建 type 色階 */
.func-icons :deep(.btn-dark-red) {
  --el-button-bg-color: #a61c1c;
  --el-button-border-color: #a61c1c;
  --el-button-hover-bg-color: #b83232;
  --el-button-hover-border-color: #b83232;
  --el-button-active-bg-color: #8a1717;
  --el-button-active-border-color: #8a1717;
  --el-button-text-color: #fff;
}
</style>
