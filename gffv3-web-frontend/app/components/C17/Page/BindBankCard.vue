<script setup lang="ts">
import { accountNoLabel, isBsbBank } from '#shared/utils/withdrawal'

/** C17 綁定提款帳號。流程在 useBindBankCard，這裡只有下拉選單的選項格式與骨架。 */
const { t } = useI18n()
const {
  w,
  loading,
  submitting,
  errorMessage,
  contactCs,
  rows,
  maxRows,
  banks,
  bankOf,
  onBankChange,
  submit,
} = useBindBankCard()

/** 第一項是空值，讓已選的列可以退回「未選」（與原本 select 的空選項相同） */
const bankOptions = computed(() => [
  { key: '', label: t('common.bank.placeholder.select') },
  ...banks.value.map(b => ({ key: b.bank_id, label: b.bank_name })),
])

const showSkeleton = useSkeleton(() => loading.value)
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('withdrawal.bankAccount.title.bindBankCard')"
      fallback="/withdrawal"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <UiSkeleton
        v-if="showSkeleton"
        :rows="6"
      />
      <p
        v-if="errorMessage"
        class="text-danger"
        :style="{ fontSize: 'var(--c17-text-link)' }"
      >
        {{ errorMessage }}
      </p>
      <p
        v-if="contactCs"
        :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
      >
        {{ $t('withdrawal.common.hint.contactSupport') }}
        <NuxtLink
          to="/chat"
          class="ml-1 font-bold text-accent"
        >
          {{ $t('site.cs.nav.customerService') }}
        </NuxtLink>
      </p>

      <form
        v-if="!loading"
        class="flex flex-col gap-(--c17-gap-3)"
        @submit.prevent="submit"
      >
        <p
          class="text-muted"
          :style="{ fontSize: 'var(--c17-text-label)' }"
        >
          {{ $t('withdrawal.bankAccount.hint.cannotModifyAfterBind') }}
        </p>
        <section
          v-for="(row, index) in rows"
          :key="index"
          class="flex flex-col gap-(--c17-gap-3) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3)"
          :style="{ boxShadow: 'var(--c17-shadow-panel)' }"
        >
          <p
            class="font-bold"
            :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }"
          >
            {{ index + 1 }} / {{ maxRows }}
          </p>
          <UiSelectField
            :label="$t('withdrawal.bankAccount.label.selectBank')"
            :options="bankOptions"
            :placeholder="$t('common.bank.placeholder.select')"
            :model-value="row.bank_id ?? ''"
            @update:model-value="v => { row.bank_id = Number(v) || null; onBankChange(row) }"
          />
          <UiTextField
            v-model="row.account_name"
            :label="$t('competitions.ranking.label.accountName')"
            :maxlength="255"
          />
          <UiTextField
            v-if="isBsbBank(bankOf(row)?.bank_type)"
            v-model="row.bsb_code"
            :label="w.bsbCodeAlias.value || 'BSB'"
          />
          <UiTextField
            v-model="row.account_no"
            :label="accountNoLabel(bankOf(row)).key ? $t(accountNoLabel(bankOf(row)).key!) : accountNoLabel(bankOf(row)).text"
            :maxlength="255"
          />
        </section>

        <UiButton
          type="submit"
          class="mt-(--c17-gap-4)"
          :loading="submitting"
        >
          {{ $t('common.ui.action.submit') }}
        </UiButton>
      </form>
    </div>
  </div>
</template>
