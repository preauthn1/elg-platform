<script setup lang="ts">
import { C17_LANGUAGES, languageFlag } from '#shared/constants/languages'

/**
 * 設計稿 Promo-pop out 最上面那排國旗晶片。V1 與 V2 的活動彈窗共用同一排。
 *
 * **切的是「這個彈窗的內容語言」，不是站台語系**：活動內容是後端依 Accept-Language 給的，
 * 選了就把語言記在 store.contentLocale、發 change 讓父層重抓；站台的 i18n 語系與 cookie 不動，
 * 換站台語系要從側邊欄。離開彈窗（卸載）就清回 null，其他頁照站台語系抓。
 *
 * 彈窗裡的固定文案（活動期間、按鈕…）也跟著換：彈窗內的元件用 useContentI18n 的 ct() 翻譯。
 */
const emit = defineEmits<{ change: [] }>()

const { locale } = useI18n()
const { ct } = useContentI18n()
const store = useEventsStore()

const selected = computed(() => store.contentLocale ?? locale.value)

const { $i18n } = useNuxtApp()

/** 語系包是 lazy 載入的：先載入，彈窗裡的 ct() 才翻得出那個語言，再寫 store 觸發重抓 */
async function choose(code: string) {
  if (code === selected.value) return
  await $i18n.loadLocaleMessages(code as typeof locale.value)
  store.contentLocale = code
  emit('change')
}

onBeforeUnmount(() => {
  store.contentLocale = null
})
</script>

<template>
  <div>
    <p
      class="mb-1"
      :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }"
    >
      {{ ct('site.language.label.language') }}
    </p>
    <div class="flex gap-2 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
      <button
        v-for="lang in C17_LANGUAGES"
        :key="lang.code"
        type="button"
        class="flex shrink-0 items-center gap-1 rounded-(--c17-radius-xs) border px-2 py-1 font-semibold"
        :style="{
          fontSize: 'var(--c17-text-label)',
          borderColor: lang.code === selected ? 'var(--c17-primary)' : 'var(--c17-line)',
          color: lang.code === selected ? 'var(--c17-primary)' : 'var(--c17-muted)',
        }"
        :aria-pressed="lang.code === selected"
        @click="choose(lang.code)"
      >
        <img
          :src="languageFlag(lang.code)"
          alt=""
          class="size-4 shrink-0 object-contain"
        >
        {{ lang.name }}
      </button>
    </div>
  </div>
</template>
