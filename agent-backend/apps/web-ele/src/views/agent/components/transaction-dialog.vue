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
} from 'element-plus';

import {
  agentTransactionApi,
  agentWalletTransactionApi,
  verifyAgentPaymentPasswordApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'AgentTransactionDialog' });

const props = defineProps<{
  /** 代理自訂設定（5304 支付密碼開關） */
  settings: Map<number, any>;
}>();

const emit = defineEmits<{ success: [] }>();

const userStore = useUserStore();

const visible = ref(false);
const submitting = ref(false);
const agent = ref<AgentRow | null>(null);
const type = ref(1); // 1=存入 2=取出
/** 0=一般餘額；3=佔成錢包 */
const walletTypeId = ref(0);

const form = reactive({ memo: '', point: undefined as number | undefined });

const title = computed(() => {
  const op = type.value === 1 ? tl('deposit') : tl('withdrawal');
  return walletTypeId.value === 3 ? `${tl('wallet_type.3')} ${op}` : op;
});
const pointLabel = computed(() =>
  type.value === 1 ? tl('deposit_point') : tl('withdrawal_point'),
);

/** 是否需要代理支付密碼（5304 開啟且角色不在 1/11/12） */
const needPaymentPassword = [REDACTED] => {
  const enabled = Number(props.settings.get(5304) ?? 0) === 1;
  const roleId = Number((userStore.userInfo as any)?.role_id ?? 0);
  return enabled && ![1, 11, 12].includes(roleId);
});

function open(row: AgentRow, t: number, wallet = 0) {
  agent.value = row;
  type.value = t;
  walletTypeId.value = wallet;
  Object.assign(form, { memo: '', point: undefined });
  visible.value = true;
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
  const a = agent.value;
  if (!a) return;
  submitting.value = true;
  try {
    const res: any =
      walletTypeId.value === 3
        ? await agentWalletTransactionApi({
            agent_id: a.id,
            point: Number(form.point),
            // 佔成錢包的後端讀 remark、一般開洗分讀 memo，兩支欄位名不同（target/tickets#4435）
            remark: form.memo,
            type: type.value,
            wallet_type_id: walletTypeId.value,
          })
        : await agentTransactionApi({
            agent_id: a.id,
            memo: form.memo,
            point: Number(form.point),
            type: type.value,
          });
    if (res.code === 1000) {
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
    <template v-if="agent">
      <div class="mb-4 space-y-1 text-sm">
        <div>{{ tl('agent_account') }}：{{ agent.username }}</div>
        <div>
          {{ tl('balance') }}：{{
            agent.is_balance_unlimited === 1 ? tl('unlimited') : agent.balance
          }}
        </div>
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
      <ElButton :loading="submitting" type="primary" @click="submit">
        {{ tl('confirm') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
