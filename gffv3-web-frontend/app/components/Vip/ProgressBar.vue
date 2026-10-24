<script setup lang="ts">
import { progressPercent } from '#shared/utils/vip'

const props = withDefaults(defineProps<{
  value: number
  max: number
  min?: number
  showLabel?: boolean
}>(), { min: 0, showLabel: false })

const percent = computed(() => progressPercent(props.value, props.min, props.max))
</script>

<template>
  <div>
    <div
      class="h-2 w-full overflow-hidden rounded bg-line"
      role="progressbar"
      aria-valuemin="0"
      aria-valuemax="100"
      :aria-valuenow="Math.round(percent)"
    >
      <div
        class="h-full rounded bg-accent"
        :style="{ width: `${percent}%` }"
      />
    </div>
    <div
      v-if="showLabel"
      class="mt-1 text-xs text-muted"
    >
      {{ percent.toFixed(2) }}%
    </div>
  </div>
</template>
