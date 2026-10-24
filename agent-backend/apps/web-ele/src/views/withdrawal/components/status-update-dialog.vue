<script lang="ts" setup>
import { reactive, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
} from 'element-plus';

import { getWithdrawalAccountListApi, reviewWithdrawalOrderApi } from '#/api';
import { requestClient } from '#/api/request';
import { tl } from '#/locales';

defineOptions({ name: 'WithdrawalStatusUpdateDialog' });

const emit = defineEmits<{ done: [] }>();

interface OpenParams {
  afterStatusText: string;
  beforeStatusText: string;
  operateType: string;
  orderId: number | string;
  originalStatus: number | string;
  /** 標記已付款（targetStatus=4）時顯示出款戶口下拉 */
  showAccount: boolean;
  targetStatus: number | string;
}

const visible = ref(false);
const submitting = ref(false);
const accounts = ref<
  Array<{ account_id: number | string; account_nickname: string }>
>([]);

const meta = reactive({
  afterStatusText: '',
  beforeStatusText: '',
  operateType: '',
  orderId: '' as number | string,
  originalStatus: '' as number | string,
  showAccount: false,
  targetStatus: '' as number | string,
});

const account = ref<number | string>('');
const memo = ref('');

let sessionId: number | string = '';

async function open(params: OpenParams) {
  Object.assign(meta, params);
  account.value = '';
  memo.value = '';
  visible.value = true;

  if (params.showAccount) {
    if (!sessionId) {
      const me = await requestClient
        .get<Record<string, any>>('/me')
        .catch(() => null);
      sessionId = me?.id ?? '';
    }
    const res = await getWithdrawalAccountListApi(sessionId);
    accounts.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
    if (accounts.value.length > 0) {
      account.value = accounts.value[0]?.account_id ?? account.value;
    }
  }
}

async function submit() {
  submitting.value = true;
  try {
    const res = await reviewWithdrawalOrderApi({
      account_id: meta.showAccount ? account.value : undefined,
      id: meta.orderId,
      original_status: meta.originalStatus,
      remark: memo.value,
      status: meta.targetStatus,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('done');
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    submitting.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog v-model="visible" width="640" align-center>
    <div class="text-center">
      <h4 class="text-lg font-bold">
        {{ meta.operateType }}
        <span class="ml-2 text-xs text-gray-400">
          {{ tl('ordernumber') }}: {{ meta.orderId }}
        </span>
      </h4>
      <div class="my-2">
        {{ meta.beforeStatusText }} =&gt; {{ meta.afterStatusText }}
      </div>
    </div>

    <div
      v-if="meta.showAccount"
      class="mb-3 flex items-center justify-center gap-2"
    >
      <span>{{ tl('account_code') }}</span>
      <ElSelect v-model="account" style="width: 240px">
        <ElOption
          v-for="a in accounts"
          :key="a.account_id"
          :label="a.account_nickname"
          :value="a.account_id"
        />
      </ElSelect>
    </div>

    <div class="mb-1 text-sm">{{ tl('please_write_memo_info') }}</div>
    <ElInput
      v-model="memo"
      :placeholder="tl('memo')"
      :rows="4"
      type="textarea"
    />

    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="submitting" type="danger" @click="submit">
        {{ tl('submit') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
