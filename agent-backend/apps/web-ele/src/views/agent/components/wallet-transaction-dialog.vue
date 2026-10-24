<script lang="ts" setup>
import type { AgentRow } from '#/api';

import { computed, reactive, ref } from 'vue';

import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElInputNumber,
  ElMessage,
  ElMessageBox,
  ElSkeleton,
} from 'element-plus';

import {
  agentGameWalletTransactionApi,
  getAgentWalletViewApi,
  verifyAgentPaymentPasswordApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'AgentWalletTransactionDialog' });

const props = defineProps<{
  /** 代理自訂設定（5304 支付密碼開關） */
  settings: Map<number, any>;
}>();

const emit = defineEmits<{ success: [] }>();

const userStore = useUserStore();

const visible = ref(false);
const loading = ref(false);
const submitting = ref(false);
/** 1=開分 2=扣分 */
const type = ref(1);
const target = ref<null | { balance: number | string; username: string }>(null);
let agentId: number | string = 0;

const form = reactive({ memo: '', point: undefined as number | undefined });

const title = computed(
  () =>
    `${tl('operate_game_wallet')} - ${
      type.value === 1 ? tl('deposit') : tl('deduct')
    }`,
);
const pointLabel = computed(() =>
  type.value === 1
    ? `${tl('paytype_deposit')}${tl('point')}`
    : tl('deduct_point'),
);

/** 是否需要代理支付密碼（5304 開啟且角色不在 1/11/12） */
const needPaymentPassword = [REDACTED] => {
  const enabled = Number(props.settings.get(5304) ?? 0) === 1;
  const roleId = Number((userStore.userInfo as any)?.role_id ?? 0);
  return enabled && ![1, 11, 12].includes(roleId);
});

async function open(row: AgentRow, t: number) {
  agentId = row.id;
  type.value = t;
  Object.assign(form, { memo: '', point: undefined });
  target.value = null;
  visible.value = true;
  loading.value = true;
  try {
    target.value = await getAgentWalletViewApi(row.id, 1);
  } finally {
    loading.value = false;
  }
}

async function submit() {
  if (!(Number(form.point) > 0)) {
    ElMessage.warning('Please enter a number greater than 0');
    return;
  }
  if (needPaymentPassword.value) {
    try {
      const { value: pwd } = await ElMessageBox.prompt(
        tl('agent_payment_password'),
        tl('auth'),
        {
          cancelButtonText: tl('cancel'),
          confirmButtonText: tl('auth'),
          inputType: 'password',
        },
      );
      const vr = await verifyAgentPaymentPasswordApi(pwd ?? '');
      if (Number(vr.code) !== 200) {
        ElMessage.error(vr.message || tl('failure'));
        return;
      }
    } catch {
      return;
    }
  }
  submitting.value = true;
  try {
    const res = await agentGameWalletTransactionApi({
      agent_id: agentId,
      memo: form.memo,
      point: Number(form.point),
      type: type.value,
      wallet_type_id: 1,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message || tl('success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    submitting.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog v-model="visible" :title="title" width="460" align-center>
    <ElSkeleton v-if="loading" :rows="4" animated />
    <template v-else-if="target">
      <div class="mb-4 space-y-1 text-sm">
        <div>{{ tl('agent_account') }}：{{ target.username }}</div>
        <div>{{ tl('agent_balance') }}：{{ target.balance }}</div>
      </div>
      <ElForm label-position="top">
        <ElFormItem :label="pointLabel">
          <ElInputNumber
            v-model="form.point"
            :min="0"
            :precision="4"
            class="!w-full"
            controls-position="right"
          />
        </ElFormItem>
        <ElFormItem :label="tl('memo')">
          <ElInput v-model="form.memo" :rows="3" type="textarea" />
        </ElFormItem>
      </ElForm>
    </template>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton
        :disabled="loading"
        :loading="submitting"
        type="primary"
        @click="submit"
      >
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
