<script lang="ts" setup>
import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
} from 'element-plus';

import {
  forgetAgentPaymentPasswordApi,
  getAgentViewApi,
  sendValidateCodeApi,
} from '#/api';
import { useLeavePage } from '#/composables/use-leave-page';
import { tl } from '#/locales';

defineOptions({ name: 'AgentForgetPaymentPassword' });

const leaveTo = useLeavePage();
const userStore = useUserStore();
const me = computed(() => userStore.userInfo as any);

const mobile = ref('');
const codeSent = ref(false);
const sending = ref(false);
const submitting = ref(false);

const form = reactive({
  authCode: '',
  confirm: '',
  newPwd: '',
});

const maskedMobile = computed(() => {
  const m = mobile.value;
  if (!m || m.length < 7) return m;
  return `${m.slice(0, 3)}${'*'.repeat(m.length - 7)}${m.slice(-4)}`;
});

async function loadMobile() {
  const res = await getAgentViewApi(me.value?.id).catch(() => null);
  mobile.value = (res as any)?.mobile ?? '';
}

async function sendSms() {
  if (!mobile.value) {
    ElMessage.warning(tl('mobile'));
    return;
  }
  sending.value = true;
  try {
    const res = await sendValidateCodeApi({
      ID: me.value?.id,
      mobile: mobile.value,
      type: 6,
      verify_by: 1,
    });
    if (Number(res.code) === 1000) {
      codeSent.value = true;
      ElMessage.success(res.message || tl('success'));
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    sending.value = false;
  }
}

async function handleSubmit() {
  if (form.newPwd !== form.confirm) {
    ElMessage.error(tl('new_password_confirm_error'));
    return;
  }
  submitting.value = true;
  try {
    const res = await forgetAgentPaymentPasswordApi({
      agent_payment_password: [REDACTED],
      agent_payment_password_confirmation: form.confirm,
      mobile: mobile.value,
      token: [REDACTED],
      username: me.value?.username ?? '',
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message || tl('success'));
      await leaveTo('/home');
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    submitting.value = false;
  }
}

onMounted(loadMobile);
</script>

<template>
  <Page :title="tl('forget_agent_payment_password')">
    <ElCard class="mx-auto max-w-xl" shadow="never">
      <ElForm label-position="top">
        <ElFormItem :label="tl('member_account')">
          <ElInput :model-value="me?.username" disabled />
        </ElFormItem>
        <ElFormItem :label="tl('mobile')">
          <div class="flex w-full gap-2">
            <ElInput :model-value="maskedMobile" class="flex-1" disabled />
            <ElButton :loading="sending" type="primary" @click="sendSms">
              {{ tl('send_sms') }}
            </ElButton>
          </div>
        </ElFormItem>
        <template v-if="codeSent">
          <ElFormItem :label="tl('auth_code')">
            <ElInput v-model="form.authCode" />
          </ElFormItem>
          <ElFormItem :label="tl('new_password')">
            <ElInput v-model="form.newPwd" show-password type="password" />
          </ElFormItem>
          <ElFormItem :label="tl('new_password_confirm')">
            <ElInput v-model="form.confirm" show-password type="password" />
          </ElFormItem>
          <div class="flex justify-end">
            <ElButton :loading="submitting" type="info" @click="handleSubmit">
              {{ tl('submit') }}
            </ElButton>
          </div>
        </template>
      </ElForm>
    </ElCard>
  </Page>
</template>
