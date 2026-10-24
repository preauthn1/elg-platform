<script setup lang="ts">
import { normalizeOtp } from '#shared/utils/auth'

/** C17 的畫面，邏輯在 usePhoneVerificationPage。 */
const {
  loggedIn,
  form,
  mobile,
  loading,
  submit,
} = usePhoneVerificationPage()
</script>

<template>
  <div class="mx-auto max-w-sm space-y-4">
    <h1 class="text-xl font-semibold">
      {{ form.verify_by === 1 ? $t('auth.otp.label.phoneVerification') : $t('auth.otp.title.emailVerification') }}
    </h1>

    <form
      class="space-y-3 rounded border border-line bg-surface p-4"
      @submit.prevent="submit"
    >
      <div
        v-if="form.verify_by === 1"
        class="flex gap-2"
      >
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
      <label
        v-else
        class="block text-sm"
      >
        {{ $t('auth.common.label.email') }}
        <input
          v-model="form.email"
          type="email"
          class="mt-1 w-full rounded border border-line px-2 py-1"
        >
      </label>

      <AuthSendCodeButton
        v-if="loggedIn"
        :type="1"
        :verify-by="form.verify_by"
        :mobile="mobile"
        :email="form.email"
      />
      <p
        v-else
        class="text-xs text-muted"
      >
        {{ $t('auth.otp.hint.codeToPhone') }}
      </p>

      <label class="block text-sm">
        {{ $t('auth.otp.placeholder.code') }}
        <input
          v-model="form.token"
          type="text"
          inputmode="numeric"
          maxlength="6"
          class="mt-1 w-full rounded border border-line px-2 py-1"
        >
      </label>

      <button
        type="submit"
        class="rounded bg-accent px-4 py-1 text-inverse disabled:opacity-50"
        :disabled="loading || normalizeOtp(form.token).length < 6"
      >
        {{ $t('common.ui.action.verify') }}
      </button>
    </form>

    <NuxtLink
      to="/"
      class="text-sm text-accent underline"
    >
      {{ $t('site.nav.nav.home') }}
    </NuxtLink>
  </div>
</template>
