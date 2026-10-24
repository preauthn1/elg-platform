<script setup lang="ts">
/** C17 修改登入密碼。流程在 useChangePasswordForm。 */
const {
  password,
  newPassword,
  confirmation,
  submitting,
  success,
  canSubmit,
  submit,
  logout,
} = useChangePasswordForm()

const reveal = ref(false)
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('member.password.title.changePassword')"
      fallback="/member/setting"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <form
        class="flex flex-col gap-(--c17-gap-3) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]"
        @submit.prevent="submit"
      >
        <UiTextField
          v-model="password"
          :label="$t('member.password.label.oldPassword')"
          :type="reveal ? 'text' : 'password'"
          :maxlength="12"
          autocomplete="current-password"
        >
          <template #suffix>
            <button
              type="button"
              class="flex items-center"
              :aria-label="$t('auth.login.label.originalPassword')"
              @click="reveal = !reveal"
            >
              <UiFieldIcon :name="reveal ? 'eye-show' : 'eye-hide'" />
            </button>
          </template>
        </UiTextField>
        <UiTextField
          v-model="newPassword"
          :label="$t('member.password.label.newPassword')"
          :type="reveal ? 'text' : 'password'"
          :maxlength="12"
          autocomplete="new-password"
          :hint="$t('validation.password.hint.length', { min: 6, max: 12 })"
        >
          <template #suffix>
            <button
              type="button"
              class="flex items-center"
              :aria-label="$t('auth.login.label.originalPassword')"
              @click="reveal = !reveal"
            >
              <UiFieldIcon :name="reveal ? 'eye-show' : 'eye-hide'" />
            </button>
          </template>
        </UiTextField>
        <UiTextField
          v-model="confirmation"
          :label="$t('auth.common.label.confirmPassword')"
          :type="reveal ? 'text' : 'password'"
          :maxlength="12"
          autocomplete="new-password"
          :error="confirmation && newPassword !== confirmation ? $t('validation.password.error.mismatch') : ''"
          show-error
        >
          <template #suffix>
            <button
              type="button"
              class="flex items-center"
              :aria-label="$t('auth.login.label.originalPassword')"
              @click="reveal = !reveal"
            >
              <UiFieldIcon :name="reveal ? 'eye-show' : 'eye-hide'" />
            </button>
          </template>
        </UiTextField>

        <UiButton
          type="submit"
          :disabled="!canSubmit || submitting"
        >
          {{ $t('common.ui.action.determine') }}
        </UiButton>
      </form>
    </div>

    <UiModal
      v-if="success"
      :dismissible="false"
      @close="logout"
    >
      <div class="flex flex-col gap-(--c17-gap-3)">
        <p class="text-center text-(length:--c17-text-link) text-ink">
          {{ $t('member.password.msg.updated') }}
        </p>
        <UiButton @click="logout">
          {{ $t('common.ui.action.determine') }}
        </UiButton>
      </div>
    </UiModal>
  </div>
</template>
