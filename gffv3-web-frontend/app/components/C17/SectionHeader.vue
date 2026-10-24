<script setup lang="ts">
/** 區塊標題列：左標題、右 View All。右側連結沒給就不顯示。 */
defineProps<{
  title: string
  to?: string
}>()
</script>

<template>
  <div
    class="flex items-baseline gap-[var(--c17-gap-2)]"
    :style="{ paddingInline: 'var(--c17-pad-x)' }"
  >
    <h2
      class="font-extrabold uppercase"
      :style="{ fontFamily: 'var(--c17-font-display)', fontSize: 'var(--c17-text-title)', color: 'var(--c17-ink)' }"
    >
      {{ title }}
    </h2>
    <slot name="meta" />
    <div class="flex-1" />
    <NuxtLink
      v-if="to"
      :to="to"
      class="flex shrink-0 items-center gap-[2px] font-bold"
      :style="{ fontFamily: 'var(--c17-font-label)', fontSize: 'var(--c17-text-link)', color: 'var(--c17-primary)' }"
    >
      {{ $t('site.nav.action.viewAll') }}
      <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2.5"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="size-3"
        aria-hidden="true"
      >
        <path d="m9 6 6 6-6 6" />
      </svg>
    </NuxtLink>
  </div>
</template>
