<script setup lang="ts">
/** C17 實名認證申請。表單與送出在 useRealnameForm。 */
const {
  loading,
  form,
  submitting,
  name,
  bankId,
  bankName,
  bankAccount,
  bsbCode,
  birthday,
  idNumber,
  enableBsb,
  bsbAlias,
  canSubmit,
  onPhoto,
  submit,
} = useRealnameForm()

/** 骨架的出現時機由 useSkeleton 決定，不可以直接綁 loading（見該 composable 的說明） */
const showSkeleton = useSkeleton(() => loading.value)
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('member.realname.title.verification')"
      fallback="/member/setting"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <UiSkeleton
        v-if="showSkeleton"
        :rows="5"
      />

      <form
        v-else
        class="flex flex-col gap-(--c17-gap-3) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]"
        @submit.prevent="submit"
      >
        <UiTextField
          v-model="name"
          :label="$t('member.realname.label.realName')"
          :maxlength="60"
        />
        <p class="text-(length:--c17-text-label) text-muted">
          {{ $t('validation.realname.hint.lengthRange') }}
        </p>

        <template v-if="form.bank.show">
          <UiTextField
            v-model="bankId"
            :label="$t('common.bank.label.bankCode')"
            inputmode="numeric"
            :required="form.bank.required"
          />
          <UiTextField
            v-model="bankName"
            :label="$t('common.bank.label.bankName')"
            :maxlength="100"
            :required="form.bank.required"
          />
          <UiTextField
            v-if="enableBsb"
            v-model="bsbCode"
            :label="bsbAlias"
          />
          <UiTextField
            v-model="bankAccount"
            :label="$t('common.bank.label.bankNumber')"
            :maxlength="100"
            :required="form.bank.required"
          />
          <p class="text-(length:--c17-text-label) text-muted">
            {{ $t('withdrawal.bankAccount.hint.cannotModifyAfterBind') }}
          </p>
        </template>

        <UiTextField
          v-if="form.birthday.show"
          v-model="birthday"
          :label="$t('member.profile.label.birthday')"
          type="date"
          :required="form.birthday.required"
        />

        <UiTextField
          v-if="form.id_number.show"
          v-model="idNumber"
          :label="$t('member.realname.label.idNumber')"
          :maxlength="100"
          :required="form.id_number.required"
        />

        <template v-if="form.id_photo.show">
          <label class="flex flex-col gap-(--c17-gap-1)">
            <span class="font-semibold text-(length:--c17-text-link) text-ink">{{ $t('member.realname.label.idFront') }}</span>
            <input
              type="file"
              accept="image/*"
              class="w-full rounded-(--c17-field-radius) border border-field-line bg-field p-(--c17-gap-2) text-(length:--c17-text-label) text-muted file:mr-(--c17-gap-2) file:rounded-(--c17-field-radius) file:border-0 file:bg-accent file:px-(--c17-gap-3) file:py-(--c17-gap-1) file:font-bold file:text-inverse"
              @change="onPhoto($event, 'front')"
            >
          </label>
          <label class="flex flex-col gap-(--c17-gap-1)">
            <span class="font-semibold text-(length:--c17-text-link) text-ink">{{ $t('member.realname.label.idBack') }}</span>
            <input
              type="file"
              accept="image/*"
              class="w-full rounded-(--c17-field-radius) border border-field-line bg-field p-(--c17-gap-2) text-(length:--c17-text-label) text-muted file:mr-(--c17-gap-2) file:rounded-(--c17-field-radius) file:border-0 file:bg-accent file:px-(--c17-gap-3) file:py-(--c17-gap-1) file:font-bold file:text-inverse"
              @change="onPhoto($event, 'back')"
            >
          </label>
        </template>

        <UiButton
          type="submit"
          :disabled="!canSubmit || submitting"
        >
          {{ $t('common.ui.action.submit') }}
        </UiButton>
      </form>
    </div>
  </div>
</template>
