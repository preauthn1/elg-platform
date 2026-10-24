<script lang="ts" setup>
import { computed, reactive, ref, watch } from 'vue';

import { useUserStore } from '@vben/stores';

import {
  ElAlert,
  ElButton,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
} from 'element-plus';

import { changePasswordBySelfApi, setAgentPaymentPasswordApi } from '#/api';
import { tl } from '#/locales';
import { useForcedSetupStore } from '#/store/forced-setup';

/** 首登強制設定全域覆蓋層：依 forced-setup store 反應式顯示。 */

const RESET = '/agent/reset_password';
const SET_PAYMENT = '/agent/set_payment_password';

const forced = useForcedSetupStore();
const userStore = useUserStore();
const me = computed(() => userStore.userInfo as any);

const current = computed(() => forced.current);
const submitting = ref(false);

// 改密表單
const resetForm = reactive({ confirm: '', newPwd: '', original: '' });
// 設付款密碼表單
const payForm = reactive({ confirm: '', password: '' });

// 切換到哪一頁就清空對應表單，避免殘留
watch(current, () => {
  resetForm.original = '';
  resetForm.newPwd = '';
  resetForm.confirm = '';
  payForm.password = '';
  payForm.confirm = '';
});

async function submitReset() {
  if (!resetForm.original || !resetForm.newPwd) {
    ElMessage.warning(tl('new_password'));
    return;
  }
  if (resetForm.newPwd !== resetForm.confirm) {
    ElMessage.error(tl('new_password_confirm'));
    return;
  }
  submitting.value = true;
  try {
    const res = await changePasswordBySelfApi({
      new_password: [REDACTED],
      new_password_confirmation: resetForm.confirm,
      password: [REDACTED],
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message || tl('success'));
      forced.complete(RESET);
    } else {
      ElMessage.error(res.data || res.message || tl('failure'));
    }
  } finally {
    submitting.value = false;
  }
}

async function submitPayment() {
  if (!payForm.password) {
    ElMessage.warning(tl('agent_payment_password'));
    return;
  }
  if (payForm.password !== payForm.confirm) {
    ElMessage.error(tl('payment_password_confirm'));
    return;
  }
  submitting.value = true;
  try {
    const res = await setAgentPaymentPasswordApi({
      id: me.value?.id,
      payment_password: [REDACTED],
      payment_password_confirm: payForm.confirm,
      username: me.value?.username ?? '',
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message || tl('success'));
      forced.complete(SET_PAYMENT);
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <div v-if="current" class="forced-setup-overlay">
    <div class="forced-setup-card">
      <!-- 改密 -->
      <template v-if="current === RESET">
        <h2 class="mb-4 text-2xl font-bold">
          {{ tl('label_change_password') }}
        </h2>
        <ElAlert
          :closable="false"
          :title="tl('hint_please_change_your_password_every_period')"
          class="mb-4"
          type="warning"
        />
        <ElForm label-position="top" @submit.prevent="submitReset">
          <ElFormItem :label="tl('member_account')">
            <ElInput :model-value="me?.username" disabled />
          </ElFormItem>
          <ElFormItem :label="tl('original_password')">
            <ElInput
              v-model="resetForm.original"
              show-password
              type="password"
            />
          </ElFormItem>
          <ElFormItem :label="tl('new_password')">
            <ElInput v-model="resetForm.newPwd" show-password type="password" />
          </ElFormItem>
          <ElFormItem :label="tl('new_password_confirm')">
            <ElInput
              v-model="resetForm.confirm"
              show-password
              type="password"
            />
          </ElFormItem>
          <div class="mt-2 flex justify-end">
            <ElButton :loading="submitting" type="primary" @click="submitReset">
              {{ tl('label_change_password') }}
            </ElButton>
          </div>
        </ElForm>
      </template>

      <!-- 設定支付密碼 -->
      <template v-else-if="current === SET_PAYMENT">
        <h2 class="mb-4 text-2xl font-bold">
          {{ tl('setting_agent_payment_password') }}
        </h2>
        <ElForm label-position="top" @submit.prevent="submitPayment">
          <ElFormItem :label="tl('member_account')">
            <ElInput :model-value="me?.username" disabled />
          </ElFormItem>
          <ElFormItem :label="tl('agent_payment_password')">
            <ElInput
              v-model="payForm.password"
              maxlength="15"
              show-password
              type="password"
            />
          </ElFormItem>
          <ElFormItem :label="tl('payment_password_confirm')">
            <ElInput
              v-model="payForm.confirm"
              maxlength="15"
              show-password
              type="password"
            />
          </ElFormItem>
          <div class="mt-2 flex justify-end">
            <ElButton
              :loading="submitting"
              type="primary"
              @click="submitPayment"
            >
              {{ tl('submit') }}
            </ElButton>
          </div>
        </ElForm>
      </template>
    </div>
  </div>
</template>

<style scoped>
.forced-setup-overlay {
  position: fixed;
  inset: 0;
  z-index: 3000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  overflow: auto;
  background-color: hsl(var(--background));
}

.forced-setup-card {
  width: 100%;
  max-width: 460px;
  padding: 32px;
  background-color: hsl(var(--card));
  border-radius: 12px;
  box-shadow: 0 8px 30px rgb(0 0 0 / 12%);
}
</style>
