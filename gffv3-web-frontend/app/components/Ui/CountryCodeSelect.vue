<script setup lang="ts">
import type { CountryItem } from '#shared/api/endpoints/site'

/**
 * 手機國碼下拉。設計稿國碼欄是固定 140 寬的獨立方塊（--c17-field-action-w），
 * 字是 12 粗體，選項寫成「MY +60」，不是跟著內容縮、也不是「+60 馬來西亞」
 * （國名長的語系會撐破）。註冊、忘記密碼、綁定手機共用這一份。
 */
const model = defineModel<string>({ required: true })

defineProps<{
  countries: readonly CountryItem[]
  disabled?: boolean
}>()
</script>

<template>
  <select
    v-model="model"
    class="h-(--c17-field-h) w-(--c17-field-action-w) shrink-0 rounded-(--c17-field-radius) border border-field-line bg-field px-2 font-bold text-ink disabled:bg-disabled disabled:text-disabled-ink"
    :style="{ fontSize: 'var(--c17-text-link)' }"
    :aria-label="$t('auth.common.label.countryCode')"
    :disabled="disabled"
  >
    <option
      v-for="c in countries"
      :key="c.code"
      :value="c.country_code"
    >
      {{ c.code }} +{{ c.country_code }}
    </option>
  </select>
</template>
