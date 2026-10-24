<script setup lang="ts">
import type { VipContent } from '#shared/api/endpoints/vip'
import type { DisplayValue } from '#shared/utils/vip'
import {
  describeKeepAmount,
  describeRebateProportion,
  describeTargetAmount,
  describeWithdrawalAmountLimit,
  describeWithdrawalTimesLimit,
  findRewardPoint,
} from '#shared/utils/vip'
import { isOn } from '#shared/utils/site'

const props = defineProps<{
  /** 已依 level 升冪排序的 vip/contents。 */
  contents: VipContent[]
  /** bonus = 各等級獎勵金額與返水比例；rules = 晉級／保級／提領限制。 */
  mode: 'bonus' | 'rules'
}>()

const { t } = useI18n()

function show(value: DisplayValue): string {
  return 'i18n' in value ? t(value.i18n) : value.text
}

/** 所有等級出現過的獎勵種類（vip_rewards.id → 名稱），依 id 排序當欄位。 */
const rewardColumns = computed(() => {
  const map = new Map<number, string>()
  for (const content of props.contents) {
    for (const reward of content.reward) {
      const id = Number(reward.id)
      if (!map.has(id)) map.set(id, reward.name)
    }
  }
  return [...map.entries()]
    .sort((a, b) => a[0] - b[0])
    .map(([id, name]) => ({ id, name }))
})

function supportOrderTypes(content: VipContent): string {
  return content.support_order_type.map(item => item.name).join(', ') || '-'
}
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full border-collapse text-sm">
      <thead>
        <tr
          v-if="mode === 'bonus'"
          class="bg-page text-left"
        >
          <th class="border border-line p-2">
            {{ $t('vip.common.label.level') }}
          </th>
          <th
            v-for="column in rewardColumns"
            :key="column.id"
            class="border border-line p-2"
          >
            {{ column.name }}
          </th>
          <th class="border border-line p-2">
            {{ $t('vip.rebate.label.rebateRatio') }} ({{ $t('games.enum.category.reality') }} / {{ $t('games.enum.category.slot') }})
          </th>
        </tr>
        <tr
          v-else
          class="bg-page text-left"
        >
          <th class="border border-line p-2">
            {{ $t('vip.common.label.level') }}
          </th>
          <th class="border border-line p-2">
            {{ $t('vip.condition.label.upgradeRequirement') }}
          </th>
          <th class="border border-line p-2">
            {{ $t('vip.condition.label.retentionRequirement') }}
          </th>
          <th class="border border-line p-2">
            {{ $t('vip.condition.label.dailyTransactionCount') }}
          </th>
          <th class="border border-line p-2">
            {{ $t('vip.condition.label.dailyWithdrawalLimit') }}
          </th>
          <th class="border border-line p-2">
            {{ $t('withdrawal.form.label.type') }}
          </th>
          <th class="border border-line p-2">
            {{ $t('vip.privilege.label.specialService') }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="content in contents"
          :key="content.id"
        >
          <td class="border border-line p-2 whitespace-nowrap">
            V{{ content.level }} {{ content.name }}
          </td>
          <template v-if="mode === 'bonus'">
            <td
              v-for="column in rewardColumns"
              :key="column.id"
              class="border border-line p-2"
            >
              {{ show(findRewardPoint(content, column.id)) }}
            </td>
            <td class="border border-line p-2">
              {{ show(describeRebateProportion(content)) }}
            </td>
          </template>
          <template v-else>
            <td class="border border-line p-2">
              {{ show(describeTargetAmount(content)) }}
            </td>
            <td class="border border-line p-2">
              {{ show(describeKeepAmount(content)) }}
            </td>
            <td class="border border-line p-2">
              {{ show(describeWithdrawalTimesLimit(content)) }}
            </td>
            <td class="border border-line p-2">
              {{ show(describeWithdrawalAmountLimit(content)) }}
            </td>
            <td class="border border-line p-2">
              {{ supportOrderTypes(content) }}
            </td>
            <td class="border border-line p-2">
              {{ isOn(content.vip_channel) ? $t('vip.privilege.hint.priorityService') : $t('common.ui.label.none') }}
            </td>
          </template>
        </tr>
      </tbody>
    </table>
    <p
      v-if="contents.length === 0"
      class="p-2 text-muted"
    >
      {{ $t('common.ui.empty.noData') }}
    </p>
  </div>
</template>
