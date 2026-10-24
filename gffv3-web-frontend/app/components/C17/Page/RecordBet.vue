<script setup lang="ts">
import type { SelectOption } from '~/components/Ui/SelectField.vue'
import { formatRecordAmount } from '~/utils/records'

/** C17 投注紀錄。資料在 useBetRecordList，這裡只有下拉選單的選項格式。 */
const {
  dayPresets,
  selectedDayKey,
  t,
  page,
  perPage,
  loading,
  loadFailed,
  list,
  totalCount,
} = useBetRecordList()

const dayOptions = computed<SelectOption[]>(() =>
  dayPresets.value.map(p => ({ key: p.key, label: p.labelKey ? t(p.labelKey) : p.label })),
)
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('records.tab.nav.bet')"
      :fallback="'/record'"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <UiSelectField
        v-model="selectedDayKey"
        :label="$t('common.time.label.date')"
        :options="dayOptions"
      />

      <p
        v-if="loading"
        class="text-sm text-muted"
      >
        {{ $t('common.ui.label.loading') }}
      </p>

      <p
        v-else-if="loadFailed || list.length === 0"
        class="text-sm text-muted"
      >
        {{ $t('common.ui.empty.noData') }}
      </p>

      <ul
        v-else
        class="divide-y divide-line overflow-hidden rounded-(--c17-radius-sm) bg-surface shadow-[var(--c17-shadow-panel)]"
      >
        <li
          v-for="(record, index) in list"
          :key="`${record.name}-${record.summary_time}-${index}`"
          class="flex items-center gap-3 px-(--c17-gap-3) py-(--c17-gap-2) text-(length:--c17-text-link)"
        >
          <img
            v-if="record.image"
            :src="record.image"
            alt=""
            class="size-[26px] shrink-0 rounded-(--c17-radius-xs) object-contain"
          >
          <div class="mr-auto min-w-0">
            <div class="truncate font-semibold">
              {{ record.name }}
            </div>
            <div class="text-(length:--c17-text-label) text-muted">
              {{ record.summary_time }}
            </div>
          </div>
          <div class="shrink-0 text-right">
            <div class="text-(length:--c17-text-label) text-muted">
              {{ $t('common.amount.label.betAmount') }}
            </div>
            <div
              class="font-bold tabular-nums"
              :style="{ color: 'var(--c17-primary)' }"
            >
              {{ formatRecordAmount(record.total_valid_bet) }}
            </div>
          </div>
        </li>
      </ul>

      <RecordsPager
        v-model:page="page"
        :total="totalCount"
        :per-page="perPage"
      />
    </div>
  </div>
</template>
