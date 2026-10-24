<script lang="ts" setup>
import { reactive, ref } from 'vue';

import { ElAlert, ElButton, ElDialog, ElInput, ElMessage } from 'element-plus';

import { editPayoutTransactionNoApi, markPayoutResponseFailedApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'ResolveVerifyingPayoutDialog' });

const emit = defineEmits<{ done: [] }>();

interface OpenParams {
  afterStatusText: string;
  beforeStatusText: string;
  mode: 'edit' | 'fail';
  operateType: string;
  orderId: number | string;
  /** 原始狀態 21/22/23，決定打 APIs 新接口或舊接口（target/tickets#4137） */
  orderStatus: number | string;
  /** 21 補號後才顯示狀態變更列，23 存量過渡維持原狀態不顯示 */
  showStatusChange: boolean;
}

const visible = ref(false);
const submitting = ref(false);
const meta = reactive({
  afterStatusText: '',
  beforeStatusText: '',
  mode: 'edit' as 'edit' | 'fail',
  operateType: '',
  orderId: '' as number | string,
  orderStatus: '' as number | string,
  showStatusChange: false,
});

const transactionNo = ref('');
const remark = ref('');

function open(params: OpenParams) {
  Object.assign(meta, params);
  transactionNo.value = '';
  remark.value = '';
  visible.value = true;
}

async function submit() {
  if (meta.mode === 'edit' && !transactionNo.value.trim()) {
    ElMessage.error(tl('please_write_payout_transaction_no'));
    return;
  }
  submitting.value = true;
  try {
    const res =
      meta.mode === 'edit'
        ? await editPayoutTransactionNoApi({
            id: meta.orderId,
            original_status: meta.orderStatus,
            remark: remark.value,
            third_party_transaction_no: transactionNo.value.trim(),
          })
        : await markPayoutResponseFailedApi({
            id: meta.orderId,
            original_status: meta.orderStatus,
            remark: remark.value,
          });
    if (Number(res.code) === 1000 || Number(res.code) === 200) {
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
      <div v-if="meta.mode === 'fail' || meta.showStatusChange" class="my-2">
        {{ meta.beforeStatusText }} =&gt; {{ meta.afterStatusText }}
      </div>
    </div>

    <ElAlert
      v-if="meta.mode === 'fail'"
      class="mb-3"
      :closable="false"
      :title="tl('payout_mark_failed_confirm_notice')"
      show-icon
      type="error"
    />

    <div v-if="meta.mode === 'edit'" class="mb-3">
      <div class="mb-1 text-sm">
        {{ tl('please_write_payout_transaction_no') }}
      </div>
      <ElInput
        v-model="transactionNo"
        maxlength="50"
        :placeholder="tl('please_write_payout_transaction_no')"
      />
    </div>

    <div class="mb-1 text-sm">{{ tl('please_write_memo_info') }}</div>
    <ElInput
      v-model="remark"
      maxlength="500"
      :placeholder="tl('memo')"
      :rows="4"
      show-word-limit
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
