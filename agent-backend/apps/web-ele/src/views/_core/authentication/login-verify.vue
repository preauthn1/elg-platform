<script lang="ts" setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

import { LOGIN_PATH } from '@vben/constants';

import {
  ElButton,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
} from 'element-plus';

import { checkValidateCodeApi, sendValidateCodeApi } from '#/api';
import { tl } from '#/locales';
import { useAuthStore } from '#/store';
import { useLoginFlowStore } from '#/store/login-flow';

/**
 * 登入二階段驗證頁（partial session，token 尚未核發）。
 * 對齊舊版 two_factor_auth.php（Google OTP）、m2fa_auth.php（簡訊＋可切 Google）、
 * mobile_auth.php（簡訊）。bind_mobile 另在 login-bind-mobile.vue。
 */
defineOptions({ name: 'LoginVerify' });

const router = useRouter();
const authStore = useAuthStore();
const flow = useLoginFlowStore();

/** m2fa_auth 可切換驗證方式（對齊舊版下拉）；其餘關卡固定 */
const method = ref<'mobile' | 'tf'>('mobile');

/** 驗證通過後 flow 會被清空，關卡沿用最後一個有效值，畫面不跳成別的關卡 */
const stage = ref(flow.action);
watch(
  () => flow.action,
  (value) => {
    if (value) stage.value = value;
  },
);

const showSelector = computed(() => stage.value === 'm2fa_auth');
const showMobileSection = computed(() =>
  stage.value === 'auth' ? false : method.value === 'mobile',
);

const secret = [REDACTED]'');
const authCode = ref('');
const sending = ref(false);
const submitting = ref(false);

// 發送簡訊 180 秒冷卻（對齊舊版倒數）
const countdown = ref(0);
let countdownTimer: ReturnType<typeof setInterval> | undefined;

function startCountdown() {
  countdown.value = 180;
  countdownTimer = setInterval(() => {
    countdown.value -= 1;
    if (countdown.value <= 0 && countdownTimer) {
      clearInterval(countdownTimer);
      countdownTimer = undefined;
    }
  }, 1000);
}

async function sendSms() {
  sending.value = true;
  try {
    const res = await sendValidateCodeApi({
      ID: flow.id,
      mobile: flow.mobile,
      type: 4,
      verify_by: 1,
    });
    ElMessage[Number(res.code) === 1000 ? 'success' : 'error'](res.message);
    startCountdown();
  } finally {
    sending.value = false;
  }
}

/** 簡訊驗證：checkValidateCode 通過後以暫存帳密重新登入（mobile_auth=true） */
async function verifyMobile() {
  submitting.value = true;
  try {
    const res = await checkValidateCodeApi({
      mobile: flow.mobile,
      token: [REDACTED],
    });
    if (Number(res.code) === 1000) {
      await authStore.resumeLogin(true);
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    submitting.value = false;
  }
}

/** Google OTP 驗證：gateway 以 partial session 帳密＋secret 重打 api/login */
async function verifyGoogle() {
  submitting.value = true;
  try {
    await authStore.verifyGoogleSecret(secret.value.trim());
  } finally {
    submitting.value = false;
  }
}

function cancel() {
  flow.clear();
  router.replace(LOGIN_PATH);
}

onMounted(() => {
  // 未經登入流程直接進入（或重新整理）→ 回登入頁
  if (!flow.action || flow.action === 'bind_mobile') {
    router.replace(LOGIN_PATH);
  }
});
onBeforeUnmount(() => {
  if (countdownTimer) clearInterval(countdownTimer);
});
</script>

<template>
  <div>
    <ElForm label-position="top" @submit.prevent>
      <!-- m2fa_auth：選擇驗證方式（手機認證 / Google 兩步驟） -->
      <ElFormItem v-if="showSelector">
        <ElSelect v-model="method" class="!w-full">
          <ElOption :label="tl('mobile_authentication')" value="mobile" />
          <ElOption :label="tl('two_factor_authentication')" value="tf" />
        </ElSelect>
      </ElFormItem>

      <!-- 簡訊驗證 -->
      <template v-if="showMobileSection">
        <h2 class="mb-2 text-2xl font-bold">
          {{ tl('mobile_authentication') }}
        </h2>
        <p class="mb-4 text-sm text-muted-foreground">
          {{ tl('mobile') }}: {{ flow.maskedMobile }}
        </p>
        <ElFormItem>
          <ElButton
            :disabled="countdown > 0"
            :loading="sending"
            @click="sendSms"
          >
            {{
              countdown > 0
                ? `${tl('resend')} (${countdown} ${tl('sec')})`
                : tl('send_sms')
            }}
          </ElButton>
        </ElFormItem>
        <ElFormItem :label="tl('auth_code')">
          <ElInput
            v-model="authCode"
            :placeholder="tl('auth_code')"
            autocomplete="off"
          />
        </ElFormItem>
        <ElButton
          :loading="submitting"
          class="w-full"
          type="primary"
          @click="verifyMobile"
        >
          {{ tl('auth') }}
        </ElButton>
      </template>

      <!-- Google OTP 驗證 -->
      <template v-else>
        <h2 class="mb-2 text-2xl font-bold">{{ tl('2fa') }}</h2>
        <p class="mb-4 text-sm text-muted-foreground">
          {{ tl('2fa_subtitle') }}
        </p>
        <ElFormItem>
          <ElInput
            v-model="secret"
            :placeholder="tl('auth_code')"
            autocomplete="off"
            @keyup.enter="verifyGoogle"
          />
        </ElFormItem>
        <ElButton
          :loading="submitting"
          class="w-full"
          type="primary"
          @click="verifyGoogle"
        >
          {{ tl('auth') }}
        </ElButton>
      </template>

      <ElButton class="!ml-0 mt-3 w-full" @click="cancel">
        {{ tl('cancel') }}
      </ElButton>
    </ElForm>
  </div>
</template>
