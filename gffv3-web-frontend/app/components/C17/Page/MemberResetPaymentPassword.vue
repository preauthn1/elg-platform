<script setup lang="ts">
import { normalizeCode } from '#shared/utils/validation-code'

/** C17 找回支付密碼。流程在 useResetPaymentPassword。 */
const {
  t,
  form,
  passwords,
  channel,
  number,
  email,
  code,
  submitting,
  success,
  countries,
  countryCode,
  canSend,
  payload,
  submit,
  stepBack,
} = useResetPaymentPassword()

const channelTabs = computed(() => [
  { key: 'mobile' as const, label: t('auth.common.label.phoneNumber') },
  { key: 'email' as const, label: 'E-mail' },
])
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('member.paymentPassword.title.retrieve')"
      fallback="/member/setting"
      :balance="false"
      :step-back="stepBack"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <div class="rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]">
        <MemberPaymentPasswordForm
          v-show="!passwords"
          ref="form"
          :require-current="false"
          defer-submit
          @filled="passwords = $event"
        />

        <form
          v-if="passwords"
          class="flex flex-col gap-(--c17-gap-3)"
          @submit.prevent="submit"
        >
          <UiPillTabs
            v-model="channel"
            :items="channelTabs"
          />

          <div
            v-if="channel === 'mobile'"
            class="flex gap-(--c17-gap-2)"
          >
            <UiCountryCodeSelect
              v-model="countryCode"
              :countries="countries"
              :disabled="success"
            />
            <UiTextField
              v-model="number"
              class="min-w-0 flex-1"
              type="tel"
              inputmode="numeric"
              :placeholder="$t('auth.common.label.phoneNumber')"
              :hint="`*${$t('auth.signup.hint.phonePrefix')}`"
              :disabled="success"
            />
          </div>
          <UiTextField
            v-else
            v-model="email"
            type="email"
            :placeholder="$t('member.bindEmail.placeholder.email')"
            :disabled="success"
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
              :placeholder="$t('auth.otp.placeholder.code')"
              :disabled="success"
            >
              <template #prefix>
                <UiFieldIcon name="verify-code" />
              </template>
            </UiTextField>
            <MemberSendCodeButton
              flush
              :payload="payload"
              :disabled="!canSend || success"
            />
          </div>

          <UiButton
            type="submit"
            :disabled="submitting || success || normalizeCode(code).length !== 6"
          >
            {{ $t('member.common.action.finish') }}
          </UiButton>

          <MemberContactCs />
        </form>
      </div>
    </div>
  </div>
</template>
