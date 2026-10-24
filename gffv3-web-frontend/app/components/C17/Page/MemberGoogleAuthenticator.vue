<script setup lang="ts">
import { normalizeCode } from '#shared/utils/validation-code'

/** C17 Google 2FA 設定。流程在 useGoogleAuthSetting。 */
const {
  image,
  secret,
  code,
  submitting,
  showCodeModal,
  enabled,
  toggle,
  closeModal,
  enable,
} = useGoogleAuthSetting()
</script>

<template>
  <div>
    <C17PageHeader
      title="Google Authenticator"
      fallback="/member/setting"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <section
        v-if="image"
        class="flex flex-col gap-(--c17-gap-3) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]"
      >
        <div class="flex items-center gap-(--c17-gap-3)">
          <p class="min-w-0 flex-1 text-(length:--c17-text-link) text-ink">
            {{ $t('auth.twoFactor.text.setupGuide') }}
          </p>
          <UiSwitch
            class="shrink-0"
            :on="enabled"
            :disabled="submitting"
            :label="$t('auth.twoFactor.label.authentication')"
            @click="toggle"
          />
        </div>
        <!-- 後端回的是 SVG 標記，不是網址 -->
        <!-- eslint-disable vue/no-v-html -->
        <div
          class="mx-auto w-full max-w-[240px] rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-2) shadow-[var(--c17-shadow-tile)]"
          v-html="image"
        />
        <!-- eslint-enable vue/no-v-html -->
        <code
          v-if="secret"
          class="block break-all text-center text-(length:--c17-text-link) font-bold text-ink"
        >{{ secret }}</code>
      </section>
    </div>

    <UiModal
      v-if="showCodeModal"
      title="Google Authenticator"
      :dismissible="false"
      @close="closeModal"
    >
      <form
        class="flex flex-col gap-(--c17-gap-3)"
        @submit.prevent="enable"
      >
        <UiTextField
          v-model="code"
          inputmode="numeric"
          :maxlength="6"
          :placeholder="$t('auth.otp.placeholder.code')"
          :hint="$t('auth.twoFactor.hint.googleCode')"
        />
        <UiButton
          type="submit"
          :disabled="submitting || normalizeCode(code).length !== 6"
        >
          {{ $t('common.ui.action.determine') }}
        </UiButton>
      </form>
    </UiModal>
  </div>
</template>
