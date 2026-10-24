<script setup lang="ts">
const props = withDefaults(defineProps<{
  loading?: boolean
  error?: string | null
}>(), { loading: false, error: null })

const code = defineModel<string>({ default: '' })

const emit = defineEmits<{
  submit: [code: string]
}>()

function submit() {
  if (props.loading) return
  emit('submit', code.value.trim())
}
</script>

<template>
  <form
    class="space-y-2 rounded border border-line bg-surface p-4"
    @submit.prevent="submit"
  >
    <label class="block text-sm">
      {{ $t('member.referralCode.label.code') }}
      <input
        v-model="code"
        type="text"
        class="mt-1 w-full rounded border border-line px-2 py-1"
        :placeholder="$t('auth.signup.placeholder.referralCode')"
        autocomplete="off"
      >
    </label>
    <p
      v-if="error"
      class="text-sm text-danger"
    >
      {{ error }}
    </p>
    <button
      type="submit"
      class="rounded bg-accent px-4 py-1 text-inverse disabled:opacity-50"
      :disabled="loading || !code.trim()"
    >
      {{ $t('common.ui.action.submit') }}
    </button>
  </form>
</template>
