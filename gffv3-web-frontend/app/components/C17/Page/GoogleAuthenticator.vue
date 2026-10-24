<script setup lang="ts">
import { normalizeOtp } from '#shared/utils/auth'

/** C17 的畫面，邏輯在 useGoogleAuthenticatorPage。 */
const {
  has2fa,
  image,
  secret,
  code,
  loading,
  onEnable,
  onDisable,
} = useGoogleAuthenticatorPage()
</script>

<template>
  <div class="mx-auto max-w-sm space-y-4">
    <h1 class="text-xl font-semibold">
      Google Authenticator
    </h1>

    <p class="text-sm">
      {{ $t('auth.twoFactor.label.authentication') }}:
      <span class="font-medium">{{ has2fa ? $t('common.ui.label.enabled') : $t('common.ui.action.close') }}</span>
    </p>

    <section
      v-if="!has2fa"
      class="space-y-3 rounded border border-line bg-surface p-4"
    >
      <p class="text-sm text-muted">
        {{ $t('auth.twoFactor.text.setupGuide') }}
      </p>
      <!-- 後端回的是 QRCode SVG 標記（width=100%），直接渲染 -->
      <!-- eslint-disable vue/no-v-html -->
      <div
        v-if="image"
        class="mx-auto w-48"
        v-html="image"
      />
      <!-- eslint-enable vue/no-v-html -->
      <p
        v-if="secret"
        class="break-all font-mono text-sm"
      >
        {{ secret }}
      </p>

      <form
        class="space-y-2"
        @submit.prevent="onEnable"
      >
        <label class="block text-sm">
          {{ $t('auth.twoFactor.hint.googleCode') }}
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
          :disabled="loading || normalizeOtp(code).length < 6"
        >
          {{ $t('common.ui.action.open') }}
        </button>
      </form>
    </section>

    <section
      v-else
      class="space-y-3 rounded border border-line bg-surface p-4"
    >
      <button
        type="button"
        class="rounded border border-danger px-4 py-1 text-danger disabled:opacity-50"
        :disabled="loading"
        @click="onDisable"
      >
        {{ $t('common.ui.action.close') }}
      </button>
    </section>
  </div>
</template>
