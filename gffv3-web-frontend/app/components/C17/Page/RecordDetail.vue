<script setup lang="ts">
import { formatRecordAmount } from '~/utils/records'

/** C17 紀錄明細。資料在 useRecordDetail。 */
const {
  site,
  user,
  records,
  type,
  loading,
  deposit,
  withdrawal,
  prize,
  titleKey,
  statusText,
} = useRecordDetail()

const siteTitle = computed(() => site.value?.raw.title ?? '')

const siteLogo = computed(() => site.value?.raw.logo_mobile || site.value?.raw.logo_pc || '')

const username = computed(() => user.value?.username ?? '')
</script>

<template>
  <div>
    <C17PageHeader
      :title="`${$t(titleKey)} · ${$t('common.ui.nav.details')}`"
      :fallback="`/record/${type}`"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <p
        v-if="loading"
        class="text-sm text-muted"
      >
        {{ $t('common.ui.label.loading') }}
      </p>

      <dl
        v-else-if="type === 'deposit' && deposit"
        class="grid grid-cols-2 gap-y-2 rounded-(--c17-radius-sm) bg-surface shadow-[var(--c17-shadow-panel)] p-4 text-sm"
      >
        <dt class="text-muted">
          {{ $t('common.ui.label.status') }}
        </dt>
        <dd>{{ deposit.status_name || statusText(deposit.status) }}</dd>
        <dt class="text-muted">
          {{ $t('common.ui.label.orderNo') }}
        </dt>
        <dd>{{ deposit.order_id }}</dd>
        <dt class="text-muted">
          {{ $t('common.time.label.date') }}
        </dt>
        <dd>{{ deposit.created_at }}</dd>
        <dt class="text-muted">
          {{ $t('common.bank.label.bank') }}
        </dt>
        <dd class="flex items-center gap-2">
          <img
            v-if="deposit.image_path"
            :src="deposit.image_path"
            alt=""
            class="h-6"
          >
          <span>{{ deposit.order_type }}</span>
        </dd>
        <dt class="text-muted">
          {{ $t('common.amount.label.amount') }}
        </dt>
        <dd>{{ formatRecordAmount(deposit.amount) }}</dd>
        <dt class="text-muted">
          {{ $t('records.deposit.label.point') }}
        </dt>
        <dd>{{ formatRecordAmount(deposit.point) }} {{ records.walletPointText.value }}</dd>
        <template v-if="deposit.deposit_url">
          <dt class="text-muted">
            {{ $t('records.deposit.label.depositSite') }}
          </dt>
          <dd class="break-all">
            <a
              :href="deposit.deposit_url"
              target="_blank"
              rel="noopener"
              class="text-accent"
            >{{ deposit.deposit_url }}</a>
          </dd>
        </template>
      </dl>

      <dl
        v-else-if="type === 'withdrawal' && withdrawal"
        class="grid grid-cols-2 gap-y-2 rounded-(--c17-radius-sm) bg-surface shadow-[var(--c17-shadow-panel)] p-4 text-sm"
      >
        <dt class="text-muted">
          {{ $t('common.ui.label.status') }}
        </dt>
        <dd>{{ withdrawal.status }} ({{ statusText(withdrawal.icon_status) }})</dd>
        <dt class="text-muted">
          {{ $t('common.ui.label.type') }}
        </dt>
        <dd>{{ withdrawal.remark }}</dd>
        <dt class="text-muted">
          {{ $t('common.ui.label.orderNo') }}
        </dt>
        <dd>{{ withdrawal.id }}</dd>
        <dt class="text-muted">
          {{ $t('common.time.label.date') }}
        </dt>
        <dd>{{ withdrawal.created_at }}</dd>
        <dt class="text-muted">
          {{ $t('common.bank.label.bank') }}
        </dt>
        <dd class="flex items-center gap-2">
          <img
            v-if="withdrawal.bank_icon"
            :src="withdrawal.bank_icon"
            alt=""
            class="h-6"
          >
          <span>{{ withdrawal.bank_name }}</span>
        </dd>
        <dt class="text-muted">
          {{ $t('common.amount.label.amount') }}
        </dt>
        <dd>{{ formatRecordAmount(withdrawal.amount) }}</dd>
      </dl>

      <dl
        v-else-if="type === 'event' && prize"
        class="grid grid-cols-2 gap-y-2 rounded-(--c17-radius-sm) bg-surface shadow-[var(--c17-shadow-panel)] p-4 text-sm"
      >
        <dt class="text-muted">
          {{ $t('site.nav.nav.events') }}
        </dt>
        <dd>{{ siteTitle }} · {{ prize.event_name }}</dd>
        <dt class="text-muted">
          {{ $t('records.event.label.reward') }}
        </dt>
        <dd>{{ prize.name }} {{ prize.point }}</dd>
        <dt class="text-muted">
          {{ $t('common.ui.label.orderNo') }}
        </dt>
        <dd>{{ prize.id }}</dd>
        <dt class="text-muted">
          {{ $t('common.time.label.date') }}
        </dt>
        <dd>{{ prize.updated_at }}</dd>
        <dt class="text-muted">
          {{ $t('common.misc.label.payer') }}
        </dt>
        <dd>
          <img
            v-if="siteLogo"
            :src="siteLogo"
            alt=""
            class="h-8"
          >
          <span v-else>{{ siteTitle }}</span>
        </dd>
        <dt class="text-muted">
          {{ $t('common.misc.label.payee') }}
        </dt>
        <dd>{{ username }}</dd>
      </dl>

      <p
        v-else
        class="text-sm text-muted"
      >
        {{ $t('common.ui.empty.noData') }}
      </p>
    </div>
  </div>
</template>
