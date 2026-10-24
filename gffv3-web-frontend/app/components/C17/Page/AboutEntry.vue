<script setup lang="ts">
/** C17 的畫面，邏輯在 useAboutEntryPage。 */
const {
  site,
  current,
  ready,
  html,
} = await useAboutEntryPage()
</script>

<template>
  <div class="space-y-4">
    <NuxtLink
      to="/about"
      class="text-sm text-accent"
    >
      ‹ {{ $t('site.about.title.about') }}
    </NuxtLink>
    <template v-if="current">
      <h1 class="text-xl font-semibold">
        {{ $t(current.titleKey) }}
      </h1>
      <img
        v-if="site?.raw.logo_mobile"
        :src="site.raw.logo_mobile"
        alt=""
        class="max-h-24 object-contain"
      >
      <!-- 舊文案含 HTML 標記（i18n 已設 escapeHtml: false），只呈現後台／語言檔內容 -->
      <!-- eslint-disable vue/no-v-html -->
      <div
        v-if="ready"
        class="whitespace-pre-line rounded border border-line bg-surface p-4 text-sm"
        v-html="html"
      />
      <!-- eslint-enable vue/no-v-html -->
      <p
        v-else
        class="text-muted"
      >
        {{ $t('common.ui.label.loading') }}
      </p>
    </template>
    <p
      v-else
      class="text-muted"
    >
      {{ $t('common.ui.empty.noData') }}
    </p>
  </div>
</template>
