<script lang="ts" setup>
import type { WithdrawalOrderInfo, WithdrawalRequestLogGroup } from '#/api';

import { computed, ref } from 'vue';

import { useIsMobile } from '@vben/hooks';

import {
  ElButton,
  ElDescriptions,
  ElDescriptionsItem,
  ElDialog,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getCustomizedSettingApi,
  getWithdrawalCheckFlowApi,
  getWithdrawalOrderInfoApi,
  getWithdrawalRequestLogsApi,
  getWithdrawalSr11FlowApi,
  getWithdrawalTimeApi,
  reviewWithdrawalOrderApi,
} from '#/api';
import { tl } from '#/locales';
import { hasMenuCode } from '#/router/agent-menu';
import { amountThousands } from '#/utils/format';
import { fromApiTime } from '#/utils/timezone-shift';

import MemberFlowLimitDialog from '../../member/components/flow-limit-dialog.vue';
import PayoutSelectDialog from './payout-select-dialog.vue';
import ResolveVerifyingPayoutDialog from './resolve-verifying-payout-dialog.vue';
import Sr02Limit5Dialog from './sr02-limit5-dialog.vue';
import StatusUpdateDialog from './status-update-dialog.vue';

defineOptions({ name: 'WithdrawalReviewDialog' });

const emit = defineEmits<{ reviewed: [] }>();

const visible = ref(false);
const loading = ref(false);
const submitting = ref(false);
const order = ref<WithdrawalOrderInfo>({});
const sr11 = ref<null | number | string>(0);
const times = ref<{ times?: number | string; times_limit?: number | string }>(
  {},
);
const checkFlowValue = ref<number | string>(0);
const remark = ref('');

const enableBsb = ref(false);
const bsbAlias = ref('BSB Code');
const hasVip = ref(false);

const flowLimitRef = ref<InstanceType<typeof MemberFlowLimitDialog>>();
const sr02Ref = ref<InstanceType<typeof Sr02Limit5Dialog>>();
const payoutRef = ref<InstanceType<typeof PayoutSelectDialog>>();
const statusUpdateRef = ref<InstanceType<typeof StatusUpdateDialog>>();
const resolveVerifyingRef =
  ref<InstanceType<typeof ResolveVerifyingPayoutDialog>>();

const { isMobile } = useIsMobile();
// 手機一排一項，桌面維持兩欄
const descColumn = computed(() => (isMobile.value ? 1 : 2));

/** 代理自助洗分單：建單時 member_id 固定寫 0（API_Agent WithdrawalController::createOrder）。
 * 會員提款與代理洗分共用這支彈窗，代理版的欄位順序、刪減與近 5 次明細都依這個旗標分流
 * （target/tickets#4555）；會員版一律維持原樣。 */
const isAgentOrder = computed(() => Number(order.value.member_id) === 0);

const status = computed(() => Number(order.value.status));
const currentStatusText = computed(() =>
  tl(`withdrawal_status_${order.value.status}`),
);
const netAmount = computed(
  () => Number(order.value.amount ?? 0) - Number(order.value.tax ?? 0),
);

const canMarkPaid = computed(() => hasMenuCode('withdrawal_order_mark_paid'));
const canPayout = computed(() => hasMenuCode('withdrawal_order_payout'));
const canSuccessToWaiting = computed(() =>
  hasMenuCode('withdrawal_order_success_to_waiting'),
);
const canVerifyingResolve = computed(() =>
  hasMenuCode('withdrawal_order_verifying_resolve'),
);

/** 三方往返紀錄，依代付請求分組（一張單可能送出多次代付）。
 * APIs 只回純量，往返內容一個欄位都不回。
 * 🔴 不要在這裡加「展開看內容」之類的功能——要看內容請開發查 order_request_logs 表。 */
const requestLogGroups = ref<WithdrawalRequestLogGroup[]>([]);
const requestLogsLoading = ref(false);

/** 以我方代付單號對應回代付明細那一塊；沒有紀錄時回空陣列，那一列整列不顯示
 * （還沒接上往返紀錄的金流商很多，留一列空的會被當成資料掉了） */
function logsForTransactionNo(transactionNo?: null | string) {
  if (!transactionNo) return [];
  return (
    requestLogGroups.value.find((g) => g.transaction_no === transactionNo)
      ?.logs ?? []
  );
}

function actionLabel(action: string) {
  return tl(`order_request_action_${action}`, action);
}

/** 判定結果是 `ok` 或 `failed:<原因>` 兩段，**兩段各自翻譯再接起來**（失敗：商戶餘額不足）——
 * 壓成單一句子會看不出成功失敗，也跟 DB／log 的值對不起來。
 * 原因若是三方回傳的錯誤碼（failed:MERCHANT_INSUFFICIENT_BALANCE…），值域開放、翻不完，
 * 查不到翻譯就原樣顯示。與代收訂單詳情同一套（order-detail-dialog.vue）。 */
function resultLabel(result: null | string) {
  if (!result) return '-';
  const [prefix, ...rest] = result.split(':');
  const prefixText = tl(`order_request_result_${prefix}`, prefix);
  const reason = rest.join(':');
  if (!reason) return prefixText;
  return [
    prefixText,
    tl('order_request_result_separator', ': '),
    tl(`order_request_reason_${reason}`, reason),
  ].join('');
}

/** 往返紀錄的 created_at 是裸 UTC，basis 給 0 換算成所選時區；
 * 本檔其餘 fromApiTime(order.xxx) 是另一組資料，維持預設 basis 不動。 */
function displayLogTime(v?: null | string) {
  return fromApiTime(v ?? undefined, 0) || '-';
}

async function loadRequestLogs(orderId: number | string) {
  requestLogGroups.value = [];
  requestLogsLoading.value = true;
  try {
    const res = await getWithdrawalRequestLogsApi(orderId);
    // 新 APIs 的成功碼是 200、舊 API_Agent 是 1000；同一個檔案兩種都有，兩個都要認
    if (Number(res.code) === 1000 || Number(res.code) === 200) {
      requestLogGroups.value = res.data ?? [];
    }
  } catch {
    // 查不到往返紀錄不該擋住訂單詳情本身
    requestLogGroups.value = [];
  } finally {
    requestLogsLoading.value = false;
  }
}

async function open(orderId: number | string) {
  visible.value = true;
  loading.value = true;
  order.value = {};
  remark.value = '';
  loadRequestLogs(orderId);
  try {
    const [info, flow, time, check, settings] = await Promise.all([
      getWithdrawalOrderInfoApi(orderId),
      getWithdrawalSr11FlowApi(orderId).catch(() => null),
      getWithdrawalTimeApi(orderId).catch(() => null),
      getWithdrawalCheckFlowApi(orderId).catch(() => null),
      getCustomizedSettingApi().catch(() => []),
    ]);
    if (Number(info.code) === 1000 && info.data) {
      order.value = info.data;
      remark.value = info.data.remark ?? '';
    }
    sr11.value = flow?.data?.[0]?.sr11 ?? 0;
    times.value = time?.data ?? {};
    checkFlowValue.value = check?.data?.value ?? 0;

    const map = new Map(
      (settings ?? []).map((s: any) => [Number(s.setting_key), s.value]),
    );
    enableBsb.value = Number(map.get(4011)) === 1;
    bsbAlias.value = (map.get(3015) as string) || 'BSB Code';
    hasVip.value = Number(map.get(3531)) === 1;
  } finally {
    loading.value = false;
  }
}

/** 直接審核（通過/拒絕），備註取自輸入框 */
async function reviewOrder(targetStatus: number) {
  submitting.value = true;
  try {
    const res = await reviewWithdrawalOrderApi({
      id: order.value.id ?? '',
      original_status: order.value.status ?? '',
      remark: remark.value,
      status: targetStatus,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('reviewed');
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    submitting.value = false;
  }
}

function openStatusUpdate(
  targetStatus: number,
  operateType: string,
  showAccount: boolean,
) {
  statusUpdateRef.value?.open({
    afterStatusText: tl(`withdrawal_status_${targetStatus}`),
    beforeStatusText: currentStatusText.value,
    operateType,
    orderId: order.value.id ?? '',
    originalStatus: order.value.status ?? '',
    showAccount,
    targetStatus,
  });
}

/** 代付停留單(21/22/23)人工定案：補三方單號或標記代付失敗（target/tickets#4137） */
function openResolveVerifying(
  mode: 'edit' | 'fail',
  operateType: string,
  afterStatusText: string,
) {
  resolveVerifyingRef.value?.open({
    afterStatusText,
    beforeStatusText: currentStatusText.value,
    mode,
    operateType,
    orderId: order.value.id ?? '',
    orderStatus: order.value.status ?? '',
    showStatusChange: status.value === 21,
  });
}

function onChildDone() {
  visible.value = false;
  emit('reviewed');
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('order_info')"
    width="900"
    align-center
  >
    <div v-loading="loading">
      <ElDescriptions :column="descColumn" border>
        <ElDescriptionsItem :label="isAgentOrder ? tl('ordernum') : tl('id')">
          {{ order.id }}
        </ElDescriptionsItem>
        <!-- 代理版第一列右欄放下單代理（a_username，來自 wo.agent_id）；
             會員版第三列的「代理商帳號」是會員的上級代理（agent_username），兩者不同來源 -->
        <ElDescriptionsItem v-if="isAgentOrder" :label="tl('agent_account')">
          {{ order.a_username }}
        </ElDescriptionsItem>
        <ElDescriptionsItem v-if="isAgentOrder" :label="tl('bank_code')">
          {{ order.bank_code }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('bank_name')">
          {{ order.bank_name }}
        </ElDescriptionsItem>
        <ElDescriptionsItem v-if="!isAgentOrder" :label="tl('bank_code')">
          {{ order.bank_code }}
        </ElDescriptionsItem>
        <ElDescriptionsItem v-if="!isAgentOrder" :label="tl('account_name')">
          {{ order.account_name }}
        </ElDescriptionsItem>
        <ElDescriptionsItem>
          <template #label>
            <template v-if="Number(order.bank_type) === 3">
              {{ tl('wallet_address') }}
            </template>
            <template v-else-if="enableBsb">
              {{ bsbAlias }}/{{ tl('account_no') }}
            </template>
            <template v-else>{{ tl('account_no') }}</template>
          </template>
          <template v-if="enableBsb">{{ order.bsb_code }}<br /></template>
          {{ order.account_no }}
        </ElDescriptionsItem>
        <ElDescriptionsItem v-if="isAgentOrder" :label="tl('account_name')">
          {{ order.account_name }}
        </ElDescriptionsItem>
        <ElDescriptionsItem v-if="!isAgentOrder" :label="tl('agent_account')">
          {{ order.agent_username }}
        </ElDescriptionsItem>
        <ElDescriptionsItem v-if="!isAgentOrder" :label="tl('member_account')">
          {{ order.username }}
        </ElDescriptionsItem>
        <ElDescriptionsItem v-if="!isAgentOrder" :label="tl('real_name')">
          {{ order.real_name }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('amount')">
          {{ amountThousands(order.amount) }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('handling_fee')">
          {{ order.tax }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('withdrawal_order_column_1')">
          {{ amountThousands(netAmount) }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('rate')">
          {{ order.rate }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('withdrawal_order_column_2')">
          {{ amountThousands(order.point, 4) }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('createdate')">
          {{ fromApiTime(order.created_at) }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('review_time')">
          {{ status === 1 ? '-' : order.check_time }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('operator')">
          {{ order.operator_name }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('status')">
          {{ currentStatusText }}
        </ElDescriptionsItem>
        <ElDescriptionsItem v-if="!isAgentOrder" :label="tl('menu_member_tag')">
          <template v-if="(order.member_tags ?? []).length > 0">
            <span
              v-for="(tag, i) in order.member_tags"
              :key="i"
              class="mr-1 rounded px-1"
              :style="{
                backgroundColor: tag.background_color,
                color: tag.text_color,
              }"
            >
              {{ tag.name }}
            </span>
          </template>
          <template v-else>-</template>
        </ElDescriptionsItem>
        <ElDescriptionsItem v-if="!isAgentOrder" :label="tl('flow_limit')">
          <div class="flex items-center gap-2">
            <span
              :class="
                Number(checkFlowValue) > 0 ? 'text-red-500' : 'text-green-600'
              "
            >
              {{ Number(checkFlowValue) > 0 ? tl('no_pass') : tl('passed') }}
            </span>
            <ElButton
              size="small"
              type="warning"
              @click="flowLimitRef?.open(Number(order.member_id))"
            >
              {{ tl('watch_data') }}
            </ElButton>
            <span>{{ tl('Valid_flow_limit') }}: {{ sr11 ?? 0 }}</span>
          </div>
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('menu_report_sr02_limit5')">
          <div class="flex items-center gap-2">
            <ElButton
              size="small"
              type="warning"
              @click="
                isAgentOrder
                  ? sr02Ref?.open(order.a_username ?? '', true)
                  : sr02Ref?.open(order.username ?? '')
              "
            >
              {{ tl('watch_data') }}
            </ElButton>
            <!-- 當日提現次數／上限來自 getWithdrawalTime，那是會員提款限額；
                 代理洗分單查不到，印出來會是字面的 undefined/undefined，代理版不顯示 -->
            <span v-if="hasVip && !isAgentOrder">
              {{ tl('withdrawal_used_times') }}/{{
                tl('that_day_withdrawal_amount_limit')
              }}:
              <span
                :class="
                  Number(times.times) > Number(times.times_limit)
                    ? 'text-red-500'
                    : ''
                "
              >
                {{
                  Number(times.times_limit) === -1
                    ? tl('unlimited')
                    : `${times.times}/${times.times_limit}`
                }}
              </span>
            </span>
          </div>
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('memo')" :span="descColumn">
          <ElInput
            v-if="status === 1"
            v-model="remark"
            :placeholder="tl('memo')"
            :rows="3"
            type="textarea"
          />
          <div v-else class="whitespace-pre-wrap">
            {{ order.remark }}
          </div>
        </ElDescriptionsItem>
      </ElDescriptions>

      <!-- 代付明細 -->
      <div v-if="(order.detail ?? []).length > 0" class="mt-4">
        <h4 class="mb-2 font-bold">{{ tl('label_payout_info') }}</h4>
        <div v-for="(d, i) in order.detail" :key="i" class="mb-4">
          <ElDescriptions class="mb-2" :column="descColumn" border>
            <ElDescriptionsItem :label="tl('label_payout_vendor')">
              <div>{{ d.cf_name }}</div>
              <div v-if="d.display_name">{{ d.display_name }}</div>
            </ElDescriptionsItem>
            <ElDescriptionsItem :label="tl('status')">
              <span
                :class="
                  Number(d.is_success_payout) === 1
                    ? 'text-green-600'
                    : d.callback_time
                      ? 'text-red-500'
                      : ''
                "
              >
                {{
                  Number(d.is_success_payout) === 1
                    ? tl('status_1')
                    : d.callback_time
                      ? tl('status_0')
                      : ''
                }}
              </span>
            </ElDescriptionsItem>
            <ElDescriptionsItem :label="tl('third_order_no')">
              {{ d.transaction_no }}
            </ElDescriptionsItem>
            <ElDescriptionsItem :label="tl('third_ordernum')">
              {{ d.third_party_transaction_no }}
            </ElDescriptionsItem>
            <!-- 請求代付時間取代付紀錄的 created_at（API 已轉 +8）；三方時間戳記要等三方回調才有值 -->
            <ElDescriptionsItem :label="tl('label_payout_request_time')">
              {{ d.created_at }}
            </ElDescriptionsItem>
            <ElDescriptionsItem :label="tl('label_third_party_timestamp')">
              {{ d.transaction_time }}
            </ElDescriptionsItem>
            <ElDescriptionsItem :label="tl('label_third_party_callback_time')">
              {{ d.callback_time }}
            </ElDescriptionsItem>
            <ElDescriptionsItem :label="tl('memo')" :span="descColumn">
              {{ d.info_1 }}
            </ElDescriptionsItem>
            <!-- 三方往返紀錄排在這一塊的最後一列，不另外拉一張表：
                 拉出去的話表格被切開，看的人要自己把它跟上面哪一筆代付單對起來。
                 🔴 沒有紀錄就整列不顯示：目前只有部分金流商會寫往返紀錄，其餘的留一列
                 「無往返紀錄」會讓站方以為資料掉了。 -->
            <ElDescriptionsItem
              v-if="logsForTransactionNo(d.transaction_no).length > 0"
              :label="tl('order_request_logs')"
              :span="descColumn"
            >
              <ElTable
                v-loading="requestLogsLoading"
                :data="logsForTransactionNo(d.transaction_no)"
                :empty-text="tl('order_request_logs_empty')"
                border
                size="small"
              >
                <ElTableColumn :label="tl('time')" width="170">
                  <template #default="{ row }">
                    {{ displayLogTime(row.created_at) }}
                  </template>
                </ElTableColumn>
                <ElTableColumn :label="tl('type')" width="150">
                  <template #default="{ row }">
                    {{ actionLabel(row.action) }}
                  </template>
                </ElTableColumn>
                <ElTableColumn :label="tl('order_request_result')">
                  <template #default="{ row }">
                    {{ resultLabel(row.result) }}
                  </template>
                </ElTableColumn>
                <ElTableColumn
                  :label="tl('third_ordernum')"
                  prop="third_party_order_no"
                  width="150"
                />
                <ElTableColumn
                  label="HTTP STATUS"
                  prop="http_status"
                  width="120"
                />
                <ElTableColumn :label="tl('ip')" prop="source_ip" width="140" />
              </ElTable>
            </ElDescriptionsItem>
          </ElDescriptions>
        </div>
      </div>
    </div>

    <template #footer>
      <!-- 待審核 -->
      <template v-if="status === 1">
        <ElButton :loading="submitting" type="success" @click="reviewOrder(3)">
          {{ tl('pass') }}
        </ElButton>
        <ElButton :loading="submitting" type="danger" @click="reviewOrder(2)">
          {{ tl('reject') }}
        </ElButton>
      </template>
      <!-- 等待付款 / 付款處理中失敗 -->
      <template v-if="status === 3 || status === 29">
        <ElButton
          v-if="canMarkPaid"
          type="success"
          @click="openStatusUpdate(4, tl('button_mark_as_paid'), true)"
        >
          {{ tl('button_mark_as_paid') }}
        </ElButton>
        <ElButton
          v-if="canPayout"
          type="primary"
          @click="payoutRef?.open(order.id ?? '')"
        >
          {{ tl('title_use_payout') }}
        </ElButton>
        <ElButton
          type="danger"
          @click="openStatusUpdate(9, tl('button_cancel_and_refund'), false)"
        >
          {{ tl('button_cancel_and_refund') }}
        </ElButton>
      </template>
      <!-- 已付款 -->
      <template v-if="status === 4 && canSuccessToWaiting">
        <ElButton
          type="danger"
          @click="
            openStatusUpdate(
              3,
              tl('button_force_status_to_wait_payment'),
              false,
            )
          "
        >
          {{ tl('button_force_status_to_wait_payment') }}
        </ElButton>
      </template>
      <!-- 代付請求中(21)/代付處理中(22)：跟支付商確認結果後的人工定案（target/tickets#4137）。 -->
      <!-- 21 可補三方單號轉 22（支付商有單）或標記失敗轉 29（查無此單）；22 只能標記失敗轉 29。 -->
      <!-- 原「調整為等待付款(3)」已依需求移除——21/22 退回 3 會解鎖重送，三方實際已出款時＝重複出款。 -->
      <template v-if="(status === 21 || status === 22) && canVerifyingResolve">
        <ElButton
          v-if="status === 21"
          type="primary"
          @click="
            openResolveVerifying(
              'edit',
              tl('button_edit_payout_transaction_no'),
              tl('withdrawal_status_22'),
            )
          "
        >
          {{ tl('button_edit_payout_transaction_no') }}
        </ElButton>
        <ElButton
          type="danger"
          @click="
            openResolveVerifying(
              'fail',
              tl('button_mark_payout_response_failed'),
              tl('withdrawal_status_29'),
            )
          "
        >
          {{ tl('button_mark_payout_response_failed') }}
        </ElButton>
      </template>
      <!-- 代付查證中(23)：存量過渡（23 已廢除、清零後移除），仍走舊接口人工定案 -->
      <template v-if="status === 23 && canVerifyingResolve">
        <ElButton
          type="primary"
          @click="
            openResolveVerifying(
              'edit',
              tl('button_edit_payout_transaction_no'),
              currentStatusText,
            )
          "
        >
          {{ tl('button_edit_payout_transaction_no') }}
        </ElButton>
        <ElButton
          type="danger"
          @click="
            openResolveVerifying(
              'fail',
              tl('button_mark_payout_response_failed'),
              tl('withdrawal_status_29'),
            )
          "
        >
          {{ tl('button_mark_payout_response_failed') }}
        </ElButton>
      </template>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
    </template>

    <MemberFlowLimitDialog ref="flowLimitRef" />
    <Sr02Limit5Dialog ref="sr02Ref" />
    <PayoutSelectDialog ref="payoutRef" @done="onChildDone" />
    <StatusUpdateDialog ref="statusUpdateRef" @done="onChildDone" />
    <ResolveVerifyingPayoutDialog
      ref="resolveVerifyingRef"
      @done="onChildDone"
    />
  </ElDialog>
</template>
