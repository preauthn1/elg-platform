<script setup lang="ts">
/** C17 提款金額。流程在 useWithdrawalAmount。 */
const {
  w,
  selected,
  amount,
  step,
  paymentPassword,
  smsCode,
  smsSent,
  busy,
  successAt,
  limits,
  amountValid,
  rateLabel,
  points,
  hasFee,
  fee,
  feePercent,
  isPercentFee,
  overLimit,
  overFee,
  net,
  available,
  amountLabel,
  submitAmount,
  sendSms,
  confirmOrder,
  verifyPaymentPassword,
  verifySms,
  finish,
} = useWithdrawalAmount()

const showPassword = [REDACTED]
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('withdrawal.entry.title.withdrawal')"
      fallback="/withdrawal"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <template v-if="selected">
        <section
          class="rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3)"
          :style="{ boxShadow: 'var(--c17-shadow-panel)' }"
        >
          <dl
            class="grid grid-cols-2 gap-x-(--c17-gap-4) gap-y-1"
            :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
          >
            <dt class="text-muted">
              {{ $t('common.bank.label.bankName') }}
            </dt>
            <dd class="text-right font-bold">
              {{ selected.bank_name }}
            </dd>
            <dt class="text-muted">
              {{ $t('competitions.ranking.label.accountName') }}
            </dt>
            <dd class="text-right font-bold">
              {{ selected.account_name }}
            </dd>
            <dt class="text-muted">
              {{ $t('common.bank.label.accountNo') }}
            </dt>
            <dd class="break-all text-right font-mono font-bold">
              {{ selected.account_no }}
            </dd>
            <template v-if="available !== null">
              <dt class="text-muted">
                {{ $t('withdrawal.form.label.available') }}
              </dt>
              <dd class="text-right font-bold tabular-nums">
                {{ available }}
              </dd>
            </template>
            <template v-if="rateLabel">
              <dt class="text-muted">
                {{ $t('withdrawal.form.label.exchangeRate') }}
              </dt>
              <dd class="text-right font-bold">
                {{ rateLabel }}
              </dd>
            </template>
          </dl>
        </section>

        <form
          v-if="step === 'input'"
          class="flex flex-col gap-(--c17-gap-3)"
          @submit.prevent="submitAmount"
        >
          <div>
            <UiTextField
              :model-value="amount"
              :label="$t(amountLabel)"
              inputmode="decimal"
              :placeholder="$t('common.amount.placeholder.amount')"
              @update:model-value="v => (amount = v.trim())"
            />
            <p
              class="mt-[6px] font-semibold"
              :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }"
            >
              {{ $t('common.amount.label.minimum') }} {{ limits.min }} / {{ $t('common.amount.label.maximum') }} {{ limits.max }}
            </p>
          </div>

          <dl
            class="grid grid-cols-2 gap-x-(--c17-gap-4) gap-y-1"
            :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
          >
            <dt class="text-muted">
              {{ $t('common.amount.label.platformPoint') }}
            </dt>
            <dd class="text-right font-bold tabular-nums">
              {{ points }}
            </dd>
            <template v-if="hasFee">
              <dt class="text-muted">
                {{ $t('withdrawal.form.label.fee') }}<span v-if="isPercentFee"> ({{ feePercent }}%)</span>
              </dt>
              <dd class="text-right font-bold tabular-nums">
                {{ fee }}
              </dd>
            </template>
            <template v-if="overLimit">
              <dt class="text-muted">
                {{ $t('withdrawal.limit.hint.exceedHandlingFee') }}
              </dt>
              <dd class="text-right font-bold tabular-nums">
                {{ overFee }}
              </dd>
            </template>
            <dt class="text-muted">
              {{ $t('withdrawal.form.label.amountReceived') }}
            </dt>
            <dd
              class="text-right font-bold tabular-nums"
              :style="{ color: 'var(--c17-primary)' }"
            >
              {{ net }}
            </dd>
          </dl>

          <UiButton
            type="submit"
            class="mt-(--c17-gap-4)"
            :disabled="!amountValid"
          >
            {{ $t('common.ui.action.next') }}
          </UiButton>
        </form>

        <section
          v-else-if="step === 'confirm' || step === 'reward-notice'"
          class="flex flex-col gap-(--c17-gap-3) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3)"
          :style="{ boxShadow: 'var(--c17-shadow-panel)' }"
        >
          <p
            v-if="step === 'reward-notice'"
            :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
          >
            {{ $t('withdrawal.form.hint.rewardDisqualify') }}
          </p>
          <dl
            v-else
            class="grid grid-cols-2 gap-x-(--c17-gap-4) gap-y-1"
            :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
          >
            <dt class="text-muted">
              {{ $t(amountLabel) }}
            </dt>
            <dd class="text-right font-bold tabular-nums">
              {{ amount }}
            </dd>
            <dt class="text-muted">
              {{ $t('withdrawal.form.label.amountReceived') }}
            </dt>
            <dd class="text-right font-bold tabular-nums">
              {{ net }}
            </dd>
            <dt class="text-muted">
              {{ $t('common.amount.label.platformPoint') }}
            </dt>
            <dd class="text-right font-bold tabular-nums">
              {{ points }}
            </dd>
          </dl>
          <div class="flex gap-(--c17-gap-3)">
            <UiButton
              variant="secondary"
              :disabled="busy"
              @click="step = 'input'"
            >
              {{ $t('common.ui.action.cancel') }}
            </UiButton>
            <UiButton
              :loading="busy"
              @click="confirmOrder"
            >
              {{ $t('common.ui.action.confirm') }}
            </UiButton>
          </div>
        </section>

        <section v-else-if="step === 'verify'">
          <form
            v-if="w.verifyStep.value === 'payment-password'"
            class="flex flex-col gap-(--c17-gap-3)"
            @submit.prevent="verifyPaymentPassword"
          >
            <UiTextField
              v-model="paymentPassword"
              :label="$t('member.paymentPassword.label.paymentPassword')"
              :type="showPassword ? 'text' : 'password'"
              :maxlength="12"
              autocomplete="off"
            >
              <template #prefix>
                <UiFieldIcon name="password" />
              </template>
              <template #suffix>
                <button
                  type="button"
                  class="flex items-center"
                  :aria-label="$t('member.paymentPassword.label.paymentPassword')"
                  @click="showPassword = [REDACTED]"
                >
                  <UiFieldIcon :name="showPassword ? 'eye-show' : 'eye-hide'" />
                </button>
              </template>
            </UiTextField>
            <UiButton
              type="submit"
              class="mt-(--c17-gap-4)"
              :loading="busy"
              :disabled="paymentPassword.length < 6"
            >
              {{ $t('common.ui.action.confirm') }}
            </UiButton>
          </form>

          <form
            v-else
            class="flex flex-col gap-(--c17-gap-3)"
            @submit.prevent="verifySms"
          >
            <p
              class="text-muted"
              :style="{ fontSize: 'var(--c17-text-link)' }"
            >
              {{ $t('auth.otp.hint.codeToPhone') }} {{ w.memberMobile.value }}
            </p>
            <UiTextField
              :model-value="smsCode"
              :label="$t('auth.otp.label.smsCode')"
              :maxlength="6"
              inputmode="numeric"
              :placeholder="$t('auth.otp.placeholder.code')"
              @update:model-value="v => (smsCode = v.trim())"
            >
              <template #prefix>
                <UiFieldIcon name="verify-code" />
              </template>
            </UiTextField>
            <div class="mt-(--c17-gap-4) flex gap-(--c17-gap-3)">
              <UiButton
                variant="secondary"
                :disabled="busy"
                @click="sendSms"
              >
                {{ smsSent ? $t('auth.otp.action.resend') : $t('auth.otp.action.sendCode') }}
              </UiButton>
              <UiButton
                type="submit"
                :loading="busy"
                :disabled="smsCode.length !== 6"
              >
                {{ $t('common.ui.action.verify') }}
              </UiButton>
            </div>
          </form>
        </section>

        <section
          v-else-if="step === 'done'"
          class="flex flex-col gap-(--c17-gap-2) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) text-center"
          :style="{ boxShadow: 'var(--c17-shadow-panel)' }"
        >
          <p
            class="font-bold"
            :style="{ fontSize: 'var(--c17-text-title)', color: 'var(--c17-primary)' }"
          >
            {{ $t('withdrawal.result.msg.applySuccess') }}
          </p>
          <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }">
            {{ successAt }}
          </p>
          <UiButton
            class="mt-(--c17-gap-3)"
            @click="finish"
          >
            {{ $t('common.ui.action.confirm') }}
          </UiButton>
        </section>
      </template>

      <p
        v-else
        class="text-muted"
        :style="{ fontSize: 'var(--c17-text-link)' }"
      >
        {{ $t('common.bank.placeholder.select') }}
      </p>
    </div>
  </div>
</template>
