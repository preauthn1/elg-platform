<script setup lang="ts">
import {
  apiToDatetimeLocal,
  datetimeLocalToApi,
  type WalletHistoryType,
} from '#shared/utils/wallet'
import { decFormat } from '#shared/utils/amount'

const {
  gameWalletAvailable,
  walletType,
  range,
  page,
  pages,
  items,
  total,
  hasPagination,
  loading,
  fetchHistories,
  setWalletType,
  search,
  goToPage,
} = useWalletHistories()

const { t } = useI18n()
const walletTabs = computed(() => [
  { key: 'main' as WalletHistoryType, label: t('wallet.overview.label.mainWallet') },
  { key: 'game' as WalletHistoryType, label: t('wallet.overview.label.gameWallet') },
])

const startInput = ref(range.value.start_time ? apiToDatetimeLocal(range.value.start_time) : '')
const endInput = ref(range.value.end_time ? apiToDatetimeLocal(range.value.end_time) : '')

function onSearch() {
  void search(datetimeLocalToApi(startInput.value), datetimeLocalToApi(endInput.value))
}

function onType(type: WalletHistoryType) {
  void setWalletType(type)
}

onMounted(() => {
  void fetchHistories()
})
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('wallet.history.title.record')"
      fallback="/wallet"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <nav
        v-if="gameWalletAvailable"
        class="flex gap-(--c17-gap-2)"
      >
        <button
          v-for="tab in walletTabs"
          :key="tab.key"
          type="button"
          class="flex h-8 shrink-0 items-center rounded-(--c17-field-radius) border px-(--c17-gap-3) font-bold"
          :style="{
            fontSize: 'var(--c17-text-link)',
            background: walletType === tab.key ? 'var(--c17-primary)' : 'var(--c17-surface)',
            color: walletType === tab.key ? 'var(--c17-primary-ink)' : 'var(--c17-ink)',
            borderColor: walletType === tab.key ? 'var(--c17-primary)' : 'var(--c17-faint)',
          }"
          @click="onType(tab.key)"
        >
          {{ tab.label }}
        </button>
      </nav>

      <form
        class="flex flex-col gap-(--c17-gap-3)"
        @submit.prevent="onSearch"
      >
        <div class="grid grid-cols-2 gap-x-(--c17-gap-3)">
          <UiTextField
            v-model="startInput"
            type="datetime-local"
            :label="$t('common.time.label.startTime')"
          />
          <UiTextField
            v-model="endInput"
            type="datetime-local"
            :label="$t('common.time.label.endDate')"
          />
        </div>
        <UiButton
          type="submit"
          :disabled="loading"
          :loading="loading"
        >
          {{ $t('common.ui.action.search') }}
        </UiButton>
        <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }">
          {{ $t('common.filter.option.last7Days') }}
        </p>
      </form>

      <section class="rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) text-(length:--c17-text-link) shadow-[var(--c17-shadow-panel)]">
        <p
          v-if="loading"
          class="text-muted"
        >
          {{ $t('common.ui.label.loading') }}
        </p>
        <p
          v-else-if="items.length === 0"
          class="text-muted"
        >
          {{ $t('common.ui.empty.noData') }}
        </p>
        <div
          v-else
          class="overflow-x-auto"
        >
          <table class="w-full text-left [&_td]:py-(--c17-gap-2) [&_td]:pr-(--c17-gap-3) [&_th]:py-(--c17-gap-2) [&_th]:pr-(--c17-gap-3)">
            <thead>
              <tr
                class="font-bold"
                :style="{ background: 'var(--c17-primary-ink)', color: 'var(--c17-ink)' }"
              >
                <th class="pl-(--c17-gap-2)">
                  {{ $t('common.time.label.time') }}
                </th>
                <th>{{ $t('common.ui.label.type') }}</th>
                <th class="text-right">
                  {{ $t('wallet.history.label.beforeBalance') }}
                </th>
                <th class="text-right">
                  {{ $t('common.amount.label.amount') }}
                </th>
                <th class="text-right">
                  {{ $t('wallet.history.label.afterBalance') }}
                </th>
                <th>{{ $t('common.ui.label.status') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr
                v-for="row in items"
                :key="row.id"
              >
                <td class="pl-(--c17-gap-2) whitespace-nowrap">
                  {{ row.created_at }}
                </td>
                <td>{{ row.type }}</td>
                <td class="text-right tabular-nums">
                  {{ decFormat(row.before_balance, 2) }}
                </td>
                <td
                  class="text-right font-bold tabular-nums"
                  :style="{ color: 'var(--c17-primary)' }"
                >
                  {{ decFormat(row.point, 2) }}
                </td>
                <td class="text-right tabular-nums">
                  {{ decFormat(row.balance, 2) }}
                </td>
                <td>{{ row.status }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div
          v-if="hasPagination"
          class="mt-(--c17-gap-3) flex items-center gap-(--c17-gap-3)"
        >
          <button
            type="button"
            class="h-8 rounded-(--c17-field-radius) border border-field-line bg-surface px-(--c17-gap-3) font-bold disabled:opacity-40"
            :disabled="loading || page <= 1"
            @click="goToPage(page - 1)"
          >
            {{ $t('common.pagination.action.previousPage') }}
          </button>
          <span class="tabular-nums">{{ page }} / {{ pages }}</span>
          <button
            type="button"
            class="h-8 rounded-(--c17-field-radius) border border-field-line bg-surface px-(--c17-gap-3) font-bold disabled:opacity-40"
            :disabled="loading || page >= pages"
            @click="goToPage(page + 1)"
          >
            {{ $t('common.pagination.nav.nextPage') }}
          </button>
          <span class="ml-auto text-muted">{{ $t('common.pagination.label.total') }}: {{ total }}</span>
        </div>
      </section>
    </div>
  </div>
</template>
