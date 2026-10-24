<script setup lang="ts">
/** C17 設定真實姓名。流程在 useSetFullname。 */
const {
  member,
  fullname,
  submitting,
  alreadySet,
  valid,
  submit,
} = useSetFullname()
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('member.realname.action.addRealName')"
      fallback="/member/setting"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <p class="text-(length:--c17-text-link) text-muted">
        {{ $t('member.realname.hint.matchBankAccount') }}
      </p>

      <div
        v-if="alreadySet"
        class="flex items-center justify-between gap-(--c17-gap-2) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) text-(length:--c17-text-link) shadow-[var(--c17-shadow-panel)]"
      >
        <span class="text-muted">{{ $t('member.realname.label.realName') }}</span>
        <span class="font-bold text-ink">{{ member.profile.value?.first_name }}</span>
      </div>

      <form
        v-else
        class="flex flex-col gap-(--c17-gap-3) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]"
        @submit.prevent="submit"
      >
        <UiTextField
          v-model="fullname"
          :label="$t('member.realname.label.realName')"
          :maxlength="60"
        />
        <p class="text-(length:--c17-text-label) text-muted">
          {{ $t('validation.realname.hint.lengthRange') }}
        </p>
        <UiButton
          type="submit"
          :disabled="!valid || submitting"
        >
          {{ $t('common.ui.action.submit') }}
        </UiButton>
      </form>
    </div>
  </div>
</template>
