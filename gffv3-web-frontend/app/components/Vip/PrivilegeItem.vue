<script setup lang="ts">
import type { VipInfoReward } from '#shared/api/endpoints/vip'
import type { PrivilegeItem } from '#shared/utils/vip'
import { canClaimReward, rewardStatusKey } from '#shared/utils/vip'

defineProps<{
  item: PrivilegeItem
  busy?: boolean
}>()

const emit = defineEmits<{
  claim: [reward: VipInfoReward]
}>()
</script>

<template>
  <li class="flex items-center justify-between gap-4 rounded border border-line bg-surface p-3">
    <template v-if="item.kind === 'reward'">
      <span class="font-medium">{{ item.reward.name }}</span>
      <div class="text-right text-sm">
        <button
          v-if="canClaimReward(item.reward)"
          type="button"
          class="rounded bg-accent px-3 py-1 text-inverse disabled:opacity-50"
          :disabled="busy"
          @click="emit('claim', item.reward)"
        >
          {{ $t('events.claim.action.claim') }}
        </button>
        <span
          v-else
          class="text-muted"
        >
          {{ $t(rewardStatusKey(item.reward.status)) }}
        </span>
        <p
          v-if="canClaimReward(item.reward) && item.reward.expired_time"
          class="mt-1 text-xs text-muted"
        >
          {{ $t('vip.privilege.label.exchangeDate') }}: {{ item.reward.expired_time }}
        </p>
      </div>
    </template>
    <template v-else>
      <span class="font-medium">{{ $t(item.nameKey) }}</span>
      <span class="text-sm">{{ $t(item.descriptionKey) }}</span>
    </template>
  </li>
</template>
