<script lang="ts" setup>
import { ref } from 'vue';

import { ElButton, ElDialog, ElInput, ElMessage } from 'element-plus';

import { enable2faApi, get2faImageApi, is2faOk } from '#/api';
import { tl } from '#/locales';

/**
 * 本人設置 Google 2FA 的視窗（target/tickets#4521）：顯示 QRCode 與金鑰，輸入 6 位數驗證碼後由
 * api/2fa/enable 驗證並開啟。驗證碼錯誤（1098）留在視窗內提示、不鎖定，可重試。
 */
const visible = ref(false);
const loading = ref(false);
const submitting = ref(false);
const image = ref('');
const secret = [REDACTED]'');
const otp = ref('');
const error = ref('');
let resolver: ((ok: boolean) => void) | null = null;

/** 成功開啟回 true；取消或失敗回 false */
async function open(): Promise<boolean> {
  otp.value = '';
  error.value = '';
  image.value = '';
  secret.value = '';
  visible.value = true;
  loading.value = true;
  const promise = new Promise<boolean>((resolve) => {
    resolver = resolve;
  });
  try {
    const img: any = await get2faImageApi();
    image.value = img?.image ?? '';
    secret.value = img?.secret ?? '';
  } catch {
    finish(false);
  } finally {
    loading.value = false;
  }
  return promise;
}

function finish(ok: boolean) {
  visible.value = false;
  const r = resolver;
  resolver = null;
  r?.(ok);
}

async function confirm() {
  if (submitting.value || loading.value) return;
  if (!/^\d{6}$/.test(otp.value)) {
    error.value = tl('tfa_input_code');
    return;
  }
  submitting.value = true;
  error.value = '';
  try {
    const res: any = await enable2faApi({ secret: [REDACTED] });
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
    // apis 的 HTTP 4xx 會被 request 攔截器全域提示並以回應 body 丟出，這裡不重複提示
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
    :title="tl('tfa_setup_title')"
    append-to-body
    width="min(420px, 92vw)"
    @close="finish(false)"
  >
    <p class="mb-3 text-sm">{{ tl('tfa_setup_desc') }}</p>
    <div v-loading="loading" class="min-h-24 text-center">
      <!-- eslint-disable-next-line vue/no-v-html -->
      <div class="flex justify-center" v-html="image"></div>
      <p class="mt-2 font-mono text-sm font-semibold text-red-500">
        {{ secret }}
      </p>
    </div>
    <p class="mt-4 mb-2 text-sm font-medium">{{ tl('tfa_input_code') }}</p>
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
        :disabled="loading || !secret"
        :loading="submitting"
        type="primary"
        @click="confirm"
      >
        {{ tl('tfa_setup_confirm') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
