<script setup lang="ts">
import { normalizeCode } from '#shared/utils/validation-code'

/** C17 IP 變更登入驗證開關。流程在 useIpChangeVerification。 */
const {
  editing,
  code,
  submitting,
  email,
  mobileDisplay,
  rows,
  payload,
  startToggle,
  cancel,
  confirmToggle,
} = useIpChangeVerification()
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('member.security.label.ipChangeVerification')"
      fallback="/member/setting"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <p class="text-(length:--c17-text-link) text-muted">
        {{ $t('member.security.hint.ipChangeVerification') }}
      </p>

      <div
        v-for="row in rows"
        :key="row.channel"
        class="flex items-center gap-(--c17-gap-3) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]"
      >
        <span class="shrink-0 font-bold text-(length:--c17-text-link) text-ink">{{ $t(row.labelKey) }}</span>
        <span class="ml-auto min-w-0 truncate text-(length:--c17-text-label) text-muted">{{ row.display }}</span>
        <UiSwitch
          :on="row.on"
          :label="$t(row.labelKey)"
          @click="startToggle(row.channel)"
        />
      </div>
    </div>

    <UiModal
      v-if="editing && payload"
      :title="$t('auth.captcha.label.verifyCode')"
      @close="cancel"
    >
      <form
        class="flex flex-col gap-(--c17-gap-3)"
        @submit.prevent="confirmToggle"
      >
        <p class="text-(length:--c17-text-link) text-ink">
          {{ editing === 'mobile' ? $t('auth.common.label.phoneNumber') : $t('auth.common.label.email') }}:
          {{ editing === 'mobile' ? mobileDisplay : email }}
        </p>
        <div class="flex items-start gap-(--c17-gap-2)">
          <UiTextField
            v-model="code"
            class="min-w-0 flex-1"
            inputmode="numeric"
            :maxlength="6"
            :placeholder="$t('auth.otp.placeholder.code')"
          />
          <MemberSendCodeButton
            :payload="payload"
            flush
          />
        </div>
        <UiButton
          type="submit"
          :disabled="submitting || normalizeCode(code).length !== 6"
        >
          {{ $t('member.common.action.finish') }}
        </UiButton>
        <MemberContactCs />
      </form>
    </UiModal>
  </div>
</template>
