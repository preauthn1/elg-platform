<script setup lang="ts">
import { normalizeOtp } from '#shared/utils/auth'

/** C17 的畫面，邏輯在 useRetrievePasswordPage。 */
const {
  store,
  form,
  mobile,
  loading,
  submit,
} = useRetrievePasswordPage()
</script>

<template>
  <div class="mx-auto max-w-sm space-y-4">
    <h1 class="text-xl font-semibold">
      {{ $t('auth.password.title.retrieve') }}
    </h1>
    <p class="text-sm text-muted">
      {{ $t('auth.password.title.resetVerification') }}
    </p>

    <form
      class="space-y-3 rounded border border-line bg-surface p-4"
      @submit.prevent="submit"
    >
      <div class="flex gap-4 text-sm">
        <label><input
          v-model="form.verify_by"
          type="radio"
          :value="1"
        > {{ $t('auth.otp.label.phoneVerification') }}</label>
        <label><input
          v-model="form.verify_by"
          type="radio"
          :value="2"
        > {{ $t('auth.otp.title.emailVerification') }}</label>
      </div>

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

      <label class="block text-sm">
        {{ $t('auth.common.label.username') }}（選填）
        <input
          v-model="form.username"
          type="text"
          class="mt-1 w-full rounded border border-line px-2 py-1"
          autocomplete="username"
        >
      </label>

      <AuthSendCodeButton
        :type="3"
        :verify-by="form.verify_by"
        :mobile="mobile"
        :email="form.email"
        :username="form.username"
        :guest-validate-token=[REDACTED]"
      />

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
        {{ $t('common.ui.action.next') }}
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
