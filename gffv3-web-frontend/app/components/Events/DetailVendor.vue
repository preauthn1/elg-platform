<script setup lang="ts">
import type { LegacyEventDetailRow } from '#shared/api/endpoints/events-core'

/**
 * V1 活動達成條件的「指定遊戲商」與「遊戲類型」兩列（舊皮 EventItem／EventLoseRebateItem）。
 * 遊戲商維護中不顯示「進入遊戲」。
 */
const props = defineProps<{
  detail: LegacyEventDetailRow
  /** 導頁時帶遊戲類型（只有輸分回扣這樣做：舊皮 $pushVendor(vendor, detail.game_type_id)） */
  withGameType?: boolean
}>()

const games = useGames()

/**
 * 進入遊戲：維護中不導頁，其餘照一般進場分流。
 * 查場館**只帶 vendor_id**（舊皮 actionVendor 三處都是）；帶 type_id 後端會回空陣列。
 */
async function toVendor() {
  const { vendor_id: vendorId, game_type_id: typeId, vendor_maintenances: maintenances } = props.detail
  if (!vendorId || Number(maintenances) === 1) return
  const res = await games.fetchVendor(Number(vendorId))
  if (isApiOk(res) && res.data) await games.goToVendor(res.data, props.withGameType ? (typeId ?? null) : null)
}
</script>

<template>
  <p
    v-if="detail.vendor_name"
    class="flex flex-wrap items-center gap-x-(--c17-gap-2) gap-y-1"
    :style="{ fontSize: 'var(--c17-text-label)' }"
  >
    <span :style="{ color: 'var(--c17-primary)' }">{{ $t('events.detail.label.designatedGamer') }} :</span>
    <span
      class="font-semibold"
      :style="{ color: 'var(--c17-ink)' }"
    >{{ detail.vendor_name }}</span>
    <button
      v-if="Number(detail.vendor_maintenances) === 0"
      type="button"
      class="flex h-8 items-center rounded-(--c17-field-radius) border border-accent px-(--c17-gap-3) font-bold"
      :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-primary)' }"
      @click="toVendor"
    >
      {{ $t('games.launch.action.enterGame') }}
    </button>
  </p>

  <p
    v-if="detail.game_type_id"
    class="flex flex-wrap items-baseline gap-x-(--c17-gap-2)"
    :style="{ fontSize: 'var(--c17-text-label)' }"
  >
    <span :style="{ color: 'var(--c17-primary)' }">{{ $t('games.common.label.category') }} :</span>
    <span
      class="font-semibold"
      :style="{ color: 'var(--c17-ink)' }"
    >{{ detail.game_type_name }}</span>
  </p>
</template>
