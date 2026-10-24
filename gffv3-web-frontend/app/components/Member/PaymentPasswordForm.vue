<script setup lang="ts">
import type { ChangePaymentPasswordInput } from '#shared/api/endpoints/member'
import { MEMBER_PATH } from '#shared/utils/member'

/**
 * 首次設定（不帶舊密碼）與變更（要舊密碼）共用；成功後 store 的 payment_password 會變 1。
 * 照舊版 C13 `Page/PaymentPassword.vue` 分步驟：
 *   變更：1 舊密碼 → 2 新密碼 (1/2) → 3 確認 (2/2)
 *   首次：2 新密碼 (1/2) → 3 確認 (2/2)
 *   重設：同首次，但第 3 步不送出，改 emit filled 交給頁面接驗證步驟（deferSubmit）
 * 「忘記舊密碼」只在變更的第 1 步出現，replace 到重設頁。
 */
const props = defineProps<{
  requireCurrent: boolean
  deferSubmit?: boolean
}>()

const emit = defineEmits<{
  done: []
  filled: [input: { next: string, confirm: string }]
}>()

const member = useMember()
const { t } = useI18n()

type Step = 1 | 2 | 3
const firstStep: Step = props.requireCurrent ? 1 : 2
const step = ref<Step>(firstStep)

const current = ref('')
const next = ref('')
const confirm = ref('')
const submitting = ref(false)
const success = ref(false)

const PIN_LENGTH = 6
const filled = (v: string) => v.length >= PIN_LENGTH
const mismatch = computed(() => filled(confirm.value) && next.value !== confirm.value)

const canNext = computed(() => {
  if (step.value === 1) return filled(current.value)
  if (step.value === 2) return filled(next.value)
  return filled(confirm.value) && !mismatch.value
})

const label = computed(() => {
  if (step.value === 1) return t('member.password.label.oldPassword')
  if (step.value === 2) return t('member.paymentPassword.placeholder.newPassword')
  return t('member.paymentPassword.placeholder.confirmPassword')
})

function onNext() {
  if (!canNext.value || submitting.value || success.value) return
  if (step.value < 3) {
    step.value = (step.value + 1) as Step
    return
  }
  if (props.deferSubmit) emit('filled', { next: next.value, confirm: confirm.value })
  else void submit()
}

async function submit() {
  const input: ChangePaymentPasswordInput = {
    new_payment_password: [REDACTED],
    new_payment_password_confirmation: confirm.value,
  }
  if (props.requireCurrent) input.current_payment_password = [REDACTED]
  submitting.value = true
  const res = await member.changePaymentPassword(input)
  submitting.value = false
  // 失敗訊息由 useApi 的全站彈窗呈現
  if (!isApiOk(res)) return
  success.value = true
  emit('done')
  // 照舊版 PaymentPassword.vue：成功彈提示，關掉後回會員中心（replace，按返回不回到表單）
  useAlert().show(t('member.paymentPassword.msg.changeSuccess'), 'success', { redirect: MEMBER_PATH.home, replace: true })
}

/** 標題列的返回：還有上一步就退一步並回傳 true，已經在第一步回傳 false 讓頁面離開。 */
function back(): boolean {
  if (step.value === firstStep) return false
  step.value = (step.value - 1) as Step
  return true
}

defineExpose({ back })
</script>

<template>
  <form
    class="flex flex-col gap-(--c17-gap-3)"
    @submit.prevent="onNext"
  >
    <p
      class="text-center font-bold text-ink"
      :style="{ fontSize: 'var(--c17-text-lg)' }"
    >
      {{ label }}
    </p>

    <UiPinInput
      v-if="step === 1"
      key="current"
      v-model="current"
      :disabled="success"
      @submit="onNext"
    />
    <UiPinInput
      v-else-if="step === 2"
      key="next"
      v-model="next"
      :disabled="success"
      @submit="onNext"
    />
    <UiPinInput
      v-else
      key="confirm"
      v-model="confirm"
      :error="mismatch"
      :disabled="success"
      @submit="onNext"
    />

    <p
      v-if="step === 3 && mismatch"
      class="text-center text-(length:--c17-text-link) text-danger"
    >
      {{ $t('validation.password.error.mismatch') }}
    </p>
    <p
      v-if="step !== 1"
      class="text-center text-(length:--c17-text-link) text-muted"
    >
      ({{ step - 1 }}/2)
    </p>

    <UiButton
      type="submit"
      :disabled="!canNext || submitting || success"
      :loading="submitting"
    >
      {{ step === 3 && !deferSubmit ? $t('member.common.action.finish') : $t('common.ui.action.next') }}
    </UiButton>

    <MemberContactCs />

    <UiButton
      v-if="requireCurrent && step === 1"
      variant="secondary"
      @click="navigateTo(MEMBER_PATH.resetPaymentPassword, { replace: true })"
    >
      {{ $t('auth.password.action.forgetOld') }}
    </UiButton>
  </form>
</template>
