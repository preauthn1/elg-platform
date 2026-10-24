<script setup lang="ts">
/**
 * 重設新密碼（guest/reset-password）：帳號與驗證碼來自前一步，成功回 /login 顯示提示。
 *
 * 設計稿 `login-reset-pw-mobile` / `-email` 把「驗證」與「設新密碼」畫在**同一張卡**上；
 * 我們拆成兩步（forget-password → 這頁），但外觀要是同一張卡的延續，所以一樣用 UiModal。
 *
 * 兩個密碼欄照設計稿：
 *   [36,247 318×32] / [36,291 318×32]
 *   前置圖示 22×22 都是 "confirm Password"（uid 2d6e7c98）——設計稿兩格都用這顆
 *   後置 22×22 "Eye hide" / "Eye Unhide" 切換明碼
 */
const {
  store,
  form,
  loading,
  mismatch,
  shown,
  canSubmit,
  submit,
} = useSetPasswordPage()
</script>

<template>
  <UiModal
    fill
    :title="$t('auth.password.label.newPassword')"

    @close="navigateTo('/login')"
  >
    <form
      class="space-y-(--c17-gap-3)"
      @submit.prevent="submit"
    >
      <p
        v-if="store.resetContext?.username"
        class="text-(length:--c17-text-label) text-muted"
      >
        {{ $t('auth.common.label.username') }}: {{ store.resetContext.username }}
      </p>

      <UiTextField
        v-model="form.new_password"
        :type="shown.pw ? 'text' : 'password'"
        :placeholder="$t('validation.password.placeholder.charsetRange', { min: 6, max: 12 })"
        autocomplete="new-password"
        :maxlength="12"
        required
      >
        <template #prefix>
          <UiFieldIcon name="password-confirm" />
        </template>
        <template #suffix>
          <button
            type="button"
            class="flex items-center"
            :aria-label="$t('auth.login.label.originalPassword')"
            @click="shown.pw = !shown.pw"
          >
            <UiFieldIcon :name="shown.pw ? 'eye-show' : 'eye-hide'" />
          </button>
        </template>
      </UiTextField>

      <UiTextField
        v-model="form.new_password_confirmation"
        :type="shown.confirm ? 'text' : 'password'"
        :placeholder="$t('auth.common.label.confirmPassword')"
        autocomplete="new-password"
        :maxlength="12"
        required
      >
        <template #prefix>
          <UiFieldIcon name="password-confirm" />
        </template>
        <template #suffix>
          <button
            type="button"
            class="flex items-center"
            :aria-label="$t('auth.login.label.originalPassword')"
            @click="shown.confirm = !shown.confirm"
          >
            <UiFieldIcon :name="shown.confirm ? 'eye-show' : 'eye-hide'" />
          </button>
        </template>
      </UiTextField>

      <p
        v-if="mismatch"
        class="text-sm text-danger"
      >
        {{ $t('validation.password.error.mismatch') }}
      </p>

      <UiButton
        type="submit"
        :disabled="!canSubmit"
        :loading="loading"
      >
        {{ $t('auth.password.action.set') }}
      </UiButton>
    </form>
  </UiModal>
</template>
