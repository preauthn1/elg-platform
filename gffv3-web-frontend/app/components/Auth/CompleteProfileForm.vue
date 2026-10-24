<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import type { RequestOf } from '#shared/api/registry'
import { normalizeMobile, normalizeOtp } from '#shared/utils/auth'
import { useMemberStore } from '~/stores/member'

/**
 * TG 會員補資料：只渲染 agent/template.missing_required_fields 內的欄位，
 * 手機／信箱各帶 OTP（member/send-validation-code type=1）。有現值者預填可改。
 */
const props = defineProps<{
  missing: string[]
}>()

const emit = defineEmits<{
  done: []
}>()

const { completeProfile } = useTelegramLogin()
const memberStore = useMemberStore()

const need = computed(() => new Set(props.missing))
const needRealName = computed(() => need.value.has('last_name') || need.value.has('real_name'))
const needPaymentPassword = [REDACTED] => need.value.has('payment_password'))
const needMobile = computed(() => need.value.has('mobile'))
const needEmail = computed(() => need.value.has('email'))
const needBirthday = computed(() => need.value.has('birthday'))

const form = reactive({
  real_name: memberStore.profile?.last_name ?? '',
  payment_password: '',
  country_code: '',
  phone: memberStore.profile?.mobile ?? '',
  mobile_token: '',
  email: memberStore.profile?.email ?? '',
  email_token: '',
  birthday: memberStore.profile?.birthday ?? '',
})

const mobile = computed(() => normalizeMobile(form.country_code, form.phone))
const loading = ref(false)

async function submit() {
  loading.value = true
  const payload: RequestOf<'tgbot/complete-profile'> = {}
  if (needRealName.value && form.real_name) payload.real_name = form.real_name
  if (needPaymentPassword.value && form.payment_password) payload.payment_password = [REDACTED]
  if (needMobile.value && mobile.value) {
    payload.mobile = mobile.value
    payload.mobile_token = [REDACTED]
  }
  if (needEmail.value && form.email) {
    payload.email = form.email
    payload.email_token = [REDACTED]
  }
  if (needBirthday.value && form.birthday) payload.birthday = form.birthday

  const res = await completeProfile(payload)
  loading.value = false
  if (isApiOk(res)) {
    emit('done')
    return
  }
  // postRaw 不經 useApi 的全站錯誤處理，失敗訊息要自己丟彈窗
  useAlert().showResponse(res)
}
</script>

<template>
  <form
    class="space-y-3 rounded border border-line bg-surface p-4"
    @submit.prevent="submit"
  >
    <h2 class="font-semibold">
      {{ $t('auth.signup.title.completeProfile') }}
    </h2>

    <label
      v-if="needRealName"
      class="block text-sm"
    >
      {{ $t('member.realname.label.realName') }}
      <input
        v-model="form.real_name"
        type="text"
        class="mt-1 w-full rounded border border-line px-2 py-1"
      >
    </label>

    <label
      v-if="needPaymentPassword"
      class="block text-sm"
    >
      {{ $t('member.paymentPassword.label.paymentPassword') }}
      <input
        v-model="form.payment_password"
        type="password"
        class="mt-1 w-full rounded border border-line px-2 py-1"
        autocomplete="new-password"
      >
    </label>

    <div
      v-if="needMobile"
      class="space-y-2"
    >
      <div class="flex gap-2">
        <label class="block w-24 text-sm">
          {{ $t('auth.common.label.countryCode') }}
          <input
            v-model="form.country_code"
            type="text"
            inputmode="numeric"
            class="mt-1 w-full rounded border border-line px-2 py-1"
          >
        </label>
        <label class="block flex-1 text-sm">
          {{ $t('auth.common.label.phoneNumber') }}
          <input
            v-model="form.phone"
            type="tel"
            class="mt-1 w-full rounded border border-line px-2 py-1"
          >
        </label>
      </div>
      <div class="flex items-end gap-2">
        <label class="block flex-1 text-sm">
          {{ $t('auth.otp.label.smsCode') }}
          <input
            v-model="form.mobile_token"
            type="text"
            inputmode="numeric"
            maxlength="6"
            class="mt-1 w-full rounded border border-line px-2 py-1"
          >
        </label>
        <AuthSendCodeButton
          :type="1"
          :verify-by="1"
          :mobile="mobile"
        />
      </div>
    </div>

    <div
      v-if="needEmail"
      class="space-y-2"
    >
      <label class="block text-sm">
        {{ $t('auth.common.label.email') }}
        <input
          v-model="form.email"
          type="email"
          class="mt-1 w-full rounded border border-line px-2 py-1"
        >
      </label>
      <div class="flex items-end gap-2">
        <label class="block flex-1 text-sm">
          {{ $t('auth.common.label.emailVerifyCode') }}
          <input
            v-model="form.email_token"
            type="text"
            inputmode="numeric"
            maxlength="6"
            class="mt-1 w-full rounded border border-line px-2 py-1"
          >
        </label>
        <AuthSendCodeButton
          :type="1"
          :verify-by="2"
          :email="form.email"
        />
      </div>
    </div>

    <label
      v-if="needBirthday"
      class="block text-sm"
    >
      {{ $t('member.profile.label.birthday') }}
      <input
        v-model="form.birthday"
        type="date"
        class="mt-1 w-full rounded border border-line px-2 py-1"
      >
    </label>

    <button
      type="submit"
      class="rounded bg-accent px-4 py-1 text-inverse disabled:opacity-50"
      :disabled="loading"
    >
      {{ $t('common.ui.action.submit') }}
    </button>
  </form>
</template>
