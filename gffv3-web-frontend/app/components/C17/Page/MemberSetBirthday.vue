<script setup lang="ts">
/** C17 設定生日。流程在 useSetBirthday。 */
const {
  member,
  birthday,
  submitting,
  alreadySet,
  maxDate,
  valid,
  submit,
} = useSetBirthday()
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('member.profile.action.setBirthday')"
      fallback="/member/setting"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <div
        v-if="alreadySet"
        class="flex items-center justify-between gap-(--c17-gap-2) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) text-(length:--c17-text-link) shadow-[var(--c17-shadow-panel)]"
      >
        <span class="text-muted">{{ $t('member.profile.label.birthday') }}</span>
        <span class="font-bold text-ink">{{ member.profile.value?.birthday }}</span>
      </div>

      <form
        v-else
        class="flex flex-col gap-(--c17-gap-3) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]"
        @submit.prevent="submit"
      >
        <UiTextField
          v-model="birthday"
          :label="$t('member.profile.label.birthday')"
          type="date"
          :max="maxDate"
          :error="birthday && !valid ? $t('validation.birthday.error.mustBeAdult') : ''"
          show-error
        />
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
