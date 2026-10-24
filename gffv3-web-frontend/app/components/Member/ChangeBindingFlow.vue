<script setup lang="ts">
import type { SendCodeInput } from '#shared/api/endpoints/member'
import { MEMBER_PATH, hasValue } from '#shared/utils/member'
import { CODE_TYPE, VERIFY_BY, joinMobile, normalizeCode } from '#shared/utils/validation-code'

/**
 * 修改綁定兩步，照舊版 C13 `Page/ChangeBinding.vue`：
 * 先用型別 11 驗目前綁定的手機／信箱，再用型別 9 把碼寄到新目標，最後打 reset-mobile / reset-email。
 * 標題列返回在第 2 步時退回第 1 步；成功彈「成功」，關掉回設定頁。
 */
const props = defineProps<{
  channel: 'mobile' | 'email'
}>()

const codes = useValidationCode()
const member = useMember()
const { t } = useI18n()

const step = ref<1 | 2>(1)
const currentCode = ref('')
const newNumber = ref('')
const newEmail = ref('')
const newCode = ref('')
const submitting = ref(false)
/** 改綁完成後鎖住表單（成功提示走全站彈窗） */
const success = ref(false)

/** 國碼清單與綁定手機、註冊同一支（country/list），預設選第一筆 */
const { countries, ensureCountries, defaultCountry } = useCountries()
const newCountryCode = ref('')
onMounted(async () => {
  if (props.channel !== 'mobile') return
  await ensureCountries()
  if (!newCountryCode.value) newCountryCode.value = defaultCountry()?.country_code ?? ''
})

const verifyBy = computed(() => (props.channel === 'mobile' ? VERIFY_BY.MOBILE : VERIFY_BY.EMAIL))
const current = computed(() => {
  const value = props.channel === 'mobile' ? member.profile.value?.mobile : member.profile.value?.email
  return hasValue(value) ? String(value) : ''
})
const newTarget = computed(() => (props.channel === 'mobile' ? joinMobile(newCountryCode.value, newNumber.value) : newEmail.value.trim()))
const canSendNew = computed(() => (props.channel === 'mobile' ? newTarget.value.length >= 7 : /^\S+@\S+\.\S+$/.test(newTarget.value)))

function targetField(value: string) {
  return props.channel === 'mobile' ? { mobile: value } : { email: value }
}

const currentPayload = computed<SendCodeInput>(() => ({ type: CODE_TYPE.VERIFY, verify_by: verifyBy.value, ...targetField(current.value) }))
const newPayload = computed<SendCodeInput>(() => ({ type: CODE_TYPE.REBINDING, verify_by: verifyBy.value, ...targetField(newTarget.value) }))

async function verifyCurrent() {
  const token = [REDACTED]
  if (token.length !== 6 || submitting.value) return
  submitting.value = true
  const res = await codes.checkCode({ token, type: CODE_TYPE.VERIFY, ...targetField(current.value) })
  submitting.value = false
  // 失敗訊息由 useApi 的全站彈窗呈現
  if (!isApiOk(res)) return
  step.value = 2
}

async function submitNew() {
  const token = [REDACTED]
  if (token.length !== 6 || submitting.value) return
  submitting.value = true
  const res = props.channel === 'mobile'
    ? await codes.resetMobile({ token, new_mobile: newTarget.value })
    : await codes.resetEmail({ token, new_email: newTarget.value })
  submitting.value = false
  // 失敗訊息由 useApi 的全站彈窗呈現
  if (!isApiOk(res)) return
  success.value = true
  useAlert().show(t('common.ui.msg.success'), 'success', { redirect: MEMBER_PATH.setting, replace: true })
}

/** 標題列的返回：第 2 步退回第 1 步並回傳 true，第 1 步回傳 false 讓頁面離開。 */
function back(): boolean {
  if (step.value === 1 || success.value) return false
  step.value = 1
  return true
}

defineExpose({ back })
</script>

<template>
  <div class="flex flex-col gap-(--c17-gap-3)">
    <p
      v-if="!current"
      class="text-(length:--c17-text-link) text-muted"
    >
      {{ channel === 'mobile' ? $t('member.bindPhone.empty.notBound') : $t('member.bindEmail.empty.notBound') }}
    </p>

    <form
      v-else-if="step === 1"
      class="flex flex-col gap-(--c17-gap-3)"
      @submit.prevent="verifyCurrent"
    >
      <p class="flex items-center justify-between gap-(--c17-gap-2) rounded-(--c17-field-radius) bg-surface-2 px-(--c17-gap-3) py-(--c17-gap-2) text-(length:--c17-text-link)">
        <span class="text-muted">{{ channel === 'mobile' ? $t('member.bindPhone.label.currentPhone') : $t('member.bindEmail.label.currentEmail') }}</span>
        <span class="font-bold text-ink">{{ current }}</span>
      </p>
      <div class="flex items-start gap-(--c17-gap-2)">
        <UiTextField
          v-model="currentCode"
          class="min-w-0 flex-1"
          inputmode="numeric"
          :maxlength="6"
          :placeholder="$t('auth.otp.placeholder.code')"
        >
          <template #prefix>
            <UiFieldIcon name="verify-code" />
          </template>
        </UiTextField>
        <MemberSendCodeButton
          flush
          :payload="currentPayload"
        />
      </div>
      <UiButton
        type="submit"
        :disabled="submitting || normalizeCode(currentCode).length !== 6"
      >
        {{ $t('common.ui.action.next') }}
      </UiButton>
      <MemberContactCs />
    </form>

    <form
      v-else
      class="flex flex-col gap-(--c17-gap-3)"
      @submit.prevent="submitNew"
    >
      <div
        v-if="channel === 'mobile'"
        class="flex gap-(--c17-gap-2)"
      >
        <UiCountryCodeSelect
          v-model="newCountryCode"
          :countries="countries"
          :disabled="success"
        />
        <UiTextField
          v-model="newNumber"
          class="min-w-0 flex-1"
          type="tel"
          inputmode="numeric"
          :placeholder="$t('member.bindPhone.placeholder.newPhone')"
          :hint="`*${$t('auth.signup.hint.phonePrefix')}`"
          :disabled="success"
        />
      </div>
      <UiTextField
        v-else
        v-model="newEmail"
        type="email"
        :placeholder="$t('member.bindEmail.placeholder.newEmail')"
        :disabled="success"
      >
        <template #prefix>
          <UiFieldIcon name="account" />
        </template>
      </UiTextField>

      <div class="flex items-start gap-(--c17-gap-2)">
        <UiTextField
          v-model="newCode"
          class="min-w-0 flex-1"
          inputmode="numeric"
          :maxlength="6"
          :placeholder="$t('auth.otp.placeholder.code')"
          :disabled="success"
        >
          <template #prefix>
            <UiFieldIcon name="verify-code" />
          </template>
        </UiTextField>
        <MemberSendCodeButton
          flush
          :payload="newPayload"
          :disabled="!canSendNew || success"
        />
      </div>

      <UiButton
        type="submit"
        :disabled="submitting || success || normalizeCode(newCode).length !== 6"
      >
        {{ $t('member.common.action.finish') }}
      </UiButton>
    </form>
  </div>
</template>
