<script setup lang="ts" generic="K extends string | number | null">
/**
 * C17 的膠囊分頁：選中是主色底白字，未選是白底淺框；超出寬度時整排橫向捲動。
 * 樣式與說明頁、VIP、錢包紀錄、通知中心那排分頁相同。
 */
defineProps<{
  items: { key: K, label: string }[]
}>()

const model = defineModel<K>({ required: true })
</script>

<template>
  <div
    class="-mx-(--c17-pad-x) flex gap-(--c17-gap-2) overflow-x-auto px-(--c17-pad-x) [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
    role="tablist"
  >
    <button
      v-for="item in items"
      :key="String(item.key)"
      type="button"
      role="tab"
      :aria-selected="model === item.key"
      class="flex h-8 shrink-0 items-center rounded-(--c17-field-radius) border px-(--c17-gap-3) font-bold"
      :style="{
        fontSize: 'var(--c17-text-link)',
        background: model === item.key ? 'var(--c17-primary)' : 'var(--c17-surface)',
        color: model === item.key ? 'var(--c17-primary-ink)' : 'var(--c17-ink)',
        borderColor: model === item.key ? 'var(--c17-primary)' : 'var(--c17-faint)',
      }"
      @click="model = item.key"
    >
      {{ item.label }}
    </button>
  </div>
</template>
