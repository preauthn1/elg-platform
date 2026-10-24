<script lang="ts" setup>
import type { UnlockEventModeRow } from '#/api';

import { computed, ref } from 'vue';

import {
  ElButton,
  ElDescriptions,
  ElDescriptionsItem,
  ElDialog,
  ElInput,
  ElMessage,
  ElPopover,
} from 'element-plus';

import { getUnlockEventModeViewApi, reviewUnlockEventModeApi } from '#/api';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

defineOptions({ name: 'UnlockEventDialog' });

const emit = defineEmits<{ saved: [] }>();

const visible = ref(false);
const loading = ref(false);
const saving = ref(false);
const record = ref<null | UnlockEventModeRow>(null);
const remark = ref('');
/** view API 不回傳 status，由列表帶入（彈窗僅從待審核列開啟、按鈕恆顯示） */
const rowStatus = ref(0);

/** 無條件捨去、千分位 */
const fmt = (v: null | number | string | undefined, decimals: number) =>
  amountThousands(v, decimals, '-');

/** 該會員上級代理是否開啟多錢包（target/tickets#4290）：開啟時餘額可點擊展開拆分明細 */
const multipleWalletSwitch = computed(
  () => Number(record.value?.multiple_wallet_switch) === 1,
);

async function open(id: number, status = 0) {
  visible.value = true;
  loading.value = true;
  record.value = null;
  remark.value = '';
  rowStatus.value = Number(status);
  try {
    record.value = (await getUnlockEventModeViewApi(id)) ?? null;
  } finally {
    loading.value = false;
  }
}

/** 審核 */
async function review(status: number) {
  if (!record.value) return;
  saving.value = true;
  try {
    const res = await reviewUnlockEventModeApi({
      id: record.value.id,
      remark: remark.value,
      status,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message);
      visible.value = false;
      emit('saved');
    } else {
      // 2002 顯示 data，其餘顯示 message
      ElMessage.error(
        Number(res.code) === 2002 ? String(res.data) : res.message,
      );
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
    :title="tl('order_info')"
    width="700"
    align-center
  >
    <ElDescriptions v-loading="loading" :column="2" border>
      <ElDescriptionsItem :label="tl('num')">
        {{ record?.id ?? '-' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('account_name')">
        {{ record?.member_username ?? '-' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('balance')">
        <!-- 餘額一律可點擊展開拆分明細；多錢包開關只決定是否多顯示遊戲錢包一項（target/tickets#4290，已與 PM 確認） -->
        <ElPopover
          :title="tl('balance_detail')"
          placement="top"
          trigger="click"
          width="200"
        >
          <template #reference>
            <span class="cursor-pointer border-b border-dashed border-current">
              {{ fmt(record?.balance, 2) }}
            </span>
          </template>
          <div class="space-y-1 text-sm">
            <div class="flex justify-between">
              <span>{{ tl('main_wallet') }}</span>
              <span>{{ fmt(record?.main_wallet, 2) }}</span>
            </div>
            <div v-if="multipleWalletSwitch" class="flex justify-between">
              <span>{{ tl('game_wallet') }}</span>
              <span>{{ fmt(record?.game_wallet, 2) }}</span>
            </div>
            <div class="flex justify-between">
              <span>{{ tl('rolling_chip') }}</span>
              <span>{{ fmt(record?.rolling_chip, 2) }}</span>
            </div>
            <div class="flex justify-between border-t pt-1 font-medium">
              <span>{{ tl('cs.total') }}</span>
              <span>{{ fmt(record?.balance, 2) }}</span>
            </div>
          </div>
        </ElPopover>
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('flow_limit')">
        {{ fmt(record?.flow_limit, 4) }}
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('memo')" :span="2">
        <ElInput v-model="remark" :rows="3" type="textarea" />
      </ElDescriptionsItem>
    </ElDescriptions>
    <template #footer>
      <template v-if="rowStatus === 0">
        <ElButton :loading="saving" type="success" @click="review(1)">
          {{ tl('pass') }}
        </ElButton>
        <ElButton :loading="saving" type="danger" @click="review(2)">
          {{ tl('reject') }}
        </ElButton>
      </template>
    </template>
  </ElDialog>
</template>
