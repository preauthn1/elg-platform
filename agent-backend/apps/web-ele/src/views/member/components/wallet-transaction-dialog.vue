<script lang="ts" setup>
import type { MemberRow } from '#/api';

import { computed, reactive, ref } from 'vue';

import { useAccess } from '@vben/access';
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
  ElOption,
  ElSelect,
  ElSkeleton,
} from 'element-plus';

import {
  getMemberGameWalletApi,
  memberGameWalletTransactionApi,
  verifyAgentPaymentPasswordApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'MemberWalletTransactionDialog' });

const props = defineProps<{
  /** 代理自訂設定（5304 支付密碼） */
  settings: Map<number, any>;
}>();

const emit = defineEmits<{ success: [] }>();

const { hasAccessByCodes } = useAccess();
const userStore = useUserStore();

const visible = ref(false);
const loading = ref(false);
const submitting = ref(false);
const target = ref<null | { balance: number | string; username: string }>(null);
let memberId: number | string = 0;

const form = reactive({ memo: '', point: 0, type: 1 });

/** 操作型別（1=充值 2=補點 3=扣分） */
const typeOptions = computed(() => {
  const opts: Array<{ label: string; value: number }> = [
    { label: tl('deposit'), value: 1 },
  ];
  if (hasAccessByCodes(['member_make_up_point']))
    opts.push({ label: tl('compensate'), value: 2 });
  if (hasAccessByCodes(['member_deduct_point']))
    opts.push({ label: tl('deduct'), value: 3 });
  return opts;
});

/** 是否需要代理支付密碼（5304 開啟且角色不在 1/11/12） */
const needPaymentPassword = [REDACTED] => {
  const enabled = Number(props.settings.get(5304) ?? 0) === 1;
  const roleId = Number((userStore.userInfo as any)?.role_id ?? 0);
  return enabled && ![1, 11, 12].includes(roleId);
});

async function open(row: MemberRow) {
  memberId = row.id;
  Object.assign(form, { memo: '', point: 0, type: 1 });
  target.value = null;
  visible.value = true;
  loading.value = true;
  try {
    target.value = await getMemberGameWalletApi(row.id);
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
    const res = await memberGameWalletTransactionApi({
      member_id: memberId,
      memo: form.memo,
      point: form.point,
      type: form.type,
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
  <ElDialog
    v-model="visible"
    :title="tl('operate_game_wallet')"
    width="460"
    align-center
  >
    <ElSkeleton v-if="loading" :rows="4" animated />
    <template v-else-if="target">
      <div class="mb-4 space-y-1 text-sm">
        <div>{{ tl('member_account') }}：{{ target.username }}</div>
        <div>{{ tl('member_balance') }}：{{ target.balance }}</div>
      </div>
      <ElForm label-position="top">
        <div class="grid grid-cols-2 gap-x-4">
          <ElFormItem :label="tl('operate')">
            <ElSelect v-model="form.type" class="w-full">
              <ElOption
                v-for="o in typeOptions"
                :key="o.value"
                :label="o.label"
                :value="o.value"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="`${tl('paytype_deposit')}${tl('point')}`">
            <ElInputNumber
              v-model="form.point"
              :min="0"
              :precision="2"
              class="!w-full"
              controls-position="right"
            />
          </ElFormItem>
        </div>
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
