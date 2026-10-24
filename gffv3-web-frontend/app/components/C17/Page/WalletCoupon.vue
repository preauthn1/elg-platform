<script setup lang="ts">
const {
  enabled,
  submitting,
  promptKey,
  redeem,
  successText,
  clearResult,
} = useCoupon()

const code = ref('')
const imageFailed = ref(false)

async function onSubmit() {
  imageFailed.value = false
  const res = await redeem(code.value)
  if (res && isApiOk(res)) code.value = ''
}

function onClose() {
  imageFailed.value = false
  clearResult()
}
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('wallet.exchange.title.coupon')"
      :fallback="'/wallet'"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <p
        v-if="!enabled"
        class="text-(length:--c17-text-link) text-muted"
      >
        {{ $t('wallet.exchange.hint.notAvailable') }}
      </p>

      <section
        v-else
        class="flex flex-col gap-(--c17-gap-3) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]"
      >
        <form
          class="flex flex-col gap-(--c17-gap-3)"
          @submit.prevent="onSubmit"
        >
          <UiTextField
            v-model="code"
            :label="$t('wallet.exchange.label.promoCode')"
            :maxlength="10"
            autocomplete="off"
            class="[&_input]:uppercase"
            :placeholder="$t('wallet.exchange.placeholder.redemptionCode')"
            :disabled="submitting"
          />
          <UiButton
            type="submit"
            :disabled="submitting"
          >
            {{ $t('common.ui.action.determine') }}
          </UiButton>
        </form>

        <p
          v-if="promptKey"
          class="text-(length:--c17-text-link) text-danger"
        >
          {{ $t(promptKey) }}
        </p>

        <div
          v-if="successText"
          class="flex flex-col gap-(--c17-gap-2) rounded-(--c17-field-radius) bg-surface-2 p-(--c17-gap-3)"
        >
          <img
            v-if="successText.imageUrl && !imageFailed"
            :src="successText.imageUrl"
            alt=""
            class="max-h-96 rounded-(--c17-radius-sm)"
            @error="imageFailed = true"
          >
          <p
            v-else
            class="text-(length:--c17-text-link) text-ink"
          >
            {{ $t('events.claim.msg.congratulations') }} {{ successText.amount }} {{ successText.unit }}
          </p>
          <UiButton
            variant="secondary"
            @click="onClose"
          >
            {{ $t('common.ui.action.determine') }}
          </UiButton>
        </div>
      </section>
    </div>

    <EventsPrizeReminder />
  </div>
</template>
