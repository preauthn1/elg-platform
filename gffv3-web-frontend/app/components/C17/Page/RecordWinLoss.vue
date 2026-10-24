<script setup lang="ts">
import type { SelectOption } from '~/components/Ui/SelectField.vue'
import { formatRecordAmount } from '~/utils/records'

/** C17 輸贏報表。資料在 useWinLossReport，這裡只有下拉選單的選項格式與輸贏的顏色。 */
const {
  dayPresets,
  selectedDayKey,
  vendors,
  selectedVendorId,
  page,
  perPage,
  report,
  loading,
  loadFailed,
  total,
} = useWinLossReport()

const vendorOptions = computed<SelectOption[]>(() =>
  vendors.value.map(v => ({ key: v.vendor_id, label: v.vendor_name })),
)

const dayOptions = computed<SelectOption[]>(() =>
  dayPresets.value.map(p => ({ key: p.key, label: p.label })),
)

/** 輸贏：正數綠、負數紅，零用一般字色。 */
function amountTone(value: unknown): string {
  const n = Number(value)
  if (!Number.isFinite(n) || n === 0) return 'var(--c17-ink)'
  return n > 0 ? 'var(--c17-success)' : 'var(--c17-error)'
}
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('records.winLoss.title.report')"
      :fallback="'/record'"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <div class="grid grid-cols-2 gap-x-(--c17-gap-3)">
        <UiSelectField
          v-model="selectedVendorId"
          :label="$t('games.vendor.label.gameVendor')"
          :options="vendorOptions"
        />
        <UiSelectField
          v-model="selectedDayKey"
          :label="$t('common.time.label.date')"
          :options="dayOptions"
        />
      </div>

      <!-- 總計先給一張螢光卡，不要只當表尾的一行小字 -->
      <section
        v-if="report"
        class="grid grid-cols-2 gap-(--c17-gap-3) rounded-(--c17-radius-sm) p-(--c17-gap-3)"
        :style="{ background: 'var(--c17-wallet-bg)', boxShadow: 'var(--c17-shadow-card)' }"
      >
        <div>
          <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }">
            {{ $t('records.winLoss.label.totalBets') }}
          </p>
          <p
            class="font-bold tabular-nums"
            :style="{ fontSize: 'var(--c17-text-lg)', color: 'var(--c17-primary)' }"
          >
            {{ report.sum.sum_record_count }}
          </p>
        </div>
        <div class="text-right">
          <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }">
            {{ $t('records.winLoss.label.totalWinLoss') }}
          </p>
          <p
            class="font-bold tabular-nums"
            :style="{ fontSize: 'var(--c17-text-lg)', color: amountTone(report.sum.sum_win_lose) }"
          >
            {{ formatRecordAmount(report.sum.sum_win_lose) }}
          </p>
        </div>
      </section>

      <p
        v-if="loading"
        class="text-sm text-muted"
      >
        {{ $t('common.ui.label.loading') }}
      </p>

      <p
        v-else-if="loadFailed || vendors.length === 0"
        class="text-sm text-muted"
      >
        {{ $t('common.ui.empty.noData') }}
      </p>

      <div
        v-else
        class="overflow-x-auto rounded-(--c17-radius-sm) bg-surface shadow-[var(--c17-shadow-panel)]"
      >
        <table class="w-full text-(length:--c17-text-link) [&_td]:px-(--c17-gap-3) [&_td]:py-(--c17-gap-2) [&_th]:px-(--c17-gap-3) [&_th]:py-(--c17-gap-2)">
          <thead>
            <tr
              class="text-left font-bold"
              :style="{ background: 'var(--c17-primary-ink)', color: 'var(--c17-ink)' }"
            >
              <th>{{ $t('games.common.label.name') }}</th>
              <th class="text-right">
                {{ $t('records.winLoss.label.betCount') }}
              </th>
              <th class="text-right">
                {{ $t('records.bet.label.validBet') }}
              </th>
              <th class="text-right">
                {{ $t('records.winLoss.label.winLoss') }}
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-if="!report || report.items.length === 0">
              <td
                colspan="4"
                class="text-center text-muted"
              >
                {{ $t('common.ui.empty.noData') }}
              </td>
            </tr>
            <tr
              v-for="(row, index) in report?.items ?? []"
              :key="`${row.name}-${index}`"
            >
              <td class="font-semibold">
                {{ row.name }}
              </td>
              <td class="text-right tabular-nums">
                {{ row.record_count }}
              </td>
              <td class="text-right tabular-nums">
                {{ formatRecordAmount(row.valid_bet) }}
              </td>
              <td
                class="text-right font-bold tabular-nums"
                :style="{ color: amountTone(row.win_lose) }"
              >
                {{ formatRecordAmount(row.win_lose) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <RecordsPager
        v-model:page="page"
        :total="total"
        :per-page="perPage"
      />
    </div>
  </div>
</template>
