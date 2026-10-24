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
  getCustomizedSettingApi,
  getShareholderCostApi,
  shareholderPayoutApi,
  shareholderPreviewPayoutApi,
  shareholderReleaseApi,
  shareholderResetApi,
  shareholderRevokeReportApi,
  shareholderSetCostApi,
  shareholderSettleApi,
  shareholderV3RevokeSubSiteReportApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

import CostInputDialog from './cost-input-dialog.vue';
import EditReportDialog from './edit-report-dialog.vue';
import RankListDialog from './rank-list-dialog.vue';
import SubSiteCostDialog from './sub-site-cost-dialog.vue';
import V2EditReportDialog from './v2-edit-report-dialog.vue';

defineOptions({ name: 'ShareholderCostSettingPage' });

const props = defineProps<{
  titleKey: string;
  v2?: boolean;
}>();

const PER_PAGE = 10;
const router = useRouter();

const shareholderType = ref<number | string>(0);

const { handlePageChange, list, loadData, loading, page, total } =
  useListQuery<ShareholderCostRow>(
    ({ page }) =>
      getShareholderCostApi({
        page,
        ...((props.v2 ?? false)
          ? {}
          : { shareholder_type: shareholderType.value }),
      }),
    { perPage: PER_PAGE },
  );

const title = computed(
  () => `${tl('menu_shareholder')} > ${tl(props.titleKey)}`,
);

const v2flag = computed(() => props.v2 ?? false);

/* modals */
const rankVisible = ref(false);
const rankCtx = ref({
  id: '' as number | string,
  payoutRecordId: '' as number | string,
  summaryDate: '',
});
const editReportVisible = ref(false);
const editReportCtx = ref({
  agentId: '' as number | string,
  payoutRecordId: '' as number | string,
  summaryDate: '',
});
const subSiteCostRef = ref<InstanceType<typeof SubSiteCostDialog>>();
const costInputDialogRef = ref<InstanceType<typeof CostInputDialog>>();

async function loadShareholderType() {
  if (v2flag.value) return;
  const settings = await getCustomizedSettingApi();
  const row = (settings ?? []).find(
    (s: any) => String(s.setting_key) === '6102',
  );
  shareholderType.value = row?.value ?? 0;
}

function statusInclude(row: ShareholderCostRow, arr: number[]) {
  return arr.includes(Number(row.status));
}

/**
 * 稱桶子站的那一列。
 * 子站的成本是「主站推下來的成本 + 子站自己的成本」，寫入走
 * v3/set_sub_site_cost（累加到 original_nett_profit）；一般的 set_cost 是直接
 * 覆寫 game_profit，用在子站會把主站那段蓋掉，所以編輯與撤回都要分流。 */
function isSubSite(row: ShareholderCostRow) {
  return !v2flag.value && Number(row.sub_site_id ?? 0) > 0;
}

/** 子站已收到主站推下來的總數據，才進子站表單；否則走一般單欄成本輸入。 */
function isSubSiteReceived(row: ShareholderCostRow) {
  return !v2flag.value && Number(row.is_sub_site_received ?? 0) === 1;
}

function openCostEdit(row: ShareholderCostRow) {
  if (isSubSiteReceived(row)) subSiteCostRef.value?.open(row);
  else doEdit(row);
}

function openRank(row: ShareholderCostRow) {
  rankCtx.value = {
    id: row.id,
    payoutRecordId: row.payout_record_id ?? '',
    summaryDate: row.summary_time ?? '',
  };
  rankVisible.value = true;
}

function openEditReport(row: ShareholderCostRow) {
  editReportCtx.value = {
    agentId: row.agent_id ?? '',
    payoutRecordId: row.payout_record_id ?? '',
    summaryDate: row.summary_time ?? '',
  };
  editReportVisible.value = true;
}

function gotoPerformanceQuery(row: ShareholderCostRow) {
  const path =
    !v2flag.value && Number(shareholderType.value) === 1
      ? '/shareholder/performance_query2'
      : '/shareholder/performance_query';
  router.push({
    path,
    query: {
      start_date: row.summary_time,
      end_date: row.summary_time,
      agent_username: row.agent_username,
      act: '1',
    },
  });
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
    const res = await shareholderSetCostApi(
      {
        cost,
        date: row.summary_time ?? '',
        id: row.id,
        memo,
        payout_record_id: row.payout_record_id ?? '',
        ...(v2flag.value ? {} : { shareholder_type: shareholderType.value }),
      },
      v2flag.value,
    );
    afterAction(res);
  } catch {
    /* cancelled at final confirm */
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
    const res = await shareholderSettleApi(
      {
        agent_id: row.agent_id ?? '',
        payout_record_id: row.payout_record_id ?? '',
        summary_time: row.summary_time ?? '',
        ...(v2flag.value ? {} : { shareholder_type: shareholderType.value }),
      },
      v2flag.value,
    );
    afterAction(res);
  } finally {
    loadingInstance.close();
  }
}

/** 先驗密碼，通過才執行 action。 */
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
    // 分桶 1.0 先試算派彩金額給操作者確認；2.0 無此步驟
    let amountLine = '';
    if (!v2flag.value) {
      const preview = await shareholderPreviewPayoutApi({
        agent_id: row.agent_id ?? '',
        payout_record_id: row.payout_record_id ?? '',
        shareholder_type: shareholderType.value,
        summary_time: row.summary_time ?? '',
      });
      if (Number(preview.code) !== 1000) {
        ElMessage.error(preview.message);
        return;
      }
      amountLine = `\n${tl('payout_amount')}:${amountThousands(preview.data?.amount ?? 0, 4)}`;
    }
    await ElMessageBox.confirm(
      `${tl('vendor')}:${row.agent_username}\n${tl('date')}:${row.summary_time}${amountLine}`,
      `${tl('payout')} - ${tl('are_you_sure')}`,
      { type: 'warning' },
    );
    const res = await shareholderPayoutApi(
      {
        agent_id: row.agent_id ?? '',
        payout_record_id: row.payout_record_id ?? '',
        summary_time: row.summary_time ?? '',
        ...(v2flag.value ? {} : { shareholder_type: shareholderType.value }),
      },
      v2flag.value,
    );
    afterAction(res);
  });
}

function doRelease(row: ShareholderCostRow) {
  withPassword(async () => {
    await ElMessageBox.confirm(
      `${tl('vendor')}:${row.agent_username}\n${tl('date')}:${row.summary_time}`,
      `${tl('release')}${tl('menu_cj7_shareholder_report')} - ${tl('are_you_sure')}`,
      { type: 'warning' },
    );
    const res = await shareholderReleaseApi(
      {
        payout_record_id: row.payout_record_id ?? '',
        ...(v2flag.value
          ? { date: row.summary_time ?? '' }
          : { shareholder_type: shareholderType.value }),
      },
      v2flag.value,
    );
    afterAction(res);
  });
}

function doRevoke(row: ShareholderCostRow) {
  withPassword(async () => {
    await ElMessageBox.confirm(
      `${tl('vendor')}:${row.agent_username}\n${tl('date')}:${row.summary_time}`,
      `${tl('revoke')}${tl('menu_cj7_shareholder_report')} - ${tl('are_you_sure')}`,
      { type: 'warning' },
    );
    // 稱桶子站的報表要走子站專用撤回
    const res = await (isSubSite(row)
      ? shareholderV3RevokeSubSiteReportApi({
          payout_record_id: row.payout_record_id ?? '',
          shareholder_type: shareholderType.value,
        })
      : shareholderRevokeReportApi(
          {
            payout_record_id: row.payout_record_id ?? '',
            ...(v2flag.value
              ? { date: row.summary_time ?? '' }
              : { shareholder_type: shareholderType.value }),
          },
          v2flag.value,
        ));
    afterAction(res);
  });
}

function doReset(row: ShareholderCostRow) {
  withPassword(async () => {
    await ElMessageBox.confirm(
      `${tl('vendor')}:${row.agent_username}\n${tl('date')}:${row.summary_time}`,
      `${tl('reset')} - ${tl('are_you_sure')}`,
      { type: 'warning' },
    );
    const res = await shareholderResetApi({
      agent_id: row.agent_id ?? '',
      payout_record_id: row.payout_record_id ?? '',
      shareholder_type: shareholderType.value,
      summary_time: row.summary_time ?? '',
    });
    afterAction(res);
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

async function init() {
  await loadShareholderType();
  await loadData();
}
init();
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
        <ElTableColumn
          :label="tl('features')"
          align="center"
          min-width="240"
          fixed="right"
        >
          <template #default="{ row }">
            <div class="func-icons flex flex-wrap justify-center gap-2">
              <template v-if="Number(row.status) !== 1">
                <ElTooltip
                  v-if="
                    !v2flag &&
                    statusInclude(row as ShareholderCostRow, [2, 4, 5])
                  "
                  :content="tl('reset')"
                  placement="top"
                >
                  <ElButton
                    circle
                    size="small"
                    type="danger"
                    @click="doReset(row as ShareholderCostRow)"
                  >
                    <IconifyIcon icon="fa:refresh" />
                  </ElButton>
                </ElTooltip>
                <ElTooltip
                  v-if="statusInclude(row as ShareholderCostRow, [5])"
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
                <ElTooltip
                  v-if="statusInclude(row as ShareholderCostRow, [4, 6])"
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
                  v-if="
                    statusInclude(row as ShareholderCostRow, [2, 3, 4, 5, 6])
                  "
                  :content="tl('menu_cj7_shareholder_report')"
                  placement="top"
                >
                  <ElButton
                    circle
                    size="small"
                    type="primary"
                    @click="openEditReport(row as ShareholderCostRow)"
                  >
                    <IconifyIcon icon="fa:calendar" />
                  </ElButton>
                </ElTooltip>
                <ElTooltip
                  v-if="statusInclude(row as ShareholderCostRow, [4])"
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
                  v-if="statusInclude(row as ShareholderCostRow, [6])"
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
                  v-if="statusInclude(row as ShareholderCostRow, [0, 3, 4])"
                  :content="
                    isSubSiteReceived(row as ShareholderCostRow)
                      ? tl('edit_cost')
                      : tl('edit')
                  "
                  placement="top"
                >
                  <ElButton
                    :type="
                      isSubSiteReceived(row as ShareholderCostRow)
                        ? 'warning'
                        : 'success'
                    "
                    circle
                    size="small"
                    @click="openCostEdit(row as ShareholderCostRow)"
                  >
                    <IconifyIcon icon="fa:edit" />
                  </ElButton>
                </ElTooltip>
              </template>
              <template v-else>
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
              </template>
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
      :id="rankCtx.id"
      :payout-record-id="rankCtx.payoutRecordId"
      :summary-date="rankCtx.summaryDate"
    />
    <SubSiteCostDialog
      v-if="!v2flag"
      ref="subSiteCostRef"
      @success="loadData"
    />
    <CostInputDialog ref="costInputDialogRef" />
    <V2EditReportDialog
      v-if="v2flag"
      v-model="editReportVisible"
      :agent-id="editReportCtx.agentId"
      :payout-record-id="editReportCtx.payoutRecordId"
      :summary-date="editReportCtx.summaryDate"
      @success="loadData"
    />
    <EditReportDialog
      v-else
      v-model="editReportVisible"
      :agent-id="editReportCtx.agentId"
      :payout-record-id="editReportCtx.payoutRecordId"
      :shareholder-type="shareholderType"
      :summary-date="editReportCtx.summaryDate"
      @success="loadData"
    />
  </Page>
</template>

<style scoped>
.func-icons :deep(.el-button) {
  margin: 0;
}
</style>
