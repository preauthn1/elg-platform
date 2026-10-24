<script setup lang="ts">
import { MEMBER_PATH } from '#shared/utils/member'

/** C17 線上存款結果（圖片）。結果來自 useDepositResult。 */
const { result } = useDepositResult()
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('deposit.entry.title.deposit')"
      fallback="/topup"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) pb-(--c17-gap-4) pt-(--c17-gap-4) text-center">
      <section
        v-if="result?.url"
        class="flex flex-col gap-(--c17-gap-2) rounded-(--c17-field-radius) bg-surface p-(--c17-gap-3)"
        :style="{ boxShadow: 'var(--c17-shadow-panel)' }"
      >
        <img
          :src="result.url"
          alt="QR Code"
          class="mx-auto max-w-full"
        >
        <p
          v-if="result.orderNo"
          class="break-all font-mono"
          :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }"
        >
          {{ $t('common.ui.label.orderNo') }}: {{ result.orderNo }}
        </p>
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
