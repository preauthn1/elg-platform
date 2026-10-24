<script lang="ts" setup>
import { computed, reactive, ref } from 'vue';

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

import { modifyAgentPaymentPasswordApi } from '#/api';
import { useLeavePage } from '#/composables/use-leave-page';
import { tl } from '#/locales';

defineOptions({ name: 'AgentModifyPaymentPassword' });

const leaveTo = useLeavePage();
const userStore = useUserStore();
const submitting = ref(false);

const me = computed(() => userStore.userInfo as any);

const form = reactive({
  confirm: '',
  newPwd: '',
  original: '',
});

async function handleSubmit() {
  if (!form.original || !form.newPwd) {
    ElMessage.warning(tl('new_payment_password'));
    return;
  }
  if (form.newPwd !== form.confirm) {
    ElMessage.error(tl('new_password_confirm_error'));
    return;
  }
  submitting.value = true;
  try {
    const res = await modifyAgentPaymentPasswordApi({
      id: me.value?.id,
      new_agent_payment_password: [REDACTED],
      new_agent_payment_password_confirmation: form.confirm,
      original_agent_payment_password: [REDACTED],
      user_name: me.value?.username ?? me.value?.realName ?? '',
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
</script>

<template>
  <Page :title="tl('modify_agent_payment_password')">
    <ElCard class="mx-auto max-w-xl" shadow="never">
      <ElForm label-position="top" @submit.prevent="handleSubmit">
        <ElFormItem :label="tl('member_account')">
          <ElInput :model-value="me?.username" disabled />
        </ElFormItem>
        <ElFormItem :label="tl('original_agent_payment_password')">
          <ElInput v-model="form.original" show-password type="password" />
        </ElFormItem>
        <ElFormItem :label="tl('new_payment_password')">
          <ElInput v-model="form.newPwd" show-password type="password" />
        </ElFormItem>
        <ElFormItem :label="tl('new_payment_password_confirm')">
          <ElInput v-model="form.confirm" show-password type="password" />
        </ElFormItem>
        <div class="flex justify-end">
          <ElButton :loading="submitting" type="primary" @click="handleSubmit">
            {{ tl('label_change_password') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>
  </Page>
</template>
