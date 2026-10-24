<script setup lang="ts">
import { MEMBER_PATH } from '#shared/utils/member'
import { currencyImageUrl } from '#shared/utils/currency'
import { computed, onMounted, ref } from 'vue'
import type { BankAccount } from '#shared/api/endpoints/deposit'
import type { SelectOption } from '~/components/Ui/SelectField.vue'
import {
  convertPoints,
  distinctCurrencyIds,
  filterByCurrency,
  isBankAmountValid,
  rateDecimals,
  splitBsbAccountNo,
  splitTopUpTypes,
} from '#shared/utils/deposit'

const { settings, flag } = useSite()
const { paymentTypes, ensureTopUpTypes } = useDeposit()
const { fetchBankAccounts, createBankOrder } = useAutoDeposit()
const currencies = useDepositCurrencies()

const loading = ref(true)
/** 骨架的出現時機由 useSkeleton 決定，不可以直接綁 loading（見該 composable 的說明） */
const showSkeleton = useSkeleton(() => loading.value)
const message = ref<string | null>(null)
const banks = ref<BankAccount[]>([])
const selectedCurrencyId = ref<number | null>(null)
const selectedBankCode = ref<string | null>(null)
const selectedAccount = ref<BankAccount | null>(null)
const amount = ref('')
const submitting = ref(false)
const copiedKey = ref<string | null>(null)

const autoDepositItem = computed(() => splitTopUpTypes(paymentTypes.value).autoDeposit)
const currencyIds = computed(() => distinctCurrencyIds(banks.value))
const banksOfCurrency = computed(() => filterByCurrency(banks.value, selectedCurrencyId.value))
/** 同一 bank_code 只列一次當「銀行」，帳戶在下一層選 */
const bankChoices = computed(() => {
  const seen = new Set<string>()
  return banksOfCurrency.value.filter((b) => {
    const code = b.bank_code ?? ''
    if (seen.has(code)) return false
    seen.add(code)
    return true
  })
})
const accounts = computed(() => banksOfCurrency.value.filter(b => (b.bank_code ?? '') === (selectedBankCode.value ?? '')))
const enableBsb = computed(() => flag('enable_bsb_code'))
const bsbAlias = computed(() => settings.value?.bsb_code_alias || 'BSB')
const textWalletPoint = computed(() => settings.value?.text_wallet_point ?? '')
const accountNo = computed(() => splitBsbAccountNo(selectedAccount.value?.account_no, enableBsb.value))
const rate = computed(() => selectedAccount.value?.rate ?? 1)
const decimals = computed(() => rateDecimals(rate.value, 4))
const points = computed(() => convertPoints(amount.value, rate.value, decimals.value))
const amountValid = computed(() => isBankAmountValid(amount.value, selectedAccount.value))
const canSubmit = computed(() => amountValid.value && !submitting.value)

function selectAccount(a: BankAccount | null) {
  selectedAccount.value = a
}

function selectBank(b: BankAccount | null) {
  selectedBankCode.value = b?.bank_code ?? null
  selectAccount(b)
}

function setCurrency(id: number | null) {
  selectedCurrencyId.value = id
  selectBank(banksOfCurrency.value[0] ?? null)
}

const currencySelectOptions = computed<SelectOption[]>(() =>
  currencyIds.value.map(id => ({ key: id, label: currencies.nameOf(id), image: currencyImageUrl(id) })),
)
/** 銀行選項用 bank_code 當 key（同一家銀行的多個帳戶只列一次）。 */
const bankOptions = computed<SelectOption[]>(() =>
  bankChoices.value.map(b => ({ key: b.bank_code ?? String(b.bank_id), label: b.bank_name, image: b.bank_icon ?? null })),
)
const accountOptions = computed<SelectOption[]>(() =>
  accounts.value.map(a => ({ key: a.bank_id, label: a.account_name })),
)

function selectBankByCode(code: string | number | null) {
  selectBank(bankChoices.value.find(b => (b.bank_code ?? String(b.bank_id)) === String(code)) ?? null)
}

function selectAccountById(id: string | number | null) {
  selectAccount(accounts.value.find(a => a.bank_id === Number(id)) ?? null)
}

async function copyText(key: string, text: string) {
  if (!text || !import.meta.client) return
  try {
    await navigator.clipboard.writeText(text)
    copiedKey.value = key
  }
  catch {
    copiedKey.value = null
  }
}

async function submit() {
  if (!selectedAccount.value || !canSubmit.value) return
  submitting.value = true
  try {
    // 失敗訊息由 useApi 的全站彈窗呈現
    await createBankOrder({ bank_id: selectedAccount.value.bank_id, amount: Number(amount.value) })
  }
  finally {
    submitting.value = false
  }
}

onMounted(async () => {
  await ensureTopUpTypes()
  if (!autoDepositItem.value) {
    await navigateTo(MEMBER_PATH.home, { replace: true })
    return
  }
  if (autoDepositItem.value.PopupText) message.value = autoDepositItem.value.PopupText

  const { res, recovered } = await fetchBankAccounts()
  if (recovered) return
  if (isApiOk(res)) {
    banks.value = res.data.bank
    await currencies.load()
    setCurrency(currencyIds.value[0] ?? null)
  }
  loading.value = false
})
</script>

<template>
  <div class="flex flex-col gap-(--c17-gap-3)">
    <UiSkeleton
      v-if="showSkeleton"
      :rows="6"
    />
    <p
      v-else-if="banks.length === 0"
      class="text-sm text-muted"
    >
      {{ $t('common.ui.empty.noData') }}
    </p>
    <template v-else>
      <!-- 幣別、銀行、帳戶三層都走同一顆下拉，與快速充值那個 tab 一致 -->
      <section v-if="currencyIds.length > 0">
        <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }">
          {{ $t('deposit.common.label.currency') }}
        </p>
        <UiSelectField
          class="mt-1"
          :options="currencySelectOptions"
          :model-value="selectedCurrencyId"
          @update:model-value="setCurrency(Number($event) || null)"
        />
      </section>

      <section>
        <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }">
          {{ $t('deposit.bankTransfer.title.bankDeposit') }}
        </p>
        <UiSelectField
          class="mt-1"
          :options="bankOptions"
          :model-value="selectedBankCode"
          @update:model-value="selectBankByCode"
        />
      </section>

      <template v-if="selectedAccount">
        <section>
          <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }">
            {{ $t('common.bank.label.accountName') }}
          </p>
          <UiSelectField
            class="mt-1"
            :options="accountOptions"
            :model-value="selectedAccount.bank_id"
            @update:model-value="selectAccountById"
          />
        </section>

        <!-- 帳號那幾行：右邊是複製鈕，值用等寬字才不會看錯數字 -->
        <section
          class="flex flex-col gap-(--c17-gap-2) rounded-(--c17-field-radius) bg-surface p-(--c17-gap-3)"
          :style="{ boxShadow: 'var(--c17-shadow-panel)', fontSize: 'var(--c17-text-link)' }"
        >
          <div
            v-if="accountNo.bsb !== null"
            class="flex items-center justify-between gap-(--c17-gap-2)"
          >
            <span :style="{ color: 'var(--c17-muted)' }">{{ bsbAlias }}</span>
            <span class="ml-auto truncate font-mono font-bold">{{ accountNo.bsb }}</span>
            <button
              type="button"
              class="shrink-0 font-bold"
              :style="{ color: 'var(--c17-primary)' }"
              @click="copyText('bsb', accountNo.bsb)"
            >
              {{ copiedKey === 'bsb' ? $t('deposit.common.msg.copied') : $t('common.ui.action.copy') }}
            </button>
          </div>
          <div class="flex items-center justify-between gap-(--c17-gap-2)">
            <span :style="{ color: 'var(--c17-muted)' }">{{ $t('common.bank.label.accountNo') }}</span>
            <span class="ml-auto truncate font-mono font-bold">{{ accountNo.account }}</span>
            <button
              type="button"
              class="shrink-0 font-bold"
              :style="{ color: 'var(--c17-primary)' }"
              @click="copyText('account', accountNo.account)"
            >
              {{ copiedKey === 'account' ? $t('deposit.common.msg.copied') : $t('common.ui.action.copy') }}
            </button>
          </div>
          <img
            v-if="selectedAccount.qrcode"
            :src="selectedAccount.qrcode"
            alt=""
            class="mx-auto max-h-48 object-contain"
          >
        </section>

        <DepositAmountPresets
          :min="selectedAccount.min_amount"
          :max="selectedAccount.max_amount"
          @select="v => (amount = String(v))"
        />

        <div>
          <UiTextField
            v-model="amount"
            type="number"
            inputmode="decimal"
            :placeholder="$t('common.amount.placeholder.amount')"
          >
            <template #prefix>
              <img
                src="/skins/c17/action/deposit.webp"
                alt=""
                class="size-[22px] object-contain"
              >
            </template>
          </UiTextField>
          <p
            class="mt-[6px] font-semibold"
            :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }"
          >
            {{ $t('deposit.common.label.depositLimit') }}: {{ selectedAccount.min_amount }} - {{ selectedAccount.max_amount }}
          </p>
        </div>

        <div :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }">
          <div class="flex justify-between gap-2">
            <span>{{ $t('deposit.common.label.youWillReceive') }}</span>
            <span>{{ $t('common.amount.label.exchangeRate') }} 1 = {{ rate }} {{ textWalletPoint }}</span>
          </div>
          <p
            class="font-bold"
            :style="{ fontSize: 'var(--c17-text-title)', color: 'var(--c17-primary)' }"
          >
            {{ points }} {{ textWalletPoint }}
          </p>
        </div>

        <UiButton
          class="mt-(--c17-gap-4)"
          :disabled="!canSubmit"
          :loading="submitting"
          @click="submit"
        >
          {{ $t('common.ui.action.submit') }}
        </UiButton>
      </template>
    </template>

    <DepositMessageBox
      v-if="message"
      :message="message"
      @close="message = null"
    />
  </div>
</template>
