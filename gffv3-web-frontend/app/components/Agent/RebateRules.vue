<script setup lang="ts">
import { formatProportion } from '#shared/utils/agent'

/**
 * 三級分銷返利規則（three-hierarchy/rule）：以遊戲類型當分頁籤，
 * 同籤內依自身比例倒序，三個比例欄顯示 %。第一欄的遊戲商圖示走 UiTable 的 #cell。
 */
const { t } = useI18n()
const { ruleGameTypes, selectedRuleGameType, ruleItemsOfSelected, fetchRule } = useAgentCenter()
const failed = ref(false)

const FIELDS = ['proportion_1', 'proportion_2', 'proportion_3'] as const

const typeItems = computed(() => ruleGameTypes.value.map(gameType => ({ key: gameType, label: gameType })))
const columns = computed(() => [
  t('games.vendor.label.vendor'),
  t('agent.rebate.label.ownRebate'),
  t('agent.rebate.label.uplineRebate'),
  t('agent.rebate.label.upperUplineRebate'),
])
const rows = computed(() => ruleItemsOfSelected.value.map(item => [
  String(item.vendor_name ?? ''),
  ...FIELDS.map(field => formatProportion(item[field])),
]))

onMounted(async () => {
  const res = await fetchRule()
  failed.value = !isApiOk(res)
})
</script>

<template>
  <section class="flex flex-col gap-(--c17-gap-3)">
    <UiPillTabs
      v-if="ruleGameTypes.length"
      v-model="selectedRuleGameType"
      :items="typeItems"
    />

    <UiTable
      v-if="ruleGameTypes.length || !failed"
      :columns="columns"
      :rows="rows"
    >
      <template #cell="{ value, rowIndex, columnIndex }">
        <span
          v-if="columnIndex === 0"
          class="flex flex-col items-center gap-(--c17-gap-1)"
        >
          <img
            v-if="ruleItemsOfSelected[rowIndex]?.vendor_image"
            :src="ruleItemsOfSelected[rowIndex]!.vendor_image!"
            alt=""
            class="h-6 w-12 object-contain"
          >
          {{ value }}
        </span>
        <template v-else>
          {{ value }}
        </template>
      </template>
    </UiTable>
  </section>
</template>
