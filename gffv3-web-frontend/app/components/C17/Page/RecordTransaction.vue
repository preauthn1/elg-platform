<script setup lang="ts">
import type { SelectOption } from '~/components/Ui/SelectField.vue'
import { formatRecordAmount } from '~/utils/records'

/** C17 交易紀錄。資料在 useTransactionRecordList，這裡只有下拉選單的選項格式。 */
const {
  t,
  tx,
  rangePresets,
  selectedRangeKey,
  selectedType,
  selectedCurrency,
  currencyOptions,
  filteredLogs,
  loading,
  loadFailed,
  labelOf,
} = useTransactionRecordList()

const currencySelectOptions = computed<SelectOption[]>(() => [
  { key: '', label: t('records.filter.option.allCurrencies') },
  ...currencyOptions.value.map(c => ({ key: c, label: c })),
])

const typeSelectOptions = computed<SelectOption[]>(() =>
  tx.typeOptions.value.map(o => ({ key: o.type, label: o.labelKey ? t(o.labelKey) : o.label })),
)

const rangeSelectOptions = computed<SelectOption[]>(() =>
  rangePresets.value.map(p => ({ key: p.key, label: p.labelKey ? t(p.labelKey) : p.label })),
)
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('records.transaction.title.record')"
      :fallback="'/record'"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <div class="flex flex-col gap-(--c17-gap-3)">
        <div class="grid grid-cols-2 gap-x-(--c17-gap-3)">
          <UiSelectField
            v-model="selectedCurrency"
            :label="$t('common.amount.label.currency')"
            :options="currencySelectOptions"
          />
          <UiSelectField
            v-model="selectedRangeKey"
            :label="$t('common.time.label.date')"
            :options="rangeSelectOptions"
          />
        </div>
        <UiSelectField
          v-model="selectedType"
          :label="$t('common.filter.label.type')"
          :options="typeSelectOptions"
        />
      </div>

      <p
        v-if="loading"
        class="text-sm text-muted"
      >
        {{ $t('common.ui.label.loading') }}
      </p>

      <p
        v-else-if="loadFailed || filteredLogs.length === 0"
        class="text-sm text-muted"
      >
        {{ $t('common.ui.empty.noData') }}
      </p>

      <ul
        v-else
        class="divide-y divide-line overflow-hidden rounded-(--c17-radius-sm) bg-surface shadow-[var(--c17-shadow-panel)]"
      >
        <li
          v-for="(item, index) in filteredLogs"
          :key="`${item.created_at}-${index}`"
          class="flex items-center gap-3 px-(--c17-gap-3) py-(--c17-gap-2) text-(length:--c17-text-link)"
        >
          <img
            v-if="tx.iconKindOf(item) === 'vendor' && item.vendor_image"
            :src="item.vendor_image"
            alt=""
            class="size-[26px] shrink-0 rounded-(--c17-radius-xs) object-contain"
          >
          <span
            v-else
            class="grid size-[26px] shrink-0 place-items-center rounded-(--c17-radius-xs) font-bold uppercase"
            :style="{ background: 'var(--c17-primary-ink)', color: 'var(--c17-primary)', fontSize: 'var(--c17-text-label)' }"
          >{{ (tx.iconKindOf(item) ?? '').slice(0, 1) }}</span>
          <div class="mr-auto min-w-0">
            <div class="truncate font-semibold">
              {{ labelOf(item) }}
            </div>
            <div class="text-(length:--c17-text-label) text-muted">
              {{ item.created_at }}
            </div>
          </div>
          <div class="shrink-0 text-right">
            <div class="text-(length:--c17-text-label) text-muted">
              {{ tx.amountLabelOf(item) }}
            </div>
            <div
              class="font-bold tabular-nums"
              :style="{ color: 'var(--c17-primary)' }"
            >
              {{ formatRecordAmount(item.point) }}
            </div>
          </div>
        </li>
      </ul>
    </div>
  </div>
</template>
