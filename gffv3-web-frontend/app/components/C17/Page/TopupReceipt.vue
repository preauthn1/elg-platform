<script setup lang="ts">
import { MEMBER_PATH } from '#shared/utils/member'

/** C17 轉帳存款單據。上傳、取消、狀態在 useDepositReceipt。 */
const { site } = useSite()
const { user } = useUserSession()
const {
  bankOrder,
  receiptDataUrl,
  submitting,
  message,
  showCancel,
  showSuccess,
  submittedAt,
  bsbAlias,
  accountNo,
  statusKey,
  onFileChange,
  submit,
  confirmCancel,
  onSuccessClose,
} = useDepositReceipt()

const fileInput = ref<HTMLInputElement | null>(null)
const payeeLogo = computed(() => site.value?.raw.logo_mobile || site.value?.raw.logo_pc || '')
const payeeName = computed(() => site.value?.raw.title || site.value?.domain || '')

function pickFile() {
  fileInput.value?.click()
}
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('deposit.common.title.deposit')"
      :fallback="MEMBER_PATH.home"
    />

    <div
      v-if="bankOrder"
      class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) pb-(--c17-gap-4) pt-(--c17-gap-4)"
    >
      <section
        class="flex flex-col gap-(--c17-gap-2) rounded-(--c17-field-radius) bg-surface p-(--c17-gap-3) text-center"
        :style="{ boxShadow: 'var(--c17-shadow-panel)' }"
      >
        <p
          class="font-bold"
          :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
        >
          {{ bankOrder.bank_name }}
        </p>
        <p
          class="font-bold"
          :style="{ fontSize: 'var(--c17-text-title)', color: 'var(--c17-primary)' }"
        >
          {{ $t('common.amount.label.amount') }} {{ bankOrder.amount }}
        </p>
        <dl
          class="flex flex-col gap-(--c17-gap-2) text-left"
          :style="{ fontSize: 'var(--c17-text-link)' }"
        >
          <div class="flex justify-between gap-(--c17-gap-2)">
            <dt :style="{ color: 'var(--c17-muted)' }">
              {{ $t('common.bank.label.accountName') }}
            </dt>
            <dd class="truncate font-mono font-bold">
              {{ bankOrder.account_name }}
            </dd>
          </div>
          <div
            v-if="accountNo.bsb !== null"
            class="flex justify-between gap-(--c17-gap-2)"
          >
            <dt :style="{ color: 'var(--c17-muted)' }">
              {{ bsbAlias }}
            </dt>
            <dd class="truncate font-mono font-bold">
              {{ accountNo.bsb }}
            </dd>
          </div>
          <div class="flex justify-between gap-(--c17-gap-2)">
            <dt :style="{ color: 'var(--c17-muted)' }">
              {{ $t('common.bank.label.accountNo') }}
            </dt>
            <dd class="truncate font-mono font-bold">
              {{ accountNo.account }}
            </dd>
          </div>
          <div class="flex justify-between gap-(--c17-gap-2)">
            <dt
              class="shrink-0"
              :style="{ color: 'var(--c17-muted)' }"
            >
              {{ $t('common.ui.label.orderNo') }}
            </dt>
            <dd class="break-all text-right font-mono font-bold">
              {{ bankOrder.order_no }}
            </dd>
          </div>
        </dl>
        <img
          v-if="bankOrder.qrcode"
          :src="bankOrder.qrcode"
          alt=""
          class="mx-auto max-h-48 object-contain"
        >
      </section>

      <dl
        class="flex flex-col gap-(--c17-gap-2)"
        :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
      >
        <div class="flex justify-between gap-(--c17-gap-2)">
          <dt :style="{ color: 'var(--c17-muted)' }">
            {{ $t('common.ui.label.status') }}
          </dt>
          <dd class="font-bold">
            {{ statusKey ? $t(statusKey) : '-' }}
          </dd>
        </div>
        <div class="flex justify-between gap-(--c17-gap-2)">
          <dt :style="{ color: 'var(--c17-muted)' }">
            {{ $t('common.misc.label.payer') }}
          </dt>
          <dd class="font-bold">
            {{ user?.username }}
          </dd>
        </div>
        <div class="flex items-center justify-between gap-(--c17-gap-2)">
          <dt :style="{ color: 'var(--c17-muted)' }">
            {{ $t('common.misc.label.payee') }}
          </dt>
          <dd class="font-bold">
            <img
              v-if="payeeLogo"
              :src="payeeLogo"
              :alt="payeeName"
              class="h-5 object-contain"
            >
            <span v-else>{{ payeeName }}</span>
          </dd>
        </div>
        <div class="flex justify-between gap-(--c17-gap-2)">
          <dt :style="{ color: 'var(--c17-muted)' }">
            {{ $t('deposit.receipt.label.receipt') }}
          </dt>
          <dd
            class="font-bold"
            :class="receiptDataUrl ? '' : 'text-danger'"
          >
            {{ receiptDataUrl ? $t('deposit.receipt.action.uploadPhoto') : $t('deposit.receipt.label.notUploaded') }}
          </dd>
        </div>
      </dl>

      <input
        ref="fileInput"
        type="file"
        accept="image/*"
        class="hidden"
        @change="onFileChange"
      >
      <img
        v-if="receiptDataUrl"
        :src="receiptDataUrl"
        alt=""
        class="mx-auto max-h-96 cursor-pointer rounded-(--c17-field-radius) object-contain"
        @click="pickFile"
      >

      <div class="mt-(--c17-gap-4) flex flex-col gap-(--c17-gap-3)">
        <UiButton
          v-if="!receiptDataUrl"
          variant="secondary"
          @click="pickFile"
        >
          {{ $t('deposit.receipt.action.upload') }}
        </UiButton>
        <UiButton
          v-else
          :loading="submitting"
          @click="submit"
        >
          {{ $t('common.ui.action.submit') }}
        </UiButton>
        <button
          type="button"
          class="font-bold"
          :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-primary)' }"
          @click="showCancel = true"
        >
          {{ $t('deposit.receipt.action.cancelOrder') }}
        </button>
      </div>
    </div>

    <UiModal
      v-if="showCancel"
      :title="$t('deposit.receipt.action.cancelOrder')"
      @close="showCancel = false"
    >
      <div class="flex flex-col gap-(--c17-gap-4)">
        <p :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }">
          {{ $t('deposit.receipt.msg.cancelConfirm') }}
        </p>
        <div class="flex gap-(--c17-gap-3)">
          <UiButton
            variant="secondary"
            @click="showCancel = false"
          >
            {{ $t('common.ui.action.cancel') }}
          </UiButton>
          <UiButton @click="confirmCancel">
            {{ $t('common.ui.action.confirm') }}
          </UiButton>
        </div>
      </div>
    </UiModal>

    <UiModal
      v-if="showSuccess && bankOrder"
      :title="bankOrder.bank_name"
      :dismissible="false"
      @close="onSuccessClose"
    >
      <div class="flex flex-col gap-(--c17-gap-2) text-center">
        <p
          class="font-bold"
          :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
        >
          {{ $t('common.ui.msg.applySuccess') }}
        </p>
        <p
          class="font-bold"
          :style="{ fontSize: 'var(--c17-text-title)', color: 'var(--c17-primary)' }"
        >
          {{ $t('common.amount.label.amount') }} {{ bankOrder.amount }}
        </p>
        <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }">
          {{ submittedAt }}
        </p>
        <UiButton
          class="mt-(--c17-gap-3)"
          @click="onSuccessClose"
        >
          {{ $t('common.ui.action.confirm') }}
        </UiButton>
      </div>
    </UiModal>

    <DepositMessageBox
      v-if="message"
      :message="message"
      @close="message = null"
    />
  </div>
</template>
