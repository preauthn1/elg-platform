<script setup lang="ts">
/** C17 的畫面，邏輯在 useAboutPage。 */
const {
  site,
  titles,
} = await useAboutPage()
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-xl font-semibold">
      {{ $t('site.about.title.about') }}
    </h1>
    <img
      v-if="site?.raw.logo_mobile"
      :src="site.raw.logo_mobile"
      alt=""
      class="max-h-24 object-contain"
    >
    <ul class="divide-y divide-line rounded border border-line bg-surface">
      <li
        v-for="(titleKey, index) in titles"
        :key="titleKey"
      >
        <NuxtLink
          :to="`/about/${index}`"
          class="flex items-center justify-between px-4 py-3"
        >
          <span>{{ $t(titleKey) }}</span>
          <span aria-hidden="true">›</span>
        </NuxtLink>
      </li>
    </ul>
  </div>
</template>
