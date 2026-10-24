<script setup lang="ts">
import { currencyImageUrl } from '#shared/utils/currency'
import { isBsbBank, showAccountNameField } from '#shared/utils/withdrawal'
import type { SelectOption } from '~/components/Ui/SelectField.vue'

/** C17 提款入口。流程在 useWithdrawalEntry，這裡只有下拉選單的選項格式與骨架。 */
const {
  w,
  store,
  redirectTo,
  bindLink,
  loading,
  notice,
  errorMessage,
  releaseConfirming,
  releaseDone,
  submitting,
  fieldErrors,
  currencies,
  types,
  availableBanks,
  contactCs,
  showTimes,
  form,
  selectedFormBank,
  infoFields,
  accountLabel,
  accountNameEditable,
  selectType,
  selectCurrency,
  submitInputBank,
  chooseCard,
  applyRelease,
} = useWithdrawalEntry()

/** 骨架的出現時機由 useSkeleton 決定，不可以直接綁 loading（見該 composable 的說明） */
const showSkeleton = useSkeleton(() => loading.value)

/** 下拉用的選項：這頁的 currencyOptions / typeOptions 是 shared 的取值函式，名字不要撞。 */
const currencySelectOptions = computed<SelectOption[]>(() => currencies.value.map(c => ({ key: c.id, label: c.name, image: currencyImageUrl(c.id) })))

const typeSelectOptions = computed<SelectOption[]>(() => types.value.map(tp => ({ key: tp.id, label: tp.name || String(tp.id) })))

const bankOptions = computed<SelectOption[]>(() => availableBanks.value.map(b => ({ key: b.bank_id, label: b.bank_name })))

const formBankModel = computed({
  get: () => form.bankId as string | number | null,
  set: (value) => {
    form.bankId = value === null ? null : Number(value)
  },
})

function bankInfoOptions(field: { options?: { value: unknown, text: string }[] | null }): SelectOption[] {
  return (field.options ?? []).map(o => ({ key: String(o.value), label: o.text }))
}
</script>

<template>
  <div>
    <C17PageHeader
      :title="redirectTo ? $t('withdrawal.bankAccount.title.bindBankCard') : $t('withdrawal.entry.title.withdrawal')"
      fallback="/member"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <NuxtLink
        to="/record/withdrawal"
        class="self-end text-accent"
        :style="{ fontSize: 'var(--c17-text-link)' }"
      >
        {{ $t('records.tab.nav.withdrawal') }}
      </NuxtLink>

      <UiSkeleton
        v-if="showSkeleton"
        :rows="6"
      />
      <p
        v-if="notice"
        class="rounded-(--c17-field-radius) border border-warning bg-warning-soft p-(--c17-gap-3)"
        :style="{ fontSize: 'var(--c17-text-link)' }"
      >
        {{ notice }}
      </p>
      <p
        v-if="errorMessage"
        class="text-sm text-danger"
      >
        {{ errorMessage }}
      </p>

      <template v-if="!loading">
        <section
          v-if="store.flowLimit"
          class="rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3)"
          :style="{ boxShadow: 'var(--c17-shadow-panel)' }"
        >
          <h2
            class="font-bold"
            :style="{ fontSize: 'var(--c17-text-body)', color: 'var(--c17-ink)' }"
          >
            {{ $t('withdrawal.turnover.title.audit') }}
          </h2>
          <dl
            class="mt-2 grid grid-cols-2 gap-x-(--c17-gap-4) gap-y-1"
            :style="{ fontSize: 'var(--c17-text-link)' }"
          >
            <dt class="text-muted">
              {{ $t('withdrawal.turnover.label.required') }}
            </dt>
            <dd class="text-right font-bold tabular-nums">
              {{ store.flowLimit.flow_limit ?? 0 }}
            </dd>
            <dt class="text-muted">
              {{ $t('withdrawal.turnover.label.currentTurnover') }}
            </dt>
            <dd class="text-right font-bold tabular-nums">
              {{ store.flowLimit.reach ?? 0 }}
            </dd>
            <template v-if="w.showRemainingFlow.value">
              <dt class="text-muted">
                {{ $t('withdrawal.turnover.label.remaining') }}
              </dt>
              <dd class="text-right font-bold tabular-nums">
                {{ w.remainingFlow.value }}
              </dd>
            </template>
            <template v-if="showTimes">
              <dt class="text-muted">
                {{ $t('withdrawal.limit.label.count') }}
              </dt>
              <dd class="text-right font-bold tabular-nums">
                {{ store.checkInfo?.withdrawal_times }} / {{ store.checkInfo?.withdrawal_times_limit }}
              </dd>
            </template>
          </dl>
          <p
            v-if="store.flowLimit.pass !== 1 && store.flowLimit.message"
            class="mt-2 text-sm text-danger"
          >
            {{ store.flowLimit.message }}
          </p>
          <p
            v-else-if="!w.canWithdraw.value"
            class="mt-2 text-sm text-danger"
          >
            {{ $t('withdrawal.turnover.error.requirementUnmet') }}
          </p>

          <div
            v-if="w.releaseState.value !== 'hidden'"
            class="mt-(--c17-gap-3)"
          >
            <UiButton
              variant="secondary"
              :disabled="w.releaseState.value === 'pending' || releaseDone"
              @click="releaseConfirming = true"
            >
              {{ $t('withdrawal.turnover.action.applyClear') }}
            </UiButton>
            <p
              v-if="w.releaseState.value === 'pending'"
              class="mt-1 text-muted"
              :style="{ fontSize: 'var(--c17-text-label)' }"
            >
              {{ $t('common.misc.label.pendingApproval') }}
            </p>
          </div>
        </section>

        <section class="flex flex-col gap-(--c17-gap-3)">
          <div>
            <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }">
              {{ $t('common.amount.label.currency') }}
            </p>
            <UiSelectField
              class="mt-1"
              :options="currencySelectOptions"
              :model-value="store.currencyId"
              @update:model-value="selectCurrency(Number($event) || null)"
            />
          </div>
          <div>
            <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }">
              {{ $t('withdrawal.bankAccount.label.method') }}
            </p>
            <UiSelectField
              class="mt-1"
              :options="typeSelectOptions"
              :model-value="store.typeId"
              @update:model-value="selectType(Number($event) || null)"
            />
          </div>
          <p
            v-if="contactCs"
            :style="{ fontSize: 'var(--c17-text-link)' }"
          >
            {{ $t('withdrawal.common.hint.contactSupport') }}
            <NuxtLink
              to="/chat"
              class="ml-1 text-accent"
            >
              {{ $t('site.cs.nav.customerService') }}
            </NuxtLink>
          </p>
          <p
            v-else-if="!types.length"
            class="text-sm text-muted"
          >
            {{ $t('common.ui.empty.noData') }}
          </p>
        </section>

        <section
          v-if="!contactCs && store.checkInfo && store.typeId !== null"
          class="flex flex-col gap-(--c17-gap-3)"
        >
          <template v-if="store.hasBankCardSetting">
            <template v-if="store.boundAccounts.length">
              <h2
                class="font-bold"
                :style="{ fontSize: 'var(--c17-text-body)', color: 'var(--c17-ink)' }"
              >
                {{ $t('auth.login.title.selectAccount') }}
              </h2>
              <ul class="flex flex-col gap-(--c17-gap-2)">
                <li
                  v-for="card in store.boundAccounts"
                  :key="`${card.bank_id}-${card.account_no}`"
                >
                  <button
                    type="button"
                    class="w-full rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) text-left"
                    :style="{ boxShadow: 'var(--c17-shadow-panel)', fontSize: 'var(--c17-text-link)' }"
                    @click="chooseCard(card)"
                  >
                    <span class="font-bold">{{ card.bank_name }}</span>
                    <span class="ml-2 text-muted">{{ card.account_name }}</span>
                    <span class="ml-2 font-mono">{{ card.account_no }}</span>
                  </button>
                </li>
              </ul>
              <NuxtLink
                v-if="store.boundAccounts.length < (store.checkInfo.bank_card_amount ?? 0)"
                :to="bindLink"
                class="text-accent"
                :style="{ fontSize: 'var(--c17-text-link)' }"
              >
                {{ $t('withdrawal.bankAccount.title.bindBankCard') }}
              </NuxtLink>
            </template>
            <template v-else>
              <p :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }">
                {{ $t('withdrawal.bankAccount.empty.noBankCard') }}
              </p>
              <UiButton @click="navigateTo(bindLink)">
                {{ $t('withdrawal.bankAccount.title.bindBankCard') }}
              </UiButton>
            </template>
          </template>

          <form
            v-else
            class="flex flex-col gap-(--c17-gap-3)"
            @submit.prevent="submitInputBank"
          >
            <div>
              <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }">
                {{ $t('withdrawal.bankAccount.label.selectBank') }}
              </p>
              <UiSelectField
                v-model="formBankModel"
                class="mt-1"
                :options="bankOptions"
                :placeholder="$t('common.bank.placeholder.select')"
              />
            </div>

            <template v-if="selectedFormBank">
              <div v-if="showAccountNameField(selectedFormBank.bank_type)">
                <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }">
                  {{ $t('competitions.ranking.label.accountName') }}
                </p>
                <UiTextField
                  v-model="form.accountName"
                  class="mt-1"
                  :maxlength="255"
                  :disabled="!accountNameEditable"
                  :hint="w.realNameVerifiedVisible.value && accountNameEditable ? $t('withdrawal.bankAccount.hint.accountNameOnce') : undefined"
                />
              </div>

              <div v-if="isBsbBank(selectedFormBank.bank_type)">
                <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }">
                  {{ w.bsbCodeAlias.value || 'BSB' }}
                </p>
                <UiTextField
                  v-model="form.bsbCode"
                  class="mt-1"
                />
              </div>

              <div>
                <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }">
                  {{ accountLabel.key ? $t(accountLabel.key) : accountLabel.text }}
                </p>
                <UiTextField
                  v-model="form.accountNo"
                  class="mt-1"
                  :maxlength="255"
                />
              </div>

              <div
                v-for="field in infoFields"
                :key="field.name"
              >
                <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }">
                  {{ field.display || field.name }}
                </p>
                <UiSelectField
                  v-if="field.type === 'select'"
                  v-model="form.bankInfoData[field.name]"
                  class="mt-1"
                  :options="bankInfoOptions(field)"
                />
                <UiTextField
                  v-else
                  v-model="form.bankInfoData[field.name]"
                  class="mt-1"
                  :placeholder="field.placeholder ?? ''"
                  :error="fieldErrors.includes(field.name) ? $t('validation.field.error.format', { value: field.display || field.name }) : undefined"
                  show-error
                />
              </div>
            </template>

            <UiButton
              type="submit"
              class="mt-(--c17-gap-4)"
              :disabled="submitting || !selectedFormBank"
              :loading="submitting"
            >
              {{ $t('common.ui.action.next') }}
            </UiButton>
          </form>
        </section>
      </template>
    </div>

    <UiModal
      v-if="releaseConfirming"
      :title="$t('common.ui.title.prompt')"
      @close="releaseConfirming = false"
    >
      <div class="flex flex-col gap-(--c17-gap-4)">
        <p :style="{ fontSize: 'var(--c17-text-body)', color: 'var(--c17-ink)' }">
          {{ $t('withdrawal.turnover.msg.clearApply') }}
        </p>
        <div class="flex gap-(--c17-gap-3)">
          <UiButton
            variant="secondary"
            @click="releaseConfirming = false"
          >
            {{ $t('common.ui.action.cancel') }}
          </UiButton>
          <UiButton @click="applyRelease">
            {{ $t('common.ui.action.confirm') }}
          </UiButton>
        </div>
      </div>
    </UiModal>

    <EventsPrizeReminder />
  </div>
</template>
