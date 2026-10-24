<script setup lang="ts">
/** C17 的畫面，邏輯在 useAgentApplyShareholderPage。 */
const {
  form,
  submitted,
  formErrorText,
  submit,
  back,
} = useAgentApplyShareholderPage()
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-xl font-semibold">
      {{ $t('agent.shareholder.action.apply') }}
    </h1>

    <form
      v-if="!submitted"
      class="space-y-3 rounded border border-line bg-surface p-4 text-sm"
      @submit.prevent="submit"
    >
      <label class="block">
        <span class="text-muted">{{ $t('agent.shareholder.label.account') }}</span>
        <input
          v-model="form.username"
          type="text"
          maxlength="20"
          autocomplete="username"
          class="mt-1 w-full rounded border border-line px-3 py-1"
          :placeholder="$t('agent.shareholder.label.account')"
        >
      </label>
      <label class="block">
        <span class="text-muted">{{ $t('auth.common.label.password') }}</span>
        <input
          v-model="form.password"
          type="password"
          maxlength="15"
          autocomplete="new-password"
          class="mt-1 w-full rounded border border-line px-3 py-1"
          :placeholder="$t('auth.common.label.password')"
        >
      </label>
      <label class="block">
        <span class="text-muted">{{ $t('auth.common.label.confirmPassword') }}</span>
        <input
          v-model="form.password_confirmation"
          type="password"
          maxlength="15"
          autocomplete="new-password"
          class="mt-1 w-full rounded border border-line px-3 py-1"
          :placeholder="$t('auth.common.label.confirmPassword')"
        >
      </label>
      <p
        v-if="formErrorText"
        class="text-danger"
      >
        {{ formErrorText }}
      </p>
      <button
        type="submit"
        class="rounded bg-accent px-4 py-2 text-inverse"
      >
        {{ $t('common.ui.action.determine') }}
      </button>
    </form>

    <section
      v-else
      class="space-y-3 rounded border border-line bg-surface p-4 text-sm"
    >
      <button
        type="button"
        class="rounded border border-line px-4 py-2"
        @click="back"
      >
        {{ $t('common.ui.action.confirm') }}
      </button>
    </section>
  </div>
</template>
