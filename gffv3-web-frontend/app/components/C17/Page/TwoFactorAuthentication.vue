<script setup lang="ts">
import { normalizeOtp } from '#shared/utils/auth'

/** C17 的畫面，邏輯在 useTwoFactorAuthenticationPage。 */
const {
  store,
  options,
  selectedIndex,
  selected,
  code,
  loading,
  locked,
  optionLabel,
  submit,
} = useTwoFactorAuthenticationPage()
</script>

<template>
  <div class="mx-auto max-w-sm space-y-4">
    <h1 class="text-xl font-semibold">
      {{ $t('auth.twoFactor.label.authentication') }}
    </h1>

    <form
      class="space-y-3 rounded border border-line bg-surface p-4"
      @submit.prevent="submit"
    >
      <label
        v-if="options.length > 1"
        class="block text-sm"
      >
        {{ $t('auth.twoFactor.hint.method') }}
        <select
          v-model.number="selectedIndex"
          class="mt-1 w-full rounded border border-line px-2 py-1"
        >
          <option
            v-for="(o, i) in options"
            :key="o.type"
            :value="i"
          >
            {{ o.type === 'mobile' ? $t('auth.otp.label.phoneVerification') : o.type === 'email' ? $t('auth.otp.title.emailVerification') : 'Google Authenticator' }} {{ optionLabel(o) }}
          </option>
        </select>
      </label>
      <p
        v-else-if="selected"
        class="text-sm"
      >
        {{ selected.type === 'google_auth' ? 'Google Authenticator' : $t('member.security.label.ipChangeVerification') }} {{ selected.type === 'google_auth' ? '' : optionLabel(selected) }}
      </p>

      <AuthSendCodeButton
        v-if="selected && selected.type !== 'google_auth' && selected.verify_by"
        :type="10"
        :verify-by="selected.verify_by"
        :mobile="selected.type === 'mobile' ? selected.target : undefined"
        :email="selected.type === 'email' ? selected.target : undefined"
        :username="store.pendingLogin?.params.username"
      />

      <label class="block text-sm">
        {{ selected?.type === 'google_auth' ? $t('auth.twoFactor.hint.googleCode') : $t('auth.otp.placeholder.code') }}
        <input
          v-model="code"
          type="text"
          inputmode="numeric"
          maxlength="6"
          class="mt-1 w-full rounded border border-line px-2 py-1"
          autocomplete="one-time-code"
        >
      </label>

      <button
        type="submit"
        class="rounded bg-accent px-4 py-1 text-inverse disabled:opacity-50"
        :disabled="loading || locked || normalizeOtp(code).length < 6"
      >
        {{ $t('common.ui.action.verify') }}
      </button>
    </form>

    <NuxtLink
      to="/login"
      class="text-sm text-accent underline"
    >
      {{ $t('auth.login.action.login') }}
    </NuxtLink>
  </div>
</template>
