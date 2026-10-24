<script setup lang="ts">
import { commissionCell, formatFixed2 } from '#shared/utils/messages'

/** C17 的畫面，邏輯在 useMessagesSystemNotificationDetailPage。 */
const {
  site,
  labels,
  item,
  page,
  loading,
  logo,
  header,
  commission,
  summaryRows,
  showLose,
  payer,
  payee,
  statusClass,
} = useMessagesSystemNotificationDetailPage()
</script>

<template>
  <div>
    <div class="mb-3 flex items-center gap-3">
      <NuxtLink
        to="/messages/system"
        class="text-sm text-accent"
      >
        &lt; {{ $t('messages.system.nav.notification') }}
      </NuxtLink>
      <h1 class="text-xl font-semibold">
        {{ $t('common.ui.nav.details') }}
      </h1>
    </div>

    <p
      v-if="!item || !header"
      class="text-sm text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>

    <template v-else>
      <p
        v-if="header.statusKey"
        class="mb-3 text-center font-semibold"
        :class="statusClass"
      >
        {{ $t(header.statusKey) }}
      </p>

      <section class="mb-3 rounded-(--c17-radius-sm) bg-surface shadow-[var(--c17-shadow-panel)] p-4 text-center">
        <img
          v-if="logo"
          :src="logo"
          alt=""
          class="mx-auto mb-2 h-10 object-contain"
        >
        <div class="text-sm text-muted">
          {{ site?.raw.title }}
        </div>
        <div class="mt-1 font-semibold">
          {{ labels.resolveLabel(header.actionName) }}
        </div>
        <div
          v-if="header.content"
          class="mt-1 text-2xl font-semibold"
        >
          {{ header.content }}
        </div>
        <dl class="mt-3 space-y-1 text-left text-sm">
          <div
            v-for="row in [...header.rows, ...summaryRows]"
            :key="row.labelKey"
            class="flex justify-between gap-4"
          >
            <dt class="text-muted">
              {{ $t(row.labelKey) }}
            </dt>
            <dd class="text-right">
              {{ row.value }}
            </dd>
          </div>
        </dl>
      </section>

      <section
        v-if="payer || payee"
        class="mb-3 space-y-2 text-sm"
      >
        <div
          v-if="payer"
          class="flex items-center justify-between"
        >
          <span>{{ $t('common.misc.label.payer') }}</span>
          <img
            v-if="payer.kind === 'logo' && logo"
            :src="logo"
            alt=""
            class="h-8 object-contain"
          >
          <span v-else-if="payer.kind === 'text'">{{ payer.text }}</span>
        </div>
        <div
          v-if="payee"
          class="flex items-center justify-between"
        >
          <span>{{ $t('common.misc.label.payee') }}</span>
          <img
            v-if="payee.kind === 'logo' && logo"
            :src="logo"
            alt=""
            class="h-8 object-contain"
          >
          <span v-else-if="payee.kind === 'text'">{{ payee.text }}</span>
        </div>
      </section>

      <p
        v-if="loading"
        class="text-sm text-muted"
      >
        {{ $t('common.ui.label.loading') }}
      </p>

      <section
        v-if="commission"
        class="rounded-(--c17-radius-sm) bg-surface shadow-[var(--c17-shadow-panel)] p-3"
      >
        <div class="overflow-x-auto">
          <table class="w-full text-center text-sm">
            <thead>
              <tr class="text-muted">
                <th class="py-1 text-left">
                  {{ $t('auth.common.label.username') }}
                </th>
                <th class="py-1">
                  {{ $t('games.vendor.label.gameVendor') }}
                </th>
                <th class="py-1">
                  {{ $t('records.bet.label.turnover') }}
                </th>
                <th
                  v-if="showLose"
                  class="py-1"
                >
                  {{ $t('messages.detail.label.lose') }}
                </th>
                <th class="py-1">
                  {{ $t('common.amount.label.bonus') }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-if="commission.items.length === 0"
              >
                <td
                  :colspan="showLose ? 5 : 4"
                  class="py-3 text-muted"
                >
                  {{ $t('common.ui.empty.noData') }}
                </td>
              </tr>
              <tr
                v-for="(row, index) in commission.items"
                :key="index"
              >
                <td class="py-1 text-left">
                  {{ row.from_member_name }}
                </td>
                <td class="py-1">
                  {{ row.vendor_name }}
                </td>
                <td class="py-1">
                  {{ commissionCell(row, 'bet') }}
                </td>
                <td
                  v-if="showLose"
                  class="py-1"
                >
                  {{ commissionCell(row, 'lose') }}
                </td>
                <td class="py-1">
                  {{ formatFixed2(row.rebate) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <UiPagination
          v-model:page="page"
          :pages="commission.pagination.pages"
        />
      </section>
    </template>
  </div>
</template>
