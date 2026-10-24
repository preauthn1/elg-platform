<script setup lang="ts">
import { decFormat } from '#shared/utils/amount'
import type { AmountLike } from '#shared/utils/amount'

const {
  enabled,
  direction,
  amount,
  submitting,
  mainBalance,
  gameBalance,
  maxTransferAmount,
  remainingFlow,
  canSubmit,
  toggleDirection,
  load,
  submit,
} = useWalletTransfer()

const { t } = useI18n()
const alert = useAlert()
const confirming = ref(false)

const fromKey = computed(() => direction.value === 'main_to_game' ? 'wallet.overview.label.mainWallet' : 'wallet.overview.label.gameWallet')
const toKey = computed(() => direction.value === 'main_to_game' ? 'wallet.overview.label.gameWallet' : 'wallet.overview.label.mainWallet')
const fromBalance = computed(() => direction.value === 'main_to_game' ? mainBalance.value : gameBalance.value)
const toBalance = computed(() => direction.value === 'main_to_game' ? gameBalance.value : mainBalance.value)

function display(value: AmountLike): string {
  return value === null || value === undefined ? '—' : decFormat(value, 2)
}

function onToggle() {
  toggleDirection()
  confirming.value = false
}

async function onConfirm() {
  confirming.value = false
  const res = await submit()
  if (res && isApiOk(res)) alert.show(`${t('wallet.transfer.title.transfer')} ${t('common.ui.msg.success')}`, 'success')
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('wallet.transfer.title.transfer')"
      fallback="/wallet"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <p
        v-if="!enabled"
        class="text-muted"
      >
        {{ $t('wallet.transfer.hint.notAvailable') }}
      </p>

      <section
        v-else
        class="flex flex-col gap-(--c17-gap-4) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]"
      >
        <div class="flex items-center gap-4">
          <div class="flex-1 text-center">
            <div class="text-sm text-muted">
              {{ $t(fromKey) }}
            </div>
            <div class="text-lg">
              {{ display(fromBalance) }}
            </div>
          </div>
          <button
            type="button"
            class="h-8 rounded-(--c17-field-radius) border border-field-line bg-surface px-(--c17-gap-3) font-bold"
            :disabled="submitting"
            @click="onToggle"
          >
            ⇄
          </button>
          <div class="flex-1 text-center">
            <div class="text-sm text-muted">
              {{ $t(toKey) }}
            </div>
            <div class="text-lg">
              {{ display(toBalance) }}
            </div>
          </div>
        </div>

        <div class="flex items-center justify-between text-sm">
          <label for="wallet-transfer-amount">{{ $t('common.amount.label.amount') }}</label>
          <span class="text-muted">
            {{ $t('common.amount.label.maximum') }}: {{ decFormat(maxTransferAmount, 2) }}
          </span>
        </div>
        <input
          id="wallet-transfer-amount"
          v-model="amount"
          type="text"
          inputmode="decimal"
          class="h-(--c17-field-h) w-full rounded-(--c17-field-radius) border border-field-line bg-surface px-(--c17-gap-3) font-semibold text-(length:--c17-text-link)"
          :placeholder="$t('common.amount.placeholder.amount')"
          :disabled="submitting"
        >

        <p
          v-if="direction === 'game_to_main'"
          class="text-sm"
        >
          {{ $t('wallet.transfer.label.turnoverLimit') }}: {{ decFormat(remainingFlow, 4) }}
        </p>

        <div
          class="rounded-(--c17-field-radius) p-(--c17-gap-3) text-(length:--c17-text-link)"
          :style="{ background: 'var(--c17-primary-ink)' }"
        >
          <div class="font-semibold">
            {{ $t('common.ui.label.notice') }}:
          </div>
          <div>{{ $t('wallet.transfer.text.lockTurnover') }}</div>
        </div>

        <div
          v-if="confirming"
          class="rounded-(--c17-field-radius) border border-field-line p-(--c17-gap-3) text-(length:--c17-text-link)"
        >
          <p>{{ $t('common.ui.label.notice') }}: {{ $t('wallet.transfer.text.lockTurnover') }}</p>
          <div class="mt-2 flex gap-2">
            <button
              type="button"
              class="h-8 rounded-(--c17-field-radius) bg-accent px-(--c17-gap-3) font-bold text-inverse"
              @click="onConfirm"
            >
              {{ $t('common.ui.action.confirm') }}
            </button>
            <button
              type="button"
              class="h-8 rounded-(--c17-field-radius) border border-field-line bg-surface px-(--c17-gap-3) font-bold"
              @click="confirming = false"
            >
              {{ $t('common.ui.action.cancel') }}
            </button>
          </div>
        </div>
        <button
          v-else
          type="button"
          class="h-(--c17-btn-h) w-full rounded-(--c17-field-radius) bg-accent font-extrabold text-inverse uppercase disabled:bg-disabled disabled:text-disabled-ink"
          :disabled="!canSubmit"
          @click="confirming = true"
        >
          {{ $t('wallet.transfer.title.transfer') }}
        </button>

        <p
          v-if="submitting"
          class="text-sm text-muted"
        >
          {{ $t('common.ui.label.loading') }}
        </p>
      </section>

      <NuxtLink
        to="/wallet"
        class="text-accent"
        :style="{ fontSize: 'var(--c17-text-link)' }"
      >
        {{ $t('wallet.overview.title.wallet') }}
      </NuxtLink>
    </div>
  </div>
</template>
