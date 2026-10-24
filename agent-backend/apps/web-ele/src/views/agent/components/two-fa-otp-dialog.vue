<script lang="ts" setup>
import { ref } from 'vue';

import { ElButton, ElDialog, ElInput, ElMessage } from 'element-plus';

import { is2faOk } from '#/api';
import { tl } from '#/locales';

/**
 * 輸入 Google 2FA 驗證碼後送出的共用視窗（target/tickets#4503：關閉 2FA、重置他人 2FA）。
 * 驗證碼錯誤（1098）時留在視窗內顯示「驗證碼錯誤」可重試、不鎖定；其他錯誤以訊息提示並關閉。
 */
interface OpenOptions {
  confirmText?: string;
  danger?: boolean;
  description?: string;
  label: string;
  submit: (otp: string) => Promise<{ code: number | string; message?: string }>;
  title: string;
}

const visible = ref(false);
const otp = ref('');
const error = ref('');
const submitting = ref(false);
const options = ref<null | OpenOptions>(null);
let resolver: ((ok: boolean) => void) | null = null;

/** 成功回 true；取消或失敗回 false */
function open(o: OpenOptions): Promise<boolean> {
  options.value = o;
  otp.value = '';
  error.value = '';
  visible.value = true;
  return new Promise((resolve) => {
    resolver = resolve;
  });
}

function finish(ok: boolean) {
  visible.value = false;
  const r = resolver;
  resolver = null;
  r?.(ok);
}

async function confirm() {
  if (!options.value || submitting.value) return;
  if (!/^\d{6}$/.test(otp.value)) {
    error.value = tl('tfa_input_code');
    return;
  }
  submitting.value = true;
  error.value = '';
  try {
    const res = await options.value.submit(otp.value);
    const code = Number(res?.code);
    if (is2faOk(code)) {
      ElMessage.success(tl('success'));
      finish(true);
    } else if (code === 1098) {
      error.value = tl('tfa_code_error');
      otp.value = '';
    } else {
      ElMessage.error(res?.message || tl('failure'));
      finish(false);
    }
  } catch (error_: any) {
    // apis 的 HTTP 4xx（權限不足等）會被 request 攔截器全域提示並以回應 body 丟出，這裡不重複提示
    if (!error_?.message) ElMessage.error(tl('failure'));
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
    :title="options?.title"
    append-to-body
    width="min(420px, 92vw)"
    @close="finish(false)"
  >
    <p v-if="options?.description" class="mb-3 text-sm">
      {{ options.description }}
    </p>
    <p class="mb-2 text-sm font-medium">{{ options?.label }}</p>
    <ElInput
      v-model="otp"
      autocomplete="one-time-code"
      inputmode="numeric"
      maxlength="6"
      :placeholder="tl('tfa_input_code')"
      @keyup.enter="confirm"
    />
    <p v-if="error" class="mt-2 text-sm text-red-500">{{ error }}</p>
    <template #footer>
      <ElButton @click="finish(false)">{{ tl('cancel') }}</ElButton>
      <ElButton
        :loading="submitting"
        :type="options?.danger ? 'danger' : 'primary'"
        @click="confirm"
      >
        {{ options?.confirmText || tl('confirm') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
