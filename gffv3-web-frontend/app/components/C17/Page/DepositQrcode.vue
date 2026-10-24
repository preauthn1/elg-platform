<script setup lang="ts">
import { MEMBER_PATH } from '#shared/utils/member'

/** C17 線上存款結果（QR code）。結果與複製在 useDepositResult。 */
const { result, copied, copyPayload } = useDepositResult()
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('deposit.entry.title.deposit')"
      fallback="/topup"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) pb-(--c17-gap-4) pt-(--c17-gap-4) text-center">
      <section
        v-if="result"
        class="flex flex-col gap-(--c17-gap-2) rounded-(--c17-field-radius) bg-surface p-(--c17-gap-3)"
        :style="{ boxShadow: 'var(--c17-shadow-panel)' }"
      >
        <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }">
          POS QR Code
        </p>
        <p
          class="break-all font-mono font-bold"
          :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
        >
          {{ result.qrPayload }}
        </p>
        <p
          v-if="result.orderNo"
          class="break-all font-mono"
          :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }"
        >
          {{ $t('common.ui.label.orderNo') }}: {{ result.orderNo }}
        </p>
        <button
          type="button"
          class="self-center font-bold"
          :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-primary)' }"
          @click="copyPayload"
        >
          {{ copied ? $t('deposit.common.msg.copied') : $t('common.ui.action.copy') }}
        </button>
      </section>

      <UiButton
        class="mt-(--c17-gap-4)"
        @click="navigateTo(MEMBER_PATH.home)"
      >
        {{ $t('deposit.receipt.nav.leave') }}
      </UiButton>
    </div>
  </div>
</template>
