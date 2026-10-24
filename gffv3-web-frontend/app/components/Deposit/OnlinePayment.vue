<script setup lang="ts">
import { MEMBER_PATH } from '#shared/utils/member'
import { currencyImageUrl } from '#shared/utils/currency'
import { computed, onMounted, ref } from 'vue'
import type { ChildOrderType, OrderType, OrderTypeField, OrderTypeFieldOption } from '#shared/api/endpoints/deposit'
import type { SelectOption } from '~/components/Ui/SelectField.vue'
import {
  buildCreateOrderBody,
  buildInitialModel,
  convertPoints,
  distinctCurrencyIds,
  filterByCurrency,
  isFieldRequired,
  isSelectField,
  rateDecimals,
  resolveDepositLimits,
  resolveFieldDefs,
  splitTopUpTypes,
  validateFields,
  type DepositOutcome,
  type FieldModel,
} from '#shared/utils/deposit'

/**
 * 快速存款表單（設計稿 Wallet-page）。由上而下：
 *   幣別下拉 → 支付方式下拉 → （子渠道下拉）→ 其他動態欄位
 *   → 金額快選四顆 → 金額輸入（22×22 錢包圖示）→ Amount Limit → 注意事項 → DEPOSIT
 *
 * 欄位定義、限額與送出後的分流都由後端下發，這裡只負責呈現。
 */
const props = defineProps<{ preselectOrderTypeId?: number }>()
const emit = defineEmits<{ return: [] }>()

const { t } = useI18n()
const router = useRouter()
const { settings } = useSite()
const { paymentTypes, submitting, ensureTopUpTypes, fetchTopUpChannels, submitDeposit, openInNewWindow } = useDeposit()
const currencies = useDepositCurrencies()

const loading = ref(true)
/** 骨架的出現時機由 useSkeleton 決定，不可以直接綁 loading（見該 composable 的說明） */
const showSkeleton = useSkeleton(() => loading.value)
const message = ref<string | null>(null)
/** open-window* 要等使用者關掉提示才做下一步 */
const pendingOutcome = ref<DepositOutcome | null>(null)
const confirmOpen = ref(false)
const copied = ref(false)

const onlineItems = computed(() => splitTopUpTypes(paymentTypes.value).online)
const currencyIds = computed(() => distinctCurrencyIds(onlineItems.value))
const selectedCurrencyId = ref<number | null>(null)
const payments = computed(() => filterByCurrency(onlineItems.value, selectedCurrencyId.value))

const payment = ref<OrderType | null>(null)
const children = ref<ChildOrderType[]>([])
const child = ref<ChildOrderType | null>(null)
const model = ref<FieldModel>({})

const fields = computed(() => resolveFieldDefs(payment.value, child.value))
/** 金額欄位在設計稿上是獨立的一段（快選 + 輸入 + 限額），與其他動態欄位分開排。 */
const amountField = computed(() => fields.value.find(f => f.name === 'amount') ?? null)
const otherFields = computed(() => fields.value.filter(f => f.name !== 'amount'))
const limits = computed(() => resolveDepositLimits(payment.value, child.value))
const rate = computed(() => payment.value?.rate ?? 1)
const decimals = computed(() => rateDecimals(rate.value, 2))
const points = computed(() => convertPoints(model.value.amount, rate.value, decimals.value))
const validation = computed(() => validateFields(fields.value, model.value, limits.value))
const isCrypto = computed(() => Boolean(payment.value?.crypto_wallet_address))
const canSubmit = computed(() => payment.value !== null && validation.value.ok && !submitting.value)
const textWalletPoint = computed(() => settings.value?.text_wallet_point ?? '')

const currencyOptions = computed<SelectOption[]>(() =>
  currencyIds.value.map(id => ({ key: id, label: currencies.nameOf(id), image: currencyImageUrl(id) })),
)
const paymentOptions = computed<SelectOption[]>(() =>
  payments.value.map(p => ({ key: p.order_type_id, label: p.name, image: p.image_path || null })),
)
const childOptions = computed<SelectOption[]>(() =>
  children.value.map(c => ({ key: c.id, label: c.name, image: c.image ?? null })),
)

function rebuildModel() {
  const next = buildInitialModel(fields.value)
  for (const key of Object.keys(next)) {
    const prev = model.value[key]
    if (prev !== undefined) next[key] = prev
  }
  model.value = next
}

async function selectPayment(p: OrderType | null) {
  payment.value = p
  child.value = null
  children.value = []
  model.value = {}
  if (!p) return
  if (p.PopupText) message.value = p.PopupText
  if (p.choose_bank === 1) {
    const res = await fetchTopUpChannels(p.order_type_id)
    children.value = isApiOk(res) ? res.data : []
    child.value = children.value[0] ?? null
  }
  model.value = buildInitialModel(fields.value)
}

function selectChild(c: ChildOrderType) {
  child.value = c
  rebuildModel()
}

function setCurrency(id: number | null) {
  selectedCurrencyId.value = id
  void selectPayment(payments.value[0] ?? null)
}

const currencyModel = computed({
  get: () => selectedCurrencyId.value as string | number | null,
  set: value => setCurrency(value === null ? null : Number(value)),
})

const paymentModel = computed({
  get: () => (payment.value?.order_type_id ?? null) as string | number | null,
  set: (value) => {
    void selectPayment(payments.value.find(p => p.order_type_id === Number(value)) ?? null)
  },
})

const childModel = computed({
  get: () => (child.value?.id ?? null) as string | number | null,
  set: (value) => {
    const found = children.value.find(c => c.id === Number(value))
    if (found) selectChild(found)
  },
})

function setValue(name: string, value: string) {
  model.value = { ...model.value, [name]: value }
}

function setAmount(value: number) {
  setValue('amount', String(value))
}

function textValue(name: string): string {
  const v = model.value[name]
  return typeof v === 'string' ? v : ''
}

function optionLabel(value: unknown): string {
  const o = value as OrderTypeFieldOption | null | undefined
  return o?.value === null || o?.value === undefined ? '' : String(o.value)
}

/** display 後端有 boolean／數字／字串三種寫法，當標題用之前先收成字串。 */
function fieldLabel(f: OrderTypeField): string {
  const display = typeof f.display === 'string' ? f.display : ''
  return display || f.name
}

/** 動態 select 的選項用索引當 key，因為 model 存的是整個 option 物件。 */
function fieldOptions(f: OrderTypeField): SelectOption[] {
  return (f.option ?? []).map((o, i) => ({ key: i, label: optionLabel(o) }))
}

function selectedOptionIndex(f: OrderTypeField): number | null {
  const at = (f.option ?? []).findIndex(o => o === model.value[f.name])
  return at >= 0 ? at : null
}

function onSelectIndex(f: OrderTypeField, index: string | number | null) {
  const opt = f.option?.[Number(index)]
  if (opt) model.value = { ...model.value, [f.name]: opt }
}

async function confirmSubmit() {
  confirmOpen.value = false
  if (!payment.value || !canSubmit.value) return
  const body = buildCreateOrderBody(payment.value, child.value, fields.value, model.value)
  const outcome = await submitDeposit(body, { cf_id: payment.value.cf_id, order_type_id: payment.value.order_type_id })
  const alert = useAlert()
  switch (outcome.kind) {
    case 'error':
      // 非 200 的錯誤 useApi 已經彈過（帶 tid），這裡只補 200 但 data.type 為錯誤的情況
      if (!alert.state.value) alert.show(outcome.message, 'error')
      break
    case 'stay':
      // 998 不是 200，訊息已由 useApi 的全站彈窗呈現
      break
    case 'return':
      // 999 的訊息已由 useApi 的全站彈窗呈現，直接回到付款方式選擇
      emit('return')
      await selectPayment(payments.value[0] ?? null)
      break
    case 'open-window':
    case 'open-window-html':
      // 開新視窗必須由使用者點擊觸發（否則會被瀏覽器擋），所以保留本地確認框
      pendingOutcome.value = outcome
      message.value = t('games.launch.hint.newWindow')
      break
    case 'success':
      alert.show(t('common.ui.msg.success'), 'success', { redirect: MEMBER_PATH.home })
      break
    default:
      break
  }
}

function closeMessage() {
  message.value = null
  const outcome = pendingOutcome.value
  pendingOutcome.value = null
  if (!outcome) return
  if (outcome.kind === 'open-window' || outcome.kind === 'open-window-html') {
    openInNewWindow(outcome)
    router.back()
  }
}

async function copyAddress() {
  const address = payment.value?.crypto_wallet_address
  if (!address || !import.meta.client) return
  try {
    await navigator.clipboard.writeText(address)
    copied.value = true
  }
  catch {
    copied.value = false
  }
}

onMounted(async () => {
  await ensureTopUpTypes()
  await currencies.load()
  const preselected = props.preselectOrderTypeId
    ? onlineItems.value.find(i => i.order_type_id === props.preselectOrderTypeId) ?? null
    : null
  if (preselected) {
    selectedCurrencyId.value = preselected.currency_id !== 0 ? preselected.currency_id : (currencyIds.value[0] ?? null)
    await selectPayment(preselected)
  }
  else {
    selectedCurrencyId.value = currencyIds.value[0] ?? null
    await selectPayment(payments.value[0] ?? null)
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
      v-else-if="onlineItems.length === 0"
      class="text-sm text-muted"
    >
      {{ $t('common.ui.empty.noData') }}
    </p>

    <template v-else>
      <UiSelectField
        v-if="currencyOptions.length > 0"
        v-model="currencyModel"
        :options="currencyOptions"
        :placeholder="$t('deposit.common.label.currency')"
      />

      <UiSelectField
        v-model="paymentModel"
        :options="paymentOptions"
        :placeholder="$t('deposit.entry.title.paymentMethod')"
      />

      <template v-if="payment">
        <UiSelectField
          v-if="payment.choose_bank === 1"
          v-model="childModel"
          :options="childOptions"
          :placeholder="$t('deposit.entry.title.paymentChannel')"
        />

        <!-- 金額以外的動態欄位：select 走同一顆下拉，其餘走共用輸入框 -->
        <template
          v-for="f in otherFields"
          :key="f.name"
        >
          <UiSelectField
            v-if="isSelectField(f)"
            :options="fieldOptions(f)"
            :model-value="selectedOptionIndex(f)"
            :placeholder="fieldLabel(f)"
            @update:model-value="onSelectIndex(f, $event)"
          />
          <UiTextField
            v-else
            :model-value="textValue(f.name)"
            :placeholder="f.placeholder || fieldLabel(f)"
            :required-mark="isFieldRequired(f)"
            @update:model-value="setValue(f.name, $event)"
          />
        </template>

        <template v-if="amountField">
          <DepositAmountPresets
            :min="limits.min"
            :max="limits.max"
            @select="setAmount"
          />

          <div>
            <UiTextField
              :model-value="textValue('amount')"
              type="number"
              inputmode="decimal"
              :placeholder="amountField.placeholder || $t('deposit.common.label.depositAmount')"
              @update:model-value="setValue('amount', $event)"
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
              {{ $t('deposit.common.label.depositLimit') }}: {{ limits.min }} - {{ limits.max || $t('deposit.ewallet.label.unlimited') }}
            </p>
          </div>

          <div
            v-if="rate !== 1"
            :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
          >
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
        </template>

        <section
          v-if="isCrypto"
          class="rounded-(--c17-field-radius) border border-line bg-surface p-3"
          :style="{ fontSize: 'var(--c17-text-link)' }"
        >
          <h3 class="font-bold">
            {{ $t('common.bank.label.walletAddress') }}
          </h3>
          <p class="break-all font-mono">
            {{ payment.crypto_wallet_address }}
          </p>
          <UiButton
            class="mt-2"
            variant="secondary"
            :block="false"
            @click="copyAddress"
          >
            {{ copied ? $t('deposit.common.msg.copied') : $t('common.ui.action.copy') }}
          </UiButton>
        </section>

        <!-- 注意事項：設計稿是一段 PJS SemiBold 10 的編號文字，不是項目符號清單 -->
        <ol
          class="mt-(--c17-gap-5) font-semibold"
          :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)', lineHeight: '1.4' }"
        >
          <li>1. {{ $t('deposit.common.hint.waitResult') }}</li>
          <li>2. {{ $t('deposit.ewallet.hint.pointsReturn') }}</li>
          <li>3. {{ $t('deposit.ewallet.hint.followAmount') }}</li>
          <li>4. {{ $t('deposit.ewallet.hint.scanNewQrCode') }}</li>
        </ol>

        <UiButton
          v-if="!isCrypto"
          class="mt-(--c17-gap-4)"
          :disabled="!canSubmit"
          :loading="submitting"
          @click="confirmOpen = true"
        >
          {{ $t('deposit.entry.action.deposit') }}
        </UiButton>
      </template>
    </template>

    <UiModal
      v-if="confirmOpen && payment"
      :title="$t('deposit.receipt.title.confirmDepositInfo')"
      @close="confirmOpen = false"
    >
      <div class="flex flex-col gap-(--c17-gap-4)">
        <dl
          class="flex flex-col gap-1"
          :style="{ fontSize: 'var(--c17-text-link)' }"
        >
          <div class="flex justify-between gap-4">
            <dt>{{ $t('deposit.entry.label.paymentWay') }}</dt>
            <dd class="font-bold">
              {{ payment.name }}
            </dd>
          </div>
          <div
            v-if="payment.choose_bank === 1 && child"
            class="flex justify-between gap-4"
          >
            <dt>{{ $t('deposit.entry.label.paymentChannel') }}</dt>
            <dd class="font-bold">
              {{ child.name }}
            </dd>
          </div>
          <div
            v-for="f in fields"
            :key="f.name"
            class="flex justify-between gap-4"
          >
            <dt>{{ f.display || f.name }}</dt>
            <dd class="break-all text-right font-bold">
              {{ isSelectField(f) ? optionLabel(model[f.name]) : textValue(f.name) }}
              <span v-if="f.name === 'amount' && rate !== 1"> = {{ points }} {{ textWalletPoint }}</span>
            </dd>
          </div>
        </dl>
        <div class="flex gap-(--c17-gap-3)">
          <UiButton
            variant="secondary"
            @click="confirmOpen = false"
          >
            {{ $t('common.ui.action.cancel') }}
          </UiButton>
          <UiButton
            :disabled="!canSubmit"
            @click="confirmSubmit"
          >
            {{ $t('common.ui.action.confirm') }}
          </UiButton>
        </div>
      </div>
    </UiModal>

    <DepositMessageBox
      v-if="message"
      :message="message"
      @close="closeMessage"
    />
  </div>
</template>
