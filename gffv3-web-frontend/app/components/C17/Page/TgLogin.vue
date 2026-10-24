<script setup lang="ts">
/** C17 的畫面，邏輯在 useTgLoginPage。 */
const {
  loading,
  message,
  needInviteCode,
  inviteCode,
  missing,
  goRedirect,
  submit,
} = useTgLoginPage()
</script>

<template>
  <div class="mx-auto max-w-sm space-y-4">
    <h1 class="text-xl font-semibold">
      {{ $t('auth.telegram.action.loginBot') }}
    </h1>
    <p
      v-if="loading"
      class="text-sm text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>

    <AuthCompleteProfileForm
      v-if="missing.length"
      :missing="missing"
      @done="goRedirect"
    />

    <AuthInviteCodePrompt
      v-else-if="needInviteCode"
      v-model="inviteCode"
      :loading="loading"
      :error="message"
      @submit="submit"
    />
    <p
      v-else-if="message"
      class="text-sm text-danger"
    >
      {{ message }}
    </p>
  </div>
</template>
