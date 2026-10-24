<script setup lang="ts">
import type { SendCodeInput } from '#shared/api/endpoints/member'
import type { ApiEnvelope } from '#shared/types/api'
import { VALIDATION_CODE } from '#shared/utils/validation-code'

const props = defineProps<{
  /** 發碼參數（不含 captcha_token，這裡自己補） */
  payload: SendCodeInput
  disabled?: boolean
  /** 排在驗證碼欄位旁的 C17 SEND 鈕（140 寬）；預設是獨立一列的次要按鈕 */
  flush?: boolean
}>()

const emit = defineEmits<{
  sent: [res: ApiEnvelope<unknown>]
  failed: [res: ApiEnvelope<unknown>]
}>()

const codes = useValidationCode()

const remaining = computed(() => codes.remaining(props.payload))
const label = computed(() => codes.countdownLabel(props.payload))
const showCaptcha = ref(false)
const sending = ref(false)

async function send(captchaToken?: string) {
  sending.value = true
  const res = await codes.sendCode(captchaToken ? { ...props.payload, captcha_token: [REDACTED] } : props.payload)
  sending.value = false
  if (isApiOk(res)) {
    emit('sent', res)
    return
  }
  // 失敗訊息由 useApi 的全站彈窗呈現；憑證缺失或過期：重新過一次滑塊
  if (res.code === VALIDATION_CODE.CAPTCHA_REQUIRED) showCaptcha.value = true
  emit('failed', res)
}

function onClick() {
  if (codes.captchaRequired.value) {
    showCaptcha.value = true
    return
  }
  void send()
}

function onVerified(token: [REDACTED] {
  showCaptcha.value = false
  void send(token)
}
</script>

<template>
  <div
    class="flex flex-col gap-(--c17-gap-2)"
    :class="flush ? 'w-(--c17-field-action-w) shrink-0' : ''"
  >
    <!-- flush：排在驗證碼欄位旁的 140 寬 SEND 鈕，跟註冊／忘記密碼同一顆（UiCodeSendButton） -->
    <UiCodeSendButton
      v-if="flush"
      :remaining="remaining"
      :disabled="disabled || sending || remaining > 0"
      @click="onClick"
    />
    <UiButton
      v-else
      variant="secondary"
      :block="false"
      class="self-start"
      :disabled="disabled || sending || remaining > 0"
      @click="onClick"
    >
      <span v-if="remaining > 0">{{ label }}</span>
      <span v-else>{{ $t('auth.otp.action.sendCode') }}</span>
    </UiButton>
    <MemberSliderCaptcha
      v-if="showCaptcha"
      @verified="onVerified"
      @cancel="showCaptcha = false"
    />
  </div>
</template>
