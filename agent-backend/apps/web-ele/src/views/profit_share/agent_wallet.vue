<script lang="ts" setup>
import type { ProfitShareScheduleRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInputNumber,
  ElMessage,
  ElMessageBox,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  cancelProfitShareAgentScheduleApi,
  getPersonalSettingApi,
  getProfitShareAgentScheduleListApi,
  getProfitShareAgentWalletViewApi,
  getTranslateApi,
  setProfitShareCapAmountApi,
  transferToMainApi,
  transferToProfitShareApi,
} from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

defineOptions({ name: 'ProfitShareAgentWallet' });

const PER_PAGE = 10;

const userStore = useUserStore();
const agentId = computed(() => (userStore.userInfo as any)?.id ?? 0);
const hostId = computed(() => (userStore.userInfo as any)?.host_id ?? 0);

const wallet = reactive<{
  main: number | string;
  profit_share: number | string;
}>({ main: 0, profit_share: 0 });
/** 95301 主錢包、95302 佔成錢包：1 啟用轉帳按鈕 */
const mainStatus = ref(1);
const shareStatus = ref(1);
/** 95110 佔成錢包封頂額度，-1 為不限 */
const capAmount = ref<number>(-1);
const translates = ref<Record<string, string>>({});

const schedules = ref<ProfitShareScheduleRow[]>([]);
const total = ref(0);
const page = ref(1);
const loading = ref(false);

const fmt4 = (v?: null | number | string) => amountThousands(v, 4);

const capAmountText = computed(() =>
  Number(capAmount.value) === -1
    ? tl('profit_share_cap_amount_unlimited')
    : `$ ${fmt4(capAmount.value)}`,
);

/** 轉帳對話框 */
const dialog = reactive({
  amount: 0 as number,
  mode: 'in' as 'in' | 'out',
  submitting: false,
  visible: false,
});

const dialogTitle = computed(() =>
  tl(
    dialog.mode === 'in'
      ? 'transfer_in_profit_share'
      : 'transfer_out_profit_share',
  ),
);
const dialogMax = computed(() =>
  dialog.mode === 'in' ? Number(wallet.main) : Number(wallet.profit_share),
);

async function loadWallet() {
  const res = await getProfitShareAgentWalletViewApi();
  if (Number(res.code) === 1000) {
    wallet.main = res.data?.main ?? 0;
    wallet.profit_share = res.data?.profit_share ?? 0;
  }
}

async function loadSettings() {
  const list = await getPersonalSettingApi(Number(hostId.value)).catch(
    () => [],
  );
  const map = new Map(
    (list ?? []).map((s) => [Number(s.setting_id), s.data_value]),
  );
  mainStatus.value = Number(map.get(95_301) ?? 1);
  shareStatus.value = Number(map.get(95_302) ?? 1);
  capAmount.value = Number(map.get(95_110) ?? -1);
}

async function loadSchedules() {
  loading.value = true;
  try {
    const res = await getProfitShareAgentScheduleListApi({
      id: agentId.value,
      page: page.value,
      per_page: PER_PAGE,
    });
    if (Number(res.code) === 1000) {
      schedules.value = res.data ?? [];
      total.value = res.pagination?.total ?? 0;
    } else {
      schedules.value = [];
      total.value = 0;
    }
  } finally {
    loading.value = false;
  }
}

function handlePageChange(p: number) {
  page.value = p;
  loadSchedules();
}

function openTransfer(mode: 'in' | 'out') {
  dialog.mode = mode;
  dialog.amount = 0;
  dialog.visible = true;
}

/** 封頂額度設定對話框（schedule_type_id 4，-1 為不限） */
const capDialog = reactive({
  submitting: false,
  value: -1 as number,
  visible: false,
});

function openCapDialog() {
  capDialog.value = Number(capAmount.value);
  capDialog.visible = true;
}

async function submitCapAmount() {
  if (!Number.isInteger(capDialog.value) || capDialog.value < -1) {
    ElMessage.warning(tl('profit_share_cap_amount_invalid'));
    return;
  }
  capDialog.submitting = true;
  try {
    const res = await setProfitShareCapAmountApi({
      cap_amount: capDialog.value,
    });
    const code = Number(res.code);
    if (code === 1000) {
      ElMessage.success(tl('success'));
      capDialog.visible = false;
      await Promise.all([loadSettings(), loadSchedules()]);
    } else if (code === 5002) {
      // 已有排程，不關閉避免重複
      ElMessage.warning(res.message);
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    capDialog.submitting = false;
  }
}

async function submitTransfer() {
  if (!dialog.amount || dialog.amount <= 0) {
    ElMessage.warning(tl('please_enter_valid_amount'));
    return;
  }
  if (dialog.amount > dialogMax.value) {
    ElMessage.warning(
      tl(
        dialog.mode === 'in'
          ? 'amount_exceeds_main_wallet'
          : 'amount_exceeds_share_wallet',
      ),
    );
    return;
  }
  dialog.submitting = true;
  try {
    const api =
      dialog.mode === 'in' ? transferToProfitShareApi : transferToMainApi;
    const res = await api({ agent_id: agentId.value, point: dialog.amount });
    const code = Number(res.code);
    if (code === 1000) {
      // 鎖定期提示（translate 8202 轉入 / 8203 轉出）
      const tip =
        dialog.mode === 'in'
          ? translates.value['8202']
          : translates.value['8203'];
      ElMessageBox.alert(tip ?? '', dialogTitle.value, { type: 'success' });
      dialog.visible = false;
      await Promise.all([loadWallet(), loadSchedules()]);
    } else if (code === 5002) {
      // 已有排程，不關閉避免重複
      ElMessage.warning(res.message);
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    dialog.submitting = false;
  }
}

/** 排程類型顯示文字：1 轉入、2 轉出、4 設定封頂額度 */
function scheduleTypeLabel(row: ProfitShareScheduleRow) {
  const typeId = Number(row.schedule_type_id);
  if (typeId === 4) return tl('set_profit_share_cap_amount');
  return tl(
    typeId === 1 ? 'transfer_in_profit_share' : 'transfer_out_profit_share',
  );
}

function showScheduleTip(row: ProfitShareScheduleRow) {
  const tip =
    Number(row.schedule_type_id) === 1
      ? translates.value['8202']
      : translates.value['8203'];
  const title = tl(
    Number(row.schedule_type_id) === 1
      ? 'transfer_in_profit_share'
      : 'transfer_out_profit_share',
  );
  ElMessageBox.alert(tip ?? '', title, { type: 'info' });
}

async function cancelSchedule(row: ProfitShareScheduleRow) {
  try {
    const { value } = await ElMessageBox.prompt(
      tl('enter_memo'),
      tl('cancel'),
      {
        confirmButtonText: tl('submit'),
        cancelButtonText: tl('cancel'),
        inputType: 'textarea',
        inputValidator: (v) => (v ? true : tl('enter_memo')),
      },
    );
    const res = await cancelProfitShareAgentScheduleApi({
      remark: value,
      schedule_id: row.schedule_log_id ?? '',
      schedule_type_id: row.schedule_type_id ?? '',
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      await Promise.all([loadWallet(), loadSchedules()]);
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } catch {
    // 取消彈窗
  }
}

onMounted(() => {
  getTranslateApi()
    .then((d) => {
      translates.value = d ?? {};
    })
    .catch(() => {});
  loadWallet();
  loadSettings();
  loadSchedules();
});
</script>

<template>
  <Page
    :title="`${tl('menu_agent_profit_sharing_management')} > ${tl('menu_profit_share_agent_wallet')}`"
  >
    <ElCard shadow="never">
      <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-3">
        <ElCard class="text-center" shadow="hover">
          <div class="text-green-600 mb-2 text-base font-bold">
            {{ tl('wallet_type.1') }}
          </div>
          <div class="mb-4 text-2xl">$ {{ fmt4(wallet.main) }}</div>
          <ElButton
            :disabled="!mainStatus"
            type="primary"
            @click="openTransfer('in')"
          >
            {{ tl('transfer_in_profit_share') }}
          </ElButton>
        </ElCard>
        <ElCard class="text-center" shadow="hover">
          <div class="text-blue-500 mb-2 text-base font-bold">
            {{ tl('wallet_type.3') }}
          </div>
          <div class="mb-4 text-2xl">$ {{ fmt4(wallet.profit_share) }}</div>
          <ElButton
            :disabled="!shareStatus"
            type="warning"
            @click="openTransfer('out')"
          >
            {{ tl('transfer_out_profit_share') }}
          </ElButton>
        </ElCard>
        <ElCard class="text-center" shadow="hover">
          <div class="mb-2 text-base font-bold text-gray-500">
            {{ tl('profit_share_cap_amount') }}
          </div>
          <div class="mb-4 text-2xl">{{ capAmountText }}</div>
          <ElButton @click="openCapDialog">
            {{ tl('set_profit_share_cap_amount') }}
          </ElButton>
        </ElCard>
      </div>

      <ElTable v-loading="loading" :data="schedules" border size="small">
        <ElTableColumn
          :label="tl('id')"
          prop="schedule_log_id"
          min-width="90"
        />
        <ElTableColumn :label="tl('type')" min-width="140">
          <template #default="{ row }">
            {{ scheduleTypeLabel(row as ProfitShareScheduleRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('amount')" align="right" min-width="130">
          <template #default="{ row }">
            {{ fmt4((row as ProfitShareScheduleRow).data_value) }}
          </template>
        </ElTableColumn>
        <ElTableColumn width="60">
          <template #default="{ row }">
            <ElButton
              v-if="
                Number((row as ProfitShareScheduleRow).schedule_status) === 0 &&
                Number((row as ProfitShareScheduleRow).schedule_type_id) !== 4
              "
              circle
              size="small"
              type="info"
              @click="showScheduleTip(row as ProfitShareScheduleRow)"
            >
              i
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('created_at')"
          prop="created_at"
          min-width="160"
        />
        <ElTableColumn
          :label="tl('estimated_effective_time')"
          prop="estimated_effective_time"
          min-width="160"
        />
        <ElTableColumn
          :label="tl('status')"
          prop="schedule_status_txt"
          min-width="110"
        />
        <ElTableColumn :label="tl('operate')" min-width="110" fixed="right">
          <template #default="{ row }">
            <ElButton
              v-if="
                Number((row as ProfitShareScheduleRow).schedule_status) === 0
              "
              size="small"
              type="danger"
              @click="cancelSchedule(row as ProfitShareScheduleRow)"
            >
              {{ tl('cancel') }}
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

    <ElDialog v-model="dialog.visible" :title="dialogTitle" width="420px">
      <ElForm label-width="auto">
        <ElFormItem
          :label="
            dialog.mode === 'in' ? tl('wallet_type.1') : tl('wallet_type.3')
          "
        >
          <span>$ {{ fmt4(dialogMax) }}</span>
        </ElFormItem>
        <ElFormItem
          :label="
            dialog.mode === 'in' ? tl('wallet_type.3') : tl('wallet_type.1')
          "
        >
          <ElInputNumber
            v-model="dialog.amount"
            :min="0"
            :precision="4"
            :step="1"
            :placeholder="tl('enter_amount')"
            class="!w-full"
          />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="dialog.visible = false">{{ tl('cancel') }}</ElButton>
        <ElButton
          :loading="dialog.submitting"
          type="primary"
          @click="submitTransfer"
        >
          {{ tl('confirm') }}
        </ElButton>
      </template>
    </ElDialog>

    <ElDialog
      v-model="capDialog.visible"
      :title="tl('set_profit_share_cap_amount')"
      width="420px"
    >
      <ElForm label-width="auto">
        <ElFormItem :label="tl('profit_share_cap_amount')">
          <div class="flex w-full items-center gap-2">
            <ElInputNumber
              v-model="capDialog.value"
              :min="-1"
              :precision="0"
              :step="1"
              class="flex-1"
              controls-position="right"
            />
            <ElButton @click="capDialog.value = -1">
              {{ tl('profit_share_cap_button_set_unlimited') }}
            </ElButton>
          </div>
        </ElFormItem>
        <p class="text-gray-400 text-sm">
          {{ tl('profit_share_cap_amount_hint') }}
        </p>
      </ElForm>
      <template #footer>
        <ElButton @click="capDialog.visible = false">
          {{ tl('cancel') }}
        </ElButton>
        <ElButton
          :loading="capDialog.submitting"
          type="primary"
          @click="submitCapAmount"
        >
          {{ tl('confirm') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
