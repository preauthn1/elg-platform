<script setup lang="ts">
/** C17 修改暱稱。流程在 useNicknameForm。 */
const {
  nickname,
  submitting,
  submit,
} = useNicknameForm()
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-xl font-semibold">
      {{ $t('member.profile.action.setNickname') }}
    </h1>
    <form
      class="space-y-3 rounded border border-line bg-surface p-4"
      @submit.prevent="submit"
    >
      <label class="block text-sm">
        {{ $t('member.profile.label.nickname') }}
        <input
          v-model="nickname"
          type="text"
          maxlength="30"
          class="mt-1 w-full rounded border border-line px-2 py-1"
          :placeholder="$t('member.profile.placeholder.nickname')"
        >
      </label>
      <button
        type="submit"
        class="rounded bg-accent px-4 py-2 text-sm text-inverse disabled:opacity-50"
        :disabled="submitting || !nickname.trim()"
      >
        {{ $t('common.ui.action.submit') }}
      </button>
    </form>
  </div>
</template>
