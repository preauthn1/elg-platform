<script lang="ts" setup>
import type { OrderRequestLogRow, OrderRow } from '#/api';

import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';

import dayjs from 'dayjs';
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
  getDepositRequestLogsApi,
  getOrderListApi,
  resetOrderStatusApi,
  saveManualDepositApi,
} from '#/api';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import { fromApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'OrderDetailDialog' });

const emit = defineEmits<{ saved: [] }>();

const router = useRouter();

const visible = ref(false);
const loading = ref(false);
const saving = ref(false);
const record = ref<null | OrderRow>(null);
const errorMsg = ref('');
const remark = ref('');

const fmt = (v?: null | number | string) => amountThousands(v, 2);

/** 三方往返紀錄。APIs 只回純量，往返內容一個欄位都不回（理由見 OrderRequestLogRow）。
 * 🔴 不要在這裡加「展開看內容」之類的功能——要看內容請開發查 order_request_logs 表。 */
const requestLogs = ref<OrderRequestLogRow[]>([]);
const requestLogsLoading = ref(false);

function actionLabel(action: string) {
  return tl(`order_request_action_${action}`, action);
}

/** 判定結果是 `ok` 或 `failed:<原因>` 兩段，**兩段各自翻譯再接起來**（失敗：查無訂單）——
 * 壓成單一句子會看不出成功失敗，也跟 DB／log 的值對不起來。
 * 原因若是三方回傳的錯誤類型（failed:NotFound、failed:APIValidationError…），值域開放、
 * 翻不完，查不到翻譯就原樣顯示（失敗：NotFound）。 */
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

async function loadRequestLogs(orderId: number | string) {
  requestLogs.value = [];
  requestLogsLoading.value = true;
  try {
    const res = await getDepositRequestLogsApi(orderId);
    // 新 APIs 的成功碼是 200、舊 API_Agent 是 1000；同一個檔案兩種都有，兩個都要認
    if (Number(res.code) === 1000 || Number(res.code) === 200) {
      requestLogs.value = res.data ?? [];
    }
  } finally {
    requestLogsLoading.value = false;
  }
}

/** created_at 顯示：端點輸出裸 UTC，以 basis 0 換算成所選時區（原寫死 +8）。
 * 注意：本檔其餘 dayjs(row.xxx).add(8,'hour') 是「列值 UTC → 端點 +8 參數」的
 * 資料衍生換算，與使用者所選時區無關，不得改成 toApiTime。 */
function displayTime(v?: string) {
  return fromApiTime(v, 0) || '-';
}

/** 備註可編輯 + 手動上分鈕：狀態 0:建立 / 4:待手動確認上分 */
const canManualDeposit = computed(() =>
  [0, 4].includes(Number(record.value?.status)),
);
/** 重置狀態鈕：狀態 1:成功 / 2:失敗 */
const canResetStatus = computed(() =>
  [1, 2].includes(Number(record.value?.status)),
);

const memberTags = computed(() =>
  (record.value?.member_tags ?? []).filter((t) => t && t.name && t.name !== ''),
);

async function open(row: OrderRow, searchAgent?: number) {
  visible.value = true;
  loading.value = true;
  record.value = null;
  errorMsg.value = '';
  remark.value = '';
  loadRequestLogs(row.id);
  try {
    // 以 id + created_at(+8h)~(+9h) 區間重查單筆；
    // search_agent 需與來源頁一致（代理自助上分是 agent_id 關聯，不帶會找不到資料）
    const res = await getOrderListApi({
      end_time: dayjs(row.created_at)
        .add(9, 'hour')
        .format('YYYY-MM-DD HH:mm:ss'),
      id: row.id,
      page: 1,
      per_page: 1,
      search_agent: searchAgent,
      start_time: dayjs(row.created_at)
        .add(8, 'hour')
        .format('YYYY-MM-DD HH:mm:ss'),
    });
    if (Number(res.code) === 1000 && res.data?.[0]) {
      record.value = res.data[0];
      remark.value = record.value.remark ?? '';
    } else {
      errorMsg.value = res.message;
    }
  } finally {
    loading.value = false;
  }
}

/** 會員 sr14 連結 */
function goSr14() {
  const r = record.value;
  if (!r) return;
  visible.value = false;
  router.push({
    path: '/report/sr14',
    query: {
      end_time: dayjs(r.updated_at).add(9, 'hour').format('YYYY-MM-DD HH:mm'),
      member_username: r.member_username,
      start_time: dayjs(r.updated_at).add(8, 'hour').format('YYYY-MM-DD HH:mm'),
    },
  });
}

async function handleManualDeposit() {
  if (!record.value) return;
  saving.value = true;
  try {
    const res = await saveManualDepositApi({
      order_id: record.value.id,
      remark: remark.value,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('saved');
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}

async function handleResetStatus() {
  if (!record.value) return;
  saving.value = true;
  try {
    const res = await resetOrderStatusApi({
      order_id: record.value.id,
      remark: remark.value,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('saved');
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('detailinfo')"
    width="820"
    align-center
  >
    <div v-if="errorMsg" class="py-6 text-center text-red-500">
      {{ errorMsg }}
    </div>
    <template v-else>
      <ElDescriptions v-loading="loading" :column="2" border>
        <ElDescriptionsItem :label="tl('ordernum')">
          {{ record?.order_no || '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem
          :label="`${tl('ordernum')} (${tl('deposit_vendor_name')})`"
        >
          {{ record?.third_party_order_no || '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('agent')">
          <span :title="String(record?.agent_id ?? '')">
            {{ record?.agent_username || '-' }}
          </span>
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('member')">
          <template v-if="Number(record?.member_id) > 0">
            <span :title="String(record?.member_id ?? '')">
              {{ record?.member_username }}
            </span>
            <ElButton link type="primary" class="ml-1" @click="goSr14">
              {{ tl('menu_report_sr14') }}
            </ElButton>
          </template>
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('deposit_vendor_name')">
          {{ record?.cf_name || '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('deposit_order_type_name')">
          {{ record?.order_type_name || '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('real_name')">
          {{
            (Number(record?.member_id) > 0
              ? record?.from_account_name
              : record?.agent_real_name) || '-'
          }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('menu_member_tag')">
          <template v-if="memberTags.length > 0">
            <span
              v-for="(tag, i) in memberTags"
              :key="i"
              :style="{
                backgroundColor: tag.background_color,
                color: tag.text_color,
              }"
              class="mr-1 rounded px-1"
            >
              {{ tag.name }}
            </span>
          </template>
          <template v-else>-</template>
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('amount')">
          {{ fmt(record?.amount) }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('point')">
          {{ fmt(record?.point) }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('time')">
          {{ displayTime(record?.created_at) }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('status')">
          {{ record?.status_name || '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('memo')" :span="2">
          <ElInput
            v-model="remark"
            :placeholder="tl('memo')"
            :readonly="!canManualDeposit"
            :rows="3"
            type="textarea"
          />
        </ElDescriptionsItem>
      </ElDescriptions>

      <!-- 🔴 沒有紀錄就整塊不顯示：目前只有部分金流商會寫往返紀錄，
           其餘的留一張「無往返紀錄」的空表會讓站方以為資料掉了。 -->
      <div v-if="requestLogs.length > 0" class="mt-4">
        <div class="mb-2 font-medium">{{ tl('order_request_logs') }}</div>
        <ElTable
          v-loading="requestLogsLoading"
          :data="requestLogs"
          :empty-text="tl('order_request_logs_empty')"
          border
          size="small"
        >
          <ElTableColumn :label="tl('time')" width="170">
            <template #default="{ row }">
              {{ displayTime(row.created_at) }}
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
          <ElTableColumn label="HTTP STATUS" prop="http_status" width="120" />
          <ElTableColumn :label="tl('ip')" prop="source_ip" width="140" />
        </ElTable>
      </div>

      <div class="mt-4 text-center">
        <ElButton
          v-if="canManualDeposit"
          :loading="saving"
          type="success"
          @click="handleManualDeposit"
        >
          {{ tl('order_manual_deposit') }}
        </ElButton>
        <ElButton
          v-if="canResetStatus"
          :loading="saving"
          @click="handleResetStatus"
        >
          {{ tl('order_reset_status') }}
        </ElButton>
      </div>
    </template>
  </ElDialog>
</template>
