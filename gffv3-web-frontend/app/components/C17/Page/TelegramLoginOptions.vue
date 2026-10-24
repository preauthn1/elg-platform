<script setup lang="ts">
/** C17 的畫面，邏輯在 useTelegramLoginOptionsPage。 */
const {
  brand,
  noBot,
  loading,
  needInviteCode,
  inviteCode,
  pendingUser,
  loginWithUser,
  openWidget,
  openBot,
} = useTelegramLoginOptionsPage()
</script>

<template>
  <div class="mx-auto max-w-sm space-y-4">
    <h1 class="text-xl font-semibold">
      {{ $t('auth.telegram.action.login') }}
    </h1>

    <p
      v-if="noBot"
      class="text-sm text-muted"
    >
      此站台尚未設定 Telegram bot。
    </p>

    <div
      v-else-if="brand"
      class="space-y-3 rounded border border-line bg-surface p-4"
    >
      <button
        type="button"
        class="w-full rounded bg-accent px-4 py-2 text-inverse disabled:opacity-50"
        :disabled="loading"
        @click="openBot"
      >
        {{ $t('auth.telegram.action.loginBot') }}
      </button>
      <button
        type="button"
        class="w-full rounded border border-accent px-4 py-2 text-accent disabled:opacity-50"
        :disabled="loading"
        @click="openWidget"
      >
        {{ $t('auth.telegram.action.loginOfficial') }}
      </button>
    </div>

    <AuthInviteCodePrompt
      v-if="needInviteCode"
      v-model="inviteCode"
      :loading="loading"
      @submit="pendingUser && loginWithUser(pendingUser)"
    />

    <NuxtLink
      to="/login"
      class="text-sm text-accent underline"
    >
      {{ $t('auth.login.action.login') }}
    </NuxtLink>
  </div>
</template>
