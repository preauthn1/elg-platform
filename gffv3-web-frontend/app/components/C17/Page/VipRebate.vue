<script setup lang="ts">
import { decFormat } from '#shared/utils/amount'
import { storeToRefs } from 'pinia'
import { API_CODE } from '#shared/constants/codes'
import {
  buildRebateTypes,
  filterRebateSummary,
  formatRebateProportion,
  gameTypeI18nKey,
  toAmount,
  weeklyRebateSummary,
} from '#shared/utils/vip'

const { t } = useI18n()
const { updating, ensureInfo, refresh } = useVip()
const store = useVipStore()
const { info, level, currentVip, nextVip } = storeToRefs(store)

const { data: loadResult } = await useAsyncData('vip-rebate', () => ensureInfo())
const loadFailed = computed(() => !!loadResult.value && loadResult.value.code !== API_CODE.OK)

const types = computed(() => buildRebateTypes(currentVip.value, nextVip.value))
const selectedTypeId = ref<number | null>(null)
watch(types, (list) => {
  if (selectedTypeId.value === null || !list.some(type => type.game_type_id === selectedTypeId.value)) {
    selectedTypeId.value = list[0]?.game_type_id ?? null
  }
}, { immediate: true })

const selectedType = computed(() => types.value.find(type => type.game_type_id === selectedTypeId.value))
const currentRebate = computed(() => formatRebateProportion(selectedType.value?.proportion))
const nextRebate = computed(() => formatRebateProportion(selectedType.value?.nextProportion))
const betList = computed(() => filterRebateSummary(info.value?.rebate_summary_records ?? [], selectedTypeId.value))
const weekly = computed(() => weeklyRebateSummary(info.value))

function typeLabel(gameTypeId: number): string {
  const key = gameTypeI18nKey(gameTypeId)
  return key ? t(key) : String(gameTypeId)
}

async function onRefresh() {
  await refresh()
}
</script>

<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <h1 class="text-xl font-semibold">
        {{ $t('vip.rebate.title.rebate') }}
      </h1>
      <NuxtLink
        to="/vip"
        class="text-sm text-accent"
      >
        {{ $t('vip.overview.title.vip') }}
      </NuxtLink>
    </div>

    <template v-if="info && currentVip">
      <div class="flex flex-wrap items-center gap-3">
        <button
          type="button"
          class="rounded bg-accent px-3 py-2 text-sm text-inverse disabled:opacity-50"
          :disabled="updating"
          @click="onRefresh"
        >
          {{ $t('vip.rebate.action.updateOrders') }}
        </button>
        <span
          v-if="updating"
          class="text-sm text-muted"
        >
          {{ $t('common.ui.label.loading') }}
        </span>
      </div>
      <p class="text-xs text-danger">
        {{ $t('vip.rebate.hint.differenceCredit') }}
      </p>

      <section class="grid grid-cols-1 gap-2 rounded border border-line bg-surface p-4 text-sm sm:grid-cols-2">
        <div>{{ $t('vip.rebate.label.weeklyValidBet') }}: <b>{{ decFormat(weekly.thisBet) }}</b></div>
        <div>{{ $t('agent.rebate.label.expected') }}: <b>{{ decFormat(weekly.thisRebate) }}</b></div>
        <div>{{ $t('vip.rebate.label.lastWeekValidBet') }}: <b>{{ decFormat(weekly.lastBet) }}</b></div>
        <div>{{ $t('vip.rebate.label.rebateIssued') }}: <b>{{ decFormat(weekly.lastRebate) }}</b></div>
      </section>

      <section
        v-if="types.length > 0"
        class="space-y-4"
      >
        <div class="flex flex-wrap gap-2">
          <button
            v-for="type in types"
            :key="type.game_type_id"
            type="button"
            class="rounded border px-3 py-2 text-sm"
            :class="type.game_type_id === selectedTypeId
              ? 'border-accent bg-accent text-inverse'
              : 'border-line bg-surface'"
            @click="selectedTypeId = type.game_type_id"
          >
            {{ typeLabel(type.game_type_id) }}
          </button>
        </div>

        <div class="rounded border border-line bg-surface p-4 text-sm">
          <h2 class="font-semibold">
            {{ $t('vip.rebate.title.depositAmount') }}
          </h2>
          <div class="mt-2 grid grid-cols-2 gap-2">
            <div class="rounded border border-line p-2 text-center">
              {{ $t('vip.rebate.label.currentRebate') }} {{ currentRebate }}
            </div>
            <div class="rounded border border-line p-2 text-center">
              {{ $t('vip.rebate.label.nextRebate') }} {{ nextRebate }}
            </div>
          </div>
          <div
            v-if="nextVip"
            class="mt-3"
          >
            <VipProgressBar
              :value="toAmount(info.deposit_amount)"
              :max="toAmount(nextVip.target_amount)"
              show-label
            />
            <p class="mt-1 text-xs text-muted">
              V{{ level }} · {{ decFormat(info.deposit_amount) }} / {{ decFormat(nextVip.target_amount) }}
            </p>
          </div>
        </div>

        <div class="overflow-x-auto rounded border border-line bg-surface">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-page text-left">
                <th class="p-2">
                  {{ $t('common.time.label.date') }}
                </th>
                <th class="p-2 text-right">
                  {{ $t('records.bet.label.validBet') }}
                </th>
                <th class="p-2 text-right">
                  {{ $t('vip.rebate.label.rebateRatio') }}
                </th>
                <th class="p-2 text-right">
                  {{ $t('vip.rebate.label.rebateAmount') }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(item, index) in betList"
                :key="index"
                class="border-t border-line"
              >
                <td class="p-2 whitespace-nowrap">
                  {{ item.summary_start_time }} ~ {{ item.summary_end_time }}
                </td>
                <td class="p-2 text-right">
                  {{ decFormat(item.bet, 2) }}
                </td>
                <td class="p-2 text-right">
                  {{ decFormat(item.proportion, 3) }} %
                </td>
                <td class="p-2 text-right">
                  {{ decFormat(item.rebate_amount, 4) }}
                </td>
              </tr>
            </tbody>
          </table>
          <p
            v-if="betList.length === 0"
            class="p-3 text-muted"
          >
            {{ $t('common.ui.empty.noData') }}
          </p>
        </div>
      </section>
      <p
        v-else
        class="text-muted"
      >
        {{ $t('common.ui.empty.noData') }}
      </p>
    </template>
    <p
      v-else-if="!loadFailed"
      class="text-muted"
    >
      {{ $t('common.ui.empty.noData') }}
    </p>
  </div>
</template>
