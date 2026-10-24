<script lang="ts" setup>
import { computed, reactive, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
} from 'element-plus';

import { modifyAgentPasswordApi } from '#/api';
import { tl } from '#/locales';

/**
 * 修改代理登入密碼／支付密碼視窗（target/tickets#4506）。
 * 欄位比照會員管理的修改密碼（密碼、確認密碼、備註）；改別人（有 agentId）另加操作者本人的
 * Google 2FA 驗證碼，錯誤碼 1098 留在視窗內提示「驗證碼錯誤」可重試、不鎖定。
 * 改自己（右上角個人選單）不帶 agentId，不需驗證碼。
 */
defineOptions({ name: 'AgentPasswordDialog' });

const emit = defineEmits<{ success: [] }>();

type PwdType = 'password' | 'payment_password';

const visible = ref(false);
const submitting = ref(false);
const agentId = ref<number | undefined>();
const username = ref('');
const pwdType = ref<PwdType>('password');
const otpError = ref('');

const form = reactive({
  confirm_password: '',
  otp: '',
  password: '',
  remark: '',
});

/** 支付密碼規則：剛好 6 碼英文字母或數字（全數字也可以）；只套用在修改支付密碼視窗 */
const PAY_PWD_RULE = /^[0-9A-Za-z]{6}$/;
const payPwdInvalid = computed(
  () =>
    pwdType.value === 'payment_password' &&
    form.password !== '' &&
    !PAY_PWD_RULE.test(form.password),
);

function open(type: PwdType, target?: { id: number; username: string }) {
  pwdType.value = type;
  agentId.value = target?.id;
  username.value = target?.username ?? '';
  form.password = '';
  form.confirm_password = '';
  form.remark = '';
  form.otp = '';
  otpError.value = '';
  visible.value = true;
}

function title() {
  if (agentId.value === undefined) {
    return tl(
      pwdType.value === 'password'
        ? 'modify_password'
        : 'modify_payment_password',
    );
  }
  return tl(
    pwdType.value === 'password'
      ? 'agent_pwd_modify_other_title'
      : 'agent_pay_pwd_modify_other_title',
  ).replace('{name}', username.value);
}

async function submit() {
  if (submitting.value) return;
  if (!form.password || !form.confirm_password || !form.remark) {
    ElMessage.warning(tl('enter_memo'));
    return;
  }
  if (
    pwdType.value === 'payment_password' &&
    !PAY_PWD_RULE.test(form.password)
  ) {
    return;
  }
  if (form.password !== form.confirm_password) {
    ElMessage.warning(
      tl(
        pwdType.value === 'password'
          ? 'password_confirm'
          : 'payment_password_confirm',
      ),
    );
    return;
  }
  if (agentId.value !== undefined && !/^\d{6}$/.test(form.otp)) {
    otpError.value = tl('tfa_input_code');
    return;
  }
  submitting.value = true;
  otpError.value = '';
  try {
    const res: any = await modifyAgentPasswordApi({
      agent_id: agentId.value,
      confirm_password: [REDACTED],
      password: [REDACTED],
      remark: form.remark,
      secret: [REDACTED] === undefined ? undefined : form.otp,
      type: pwdType.value,
    });
    const code = Number(res?.code);
    if (code === 1000) {
      ElMessage.success(res.message || tl('success'));
      visible.value = false;
      emit('success');
    } else if (code === 1098) {
      otpError.value = tl('tfa_code_error');
      form.otp = '';
    } else {
      ElMessage.error(
        Number(res?.code) === 2002
          ? String(res.data)
          : res?.message || tl('failure'),
      );
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
    :close-on-click-modal="false"
    :title="title()"
    append-to-body
    align-center
    width="min(480px, 92vw)"
  >
    <ElForm label-position="top" @submit.prevent>
      <ElFormItem
        :label="tl(pwdType === 'password' ? 'password' : 'payment_password')"
        required
      >
        <ElInput
          v-model="form.password"
          autocomplete="new-password"
          :maxlength="pwdType === 'payment_password' ? 6 : undefined"
          :placeholder="
            pwdType === 'payment_password' ? tl('pay_pwd_placeholder') : ''
          "
          show-password
          type="password"
        />
        <template v-if="pwdType === 'payment_password'">
          <p
            class="mt-1 text-xs leading-snug"
            :class="payPwdInvalid ? 'text-red-500' : 'text-muted-foreground'"
          >
            {{
              payPwdInvalid ? tl('pay_pwd_rule_error') : tl('pay_pwd_rule_hint')
            }}
          </p>
        </template>
      </ElFormItem>
      <ElFormItem
        :label="
          tl(
            pwdType === 'password'
              ? 'password_confirm'
              : 'payment_password_confirm',
          )
        "
        required
      >
        <ElInput
          v-model="form.confirm_password"
          autocomplete="new-password"
          show-password
          type="password"
        />
      </ElFormItem>
      <ElFormItem :label="tl('memo')" required>
        <ElInput v-model="form.remark" :rows="3" type="textarea" />
      </ElFormItem>
      <ElFormItem
        v-if="agentId !== undefined"
        :error="otpError"
        :label="tl('tfa_your_code')"
        required
      >
        <ElInput
          v-model="form.otp"
          autocomplete="one-time-code"
          inputmode="numeric"
          maxlength="6"
          :placeholder="tl('tfa_input_code')"
          @keyup.enter="submit"
        />
      </ElFormItem>
    </ElForm>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton
        :disabled="payPwdInvalid"
        :loading="submitting"
        type="primary"
        @click="submit"
      >
        {{ tl('confirm') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
