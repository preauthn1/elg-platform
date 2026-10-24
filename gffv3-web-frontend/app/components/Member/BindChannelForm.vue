<script setup lang="ts">
import type { SendCodeInput } from '#shared/api/endpoints/member'
import { CODE_TYPE, VERIFY_BY, joinMobile, normalizeCode } from '#shared/utils/validation-code'

/** 綁定手機（type 1 / verify_by 1）或信箱（type 1 / verify_by 2），驗碼成功即綁定完成。 */
const props = defineProps<{
  channel: 'mobile' | 'email'
}>()

const emit = defineEmits<{
  done: []
}>()

const codes = useValidationCode()

/** 國碼清單與註冊、忘記密碼同一支（country/list），預設選第一筆 */
const { countries, ensureCountries, defaultCountry } = useCountries()
const countryCode = ref('')
onMounted(async () => {
  if (props.channel !== 'mobile') return
  await ensureCountries()
  if (!countryCode.value) countryCode.value = defaultCountry()?.country_code ?? ''
})
const number = ref('')
const email = ref('')
const code = ref('')
const sent = ref(false)
const submitting = ref(false)

const target = computed(() => (props.channel === 'mobile' ? joinMobile(countryCode.value, number.value) : email.value.trim()))
const canSend = computed(() => (props.channel === 'mobile' ? target.value.length >= 7 : /^\S+@\S+\.\S+$/.test(target.value)))

const payload = computed<SendCodeInput>(() =>
  props.channel === 'mobile'
    ? { type: CODE_TYPE.BINDING, verify_by: VERIFY_BY.MOBILE, mobile: target.value }
    : { type: CODE_TYPE.BINDING, verify_by: VERIFY_BY.EMAIL, email: target.value },
)

function onSent() {
  sent.value = true
}

async function submit() {
  const token = [REDACTED]
  if (token.length !== 6 || submitting.value) return
  submitting.value = true
  const res = await codes.checkCode({
    token,
    type: CODE_TYPE.BINDING,
    ...(props.channel === 'mobile' ? { mobile: target.value } : { email: target.value }),
  })
  submitting.value = false
  // 失敗訊息由 useApi 的全站彈窗呈現
  if (isApiOk(res)) emit('done')
}
</script>

<template>
  <form
    class="flex flex-col gap-(--c17-gap-3)"
    @submit.prevent="submit"
  >
    <!-- 版面照忘記密碼頁（C17）：國碼方塊 + 號碼同一列，驗證碼欄與 SEND 鈕同一列，欄位不帶上方標題 -->
    <div
      v-if="channel === 'mobile'"
      class="flex gap-(--c17-gap-2)"
    >
      <UiCountryCodeSelect
        v-model="countryCode"
        :countries="countries"
        :disabled="sent"
      />
      <UiTextField
        v-model="number"
        class="min-w-0 flex-1"
        type="tel"
        inputmode="numeric"
        :placeholder="$t('auth.common.label.phoneNumber')"
        :hint="`*${$t('auth.signup.hint.phonePrefix')}`"
        :disabled="sent"
      />
    </div>
    <UiTextField
      v-else
      v-model="email"
      type="email"
      :placeholder="$t('member.bindEmail.placeholder.email')"
      :disabled="sent"
    >
      <template #prefix>
        <UiFieldIcon name="account" />
      </template>
    </UiTextField>

    <div class="flex items-start gap-(--c17-gap-2)">
      <UiTextField
        v-model="code"
        class="min-w-0 flex-1"
        inputmode="numeric"
        :maxlength="6"
        :placeholder="channel === 'mobile' ? $t('auth.otp.label.smsCode') : $t('auth.common.label.emailVerifyCode')"
      >
        <template #prefix>
          <UiFieldIcon name="verify-code" />
        </template>
      </UiTextField>
      <MemberSendCodeButton
        flush
        :payload="payload"
        :disabled="!canSend"
        @sent="onSent"
      />
    </div>

    <UiButton
      type="submit"
      :disabled="submitting || normalizeCode(code).length !== 6"
    >
      {{ channel === 'mobile' ? $t('common.ui.action.next') : $t('member.common.action.finish') }}
    </UiButton>

    <MemberContactCs v-if="channel === 'mobile'" />
  </form>
</template>
