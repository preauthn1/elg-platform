<script setup lang="ts">
import type { CaptchaData } from '#shared/api/endpoints/member'

const emit = defineEmits<{
  verified: [token: [REDACTED]
  cancel: []
}>()

const codes = useValidationCode()
const { t } = useI18n()

const captcha = ref<CaptchaData | null>(null)
/** 拼圖塊左緣，單位就是 bg_width 的邏輯像素 */
const x = ref(0)
const loading = ref(false)
const verifying = ref(false)
const error = ref('')

const maxX = computed(() => (captcha.value ? captcha.value.bg_width - captcha.value.piece_width : 0))
const pieceStyle = computed(() => {
  if (!captcha.value) return {}
  return {
    left: `${(x.value / captcha.value.bg_width) * 100}%`,
    top: `${(captcha.value.piece_y / captcha.value.bg_height) * 100}%`,
    width: `${(captcha.value.piece_width / captcha.value.bg_width) * 100}%`,
  }
})

async function loadCaptcha() {
  loading.value = true
  error.value = ''
  const res = await codes.getCaptcha()
  loading.value = false
  if (isApiOk(res)) {
    captcha.value = res.data
    x.value = 0
    return
  }
  captcha.value = null
  error.value = t('auth.captcha.error.loadFailed')
  // 出題走 silent（不觸發全站錯誤碼導頁），後端訊息自己丟彈窗
  useAlert().showResponse(res)
}

async function verify() {
  if (!captcha.value || verifying.value) return
  verifying.value = true
  const res = await codes.verifyCaptcha(captcha.value.captcha_id, Math.round(x.value))
  verifying.value = false
  if (isApiOk(res)) {
    emit('verified', res.data.captcha_token)
    return
  }
  // 驗證走 silent，後端沒給訊息時退回前端文案
  useAlert().show(res.message || t('auth.captcha.error.verifyFailed'), 'error', { tid: res.tid })
  await loadCaptcha()
}

onMounted(loadCaptcha)
</script>

<template>
  <div class="flex flex-col gap-(--c17-gap-2) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]">
    <p class="font-bold text-(length:--c17-text-body) text-ink">
      {{ $t('auth.captcha.title.securityVerification') }}
    </p>

    <div
      v-if="captcha"
      class="relative w-full max-w-[300px] select-none overflow-hidden rounded-(--c17-radius-sm)"
    >
      <img
        :src="captcha.bg_image"
        class="block w-full"
        alt=""
      >
      <img
        :src="captcha.piece_image"
        class="absolute"
        :style="pieceStyle"
        alt=""
      >
    </div>
    <p
      v-else-if="loading"
      class="text-(length:--c17-text-link) text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>

    <input
      v-if="captcha"
      v-model.number="x"
      type="range"
      min="0"
      :max="maxX"
      step="1"
      class="w-full max-w-[300px] accent-accent"
    >
    <p class="text-(length:--c17-text-label) text-muted">
      {{ $t('auth.captcha.hint.dragSlider') }}
    </p>

    <div class="flex flex-wrap gap-(--c17-gap-2)">
      <UiButton
        :block="false"
        :disabled="!captcha || verifying"
        @click="verify"
      >
        {{ verifying ? $t('auth.captcha.msg.verifying') : $t('common.ui.action.verify') }}
      </UiButton>
      <UiButton
        variant="secondary"
        :block="false"
        :disabled="loading"
        @click="loadCaptcha"
      >
        {{ $t('auth.captcha.action.refresh') }}
      </UiButton>
      <UiButton
        variant="secondary"
        :block="false"
        @click="emit('cancel')"
      >
        {{ $t('common.ui.action.cancel') }}
      </UiButton>
    </div>

    <p
      v-if="error"
      class="text-(length:--c17-text-link) text-danger"
    >
      {{ error }}
    </p>
  </div>
</template>
