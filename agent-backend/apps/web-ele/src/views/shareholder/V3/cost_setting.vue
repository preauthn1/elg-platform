<script lang="ts" setup>
import type { ShareholderCostRow } from '#/api';

import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';

import { Page } from '@vben/common-ui';
import { IconifyIcon } from '@vben/icons';

import {
  ElButton,
  ElCard,
  ElLoading,
  ElMessage,
  ElMessageBox,
  ElTable,
  ElTableColumn,
  ElTooltip,
} from 'element-plus';

import {
  confirmAgentPasswordApi,
  getShareholderCostApi,
  shareholderV3PayoutApi,
  shareholderV3ReleaseApi,
  shareholderV3RevokeReportApi,
  shareholderV3SetCostApi,
  shareholderV3SettleApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

import CostInputDialog from '../components/cost-input-dialog.vue';
import RankListDialog from '../components/rank-list-dialog.vue';
import V3EditReportDialog from '../components/v3-edit-report-dialog.vue';
import V3PushDialog from '../components/v3-push-dialog.vue';
import V3SyncDialog from '../components/v3-sync-dialog.vue';

defineOptions({ name: 'ShareholderV3CostSetting' });

const PER_PAGE = 10;
/** 0:未派發 1:已派發 2:待派發 3:已輸入數據 4:已結算 5:已計算 6:已發佈數據 7:子站輸入成本 */
const PAID = 1;

const router = useRouter();

const { handlePageChange, list, loadData, loading, page, total } =
  useListQuery<ShareholderCostRow>(
    ({ page }) => getShareholderCostApi({ page }),
    { perPage: PER_PAGE },
  );

const title = computed(
  () => `${tl('menu_shareholder')} > ${tl('menu_shareholder_cost_settingV3')}`,
);

const rankVisible = ref(false);
const syncVisible = ref(false);
const pushVisible = ref(false);
const editReportVisible = ref(false);
const ctx = ref({
  agentId: '' as number | string,
  id: '' as number | string,
  payoutRecordId: '' as number | string,
  payoutStatus: 0 as number | string,
  summaryDate: '',
});
const costInputDialogRef = ref<InstanceType<typeof CostInputDialog>>();

function statusIn(row: ShareholderCostRow, arr: number[]) {
  return arr.includes(Number(row.status));
}

function setCtx(row: ShareholderCostRow) {
  ctx.value = {
    agentId: row.agent_id ?? '',
    id: row.id,
    payoutRecordId: row.payout_record_id ?? '',
    payoutStatus: row.status ?? 0,
    summaryDate: row.summary_time ?? '',
  };
}

function openRank(row: ShareholderCostRow) {
  setCtx(row);
  rankVisible.value = true;
}
function openSync(row: ShareholderCostRow) {
  setCtx(row);
  syncVisible.value = true;
}
function openPush(row: ShareholderCostRow) {
  setCtx(row);
  pushVisible.value = true;
}
function openEditReport(row: ShareholderCostRow) {
  setCtx(row);
  editReportVisible.value = true;
}

function gotoPerformanceQuery(row: ShareholderCostRow) {
  router.push({
    path: '/shareholder/performance_query',
    query: {
      act: '1',
      agent_username: row.agent_username,
      end_date: row.summary_time,
      start_date: row.summary_time,
    },
  });
}

function afterAction(res: { code: number | string; message: string }) {
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    loadData();
  } else {
    ElMessage.error(res.message);
  }
}

async function doEdit(row: ShareholderCostRow) {
  const result = await costInputDialogRef.value?.open(row);
  if (!result) return;
  const { cost, memo } = result;
  try {
    await ElMessageBox.confirm(
      `${tl('cost')}:${cost}\n${tl('memo')}:${memo}`,
      tl('are_you_sure'),
    );
    afterAction(
      await shareholderV3SetCostApi({
        cost,
        date: row.summary_time ?? '',
        id: row.id,
        memo,
        payout_record_id: row.payout_record_id ?? '',
      }),
    );
  } catch {
    /* cancelled */
  }
}

async function doSettle(row: ShareholderCostRow) {
  try {
    await ElMessageBox.confirm(
      `${tl('vendor')}:${row.agent_username}\n${tl('date')}:${row.summary_time}`,
      `${tl('settle')} - ${tl('are_you_sure')}`,
      { type: 'warning' },
    );
  } catch {
    return;
  }
  const loadingInstance = ElLoading.service({ lock: true });
  try {
    afterAction(
      await shareholderV3SettleApi({
        agent_id: row.agent_id ?? '',
        payout_record_id: row.payout_record_id ?? '',
        summary_time: row.summary_time ?? '',
      }),
    );
  } finally {
    loadingInstance.close();
  }
}

/** 先驗密碼，通過才執行 action */
async function withPassword(run: () => Promise<void>) {
  try {
    const { value: pwd } = await ElMessageBox.prompt(
      `${tl('auth')}${tl('password')}`,
      '',
      { inputType: 'password' },
    );
    const chk = await confirmAgentPasswordApi(pwd ?? '');
    if (Number(chk.code) !== 1000) {
      ElMessage.error(`${tl('password')}${tl('error')}`);
      return;
    }
    await run();
  } catch {
    /* cancelled */
  }
}

function doPayout(row: ShareholderCostRow) {
  withPassword(async () => {
    await ElMessageBox.confirm(
      `${tl('vendor')}:${row.agent_username}\n${tl('date')}:${row.summary_time}`,
      `${tl('payout')} - ${tl('are_you_sure')}`,
      { type: 'warning' },
    );
    afterAction(
      await shareholderV3PayoutApi({
        agent_id: row.agent_id ?? '',
        payout_record_id: row.payout_record_id ?? '',
        summary_time: row.summary_time ?? '',
      }),
    );
  });
}

function doRelease(row: ShareholderCostRow) {
  withPassword(async () => {
    await ElMessageBox.confirm(
      `${tl('vendor')}:${row.agent_username}\n${tl('date')}:${row.summary_time}`,
      `${tl('release')}${tl('menu_cj7_shareholder_report')} - ${tl('are_you_sure')}`,
      { type: 'warning' },
    );
    afterAction(
      await shareholderV3ReleaseApi({
        date: row.summary_time ?? '',
        payout_record_id: row.payout_record_id ?? '',
      }),
    );
  });
}

function doRevoke(row: ShareholderCostRow) {
  withPassword(async () => {
    await ElMessageBox.confirm(
      `${tl('vendor')}:${row.agent_username}\n${tl('date')}:${row.summary_time}`,
      `${tl('revoke')}${tl('menu_cj7_shareholder_report')} - ${tl('are_you_sure')}`,
      { type: 'warning' },
    );
    afterAction(
      await shareholderV3RevokeReportApi({
        date: row.summary_time ?? '',
        payout_record_id: row.payout_record_id ?? '',
      }),
    );
  });
}

loadData();
</script>

<template>
  <Page :title="title">
    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('performance_date')"
          prop="summary_time"
          align="center"
          min-width="120"
        />
        <ElTableColumn
          :label="tl('vendor')"
          prop="agent_username"
          min-width="120"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('cost')" align="right" min-width="120">
          <template #default="{ row }">
            <span
              :class="
                Number(row.game_profit) < 0 ? 'text-red-500' : 'text-blue-500'
              "
            >
              {{ amountThousands(row.game_profit, 4) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" prop="memo" min-width="120" />
        <ElTableColumn :label="tl('features')" align="center" min-width="240">
          <template #default="{ row }">
            <div class="func-icons flex flex-wrap justify-center gap-2">
              <ElTooltip :content="tl('bet_ranking')" placement="top">
                <ElButton
                  circle
                  size="small"
                  type="primary"
                  @click="openRank(row as ShareholderCostRow)"
                >
                  <IconifyIcon icon="fa:list" />
                </ElButton>
              </ElTooltip>
              <template v-if="Number(row.status) === PAID">
                <ElTooltip
                  :content="tl('shareholder_performance_query')"
                  placement="top"
                >
                  <ElButton
                    circle
                    size="small"
                    type="warning"
                    @click="gotoPerformanceQuery(row as ShareholderCostRow)"
                  >
                    <IconifyIcon icon="fa:search" />
                  </ElButton>
                </ElTooltip>
                <ElTooltip :content="tl('push')" placement="top">
                  <ElButton
                    circle
                    color="#6f42c1"
                    size="small"
                    @click="openPush(row as ShareholderCostRow)"
                  >
                    <IconifyIcon icon="fa:paper-plane" />
                  </ElButton>
                </ElTooltip>
              </template>
              <template v-else>
                <ElTooltip
                  v-if="statusIn(row as ShareholderCostRow, [0, 2, 3])"
                  :content="tl('sync_fetch_data')"
                  placement="top"
                >
                  <ElButton
                    circle
                    size="small"
                    type="warning"
                    @click="openSync(row as ShareholderCostRow)"
                  >
                    <IconifyIcon icon="fa:refresh" />
                  </ElButton>
                </ElTooltip>
                <ElTooltip
                  v-if="statusIn(row as ShareholderCostRow, [0, 3])"
                  :content="tl('edit_cost')"
                  placement="top"
                >
                  <ElButton
                    circle
                    size="small"
                    type="success"
                    @click="doEdit(row as ShareholderCostRow)"
                  >
                    <IconifyIcon icon="fa:edit" />
                  </ElButton>
                </ElTooltip>
                <ElTooltip
                  v-if="statusIn(row as ShareholderCostRow, [6])"
                  :content="tl('settle')"
                  placement="top"
                >
                  <ElButton
                    circle
                    size="small"
                    type="primary"
                    @click="doSettle(row as ShareholderCostRow)"
                  >
                    <IconifyIcon icon="fa:calculator" />
                  </ElButton>
                </ElTooltip>
                <ElTooltip
                  v-if="statusIn(row as ShareholderCostRow, [4])"
                  :content="tl('payout')"
                  placement="top"
                >
                  <ElButton
                    circle
                    size="small"
                    type="danger"
                    @click="doPayout(row as ShareholderCostRow)"
                  >
                    <IconifyIcon icon="fa:money" />
                  </ElButton>
                </ElTooltip>
                <ElTooltip
                  v-if="statusIn(row as ShareholderCostRow, [2, 3, 5, 6])"
                  :content="tl('edit_data')"
                  placement="top"
                >
                  <ElButton
                    circle
                    size="small"
                    type="info"
                    @click="openEditReport(row as ShareholderCostRow)"
                  >
                    <IconifyIcon icon="fa:table" />
                  </ElButton>
                </ElTooltip>
                <ElTooltip
                  v-if="statusIn(row as ShareholderCostRow, [4, 6])"
                  :content="`${tl('revoke')}${tl('menu_cj7_shareholder_report')}`"
                  placement="top"
                >
                  <ElButton
                    circle
                    size="small"
                    type="danger"
                    @click="doRevoke(row as ShareholderCostRow)"
                  >
                    <IconifyIcon icon="fa:safari" />
                  </ElButton>
                </ElTooltip>
                <ElTooltip
                  v-if="statusIn(row as ShareholderCostRow, [5])"
                  :content="`${tl('release')}${tl('menu_cj7_shareholder_report')}`"
                  placement="top"
                >
                  <ElButton
                    circle
                    size="small"
                    type="success"
                    @click="doRelease(row as ShareholderCostRow)"
                  >
                    <IconifyIcon icon="fa:paper-plane" />
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

    <RankListDialog
      v-model="rankVisible"
      :id="ctx.id"
      :payout-record-id="ctx.payoutRecordId"
      :payout-status="ctx.payoutStatus"
      :summary-date="ctx.summaryDate"
    />
    <V3SyncDialog
      v-model="syncVisible"
      :agent-id="ctx.agentId"
      :payout-record-id="ctx.payoutRecordId"
      :summary-date="ctx.summaryDate"
      @success="loadData"
    />
    <V3PushDialog
      v-model="pushVisible"
      :agent-id="ctx.agentId"
      :payout-record-id="ctx.payoutRecordId"
      :summary-date="ctx.summaryDate"
    />
    <V3EditReportDialog
      v-model="editReportVisible"
      :agent-id="ctx.agentId"
      :payout-record-id="ctx.payoutRecordId"
      :summary-date="ctx.summaryDate"
      @success="loadData"
    />
    <CostInputDialog ref="costInputDialogRef" />
  </Page>
</template>

<style scoped>
.func-icons :deep(.el-button) {
  margin: 0;
}
</style>
