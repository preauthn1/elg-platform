<script setup lang="ts">
/**
 * 下拉選擇（設計稿 Wallet-page / Wallet-page_selection-open）。
 *
 *   收合  358×32 r8 白底，邊框 1pt：已選 #1BC964、未選 #B4B4B4
 *         圖示 22×22 @ 左 12、文字 PJS Bold 12 #1A1F36、箭頭 8×12 在右
 *   展開  面板 358×204 r8 白底、邊框 1pt #1565FF，貼在欄位下方 11
 *         每列 32 高，圖示 22×22、文字同上；超過 6 列就捲動
 *
 * 用原生 `<select>` 排不出每列的圖示，所以自己做一顆。選項的圖由後端給
 * （deposit 的 image_path / image），沒有就只排文字。
 */
export interface SelectOption {
  /** 送出用的值 */
  key: string | number
  label: string
  /** 選項左邊的圖示網址（後端給的），沒有就不留位置 */
  image?: string | null
}

const props = withDefaults(defineProps<{
  options: readonly SelectOption[]
  /** 欄位上方的小標；篩選列幾乎都要，所以做進元件不要每個呼叫端自己排 */
  label?: string
  placeholder?: string
  disabled?: boolean
  /** 面板最多顯示幾列，超過就捲動。設計稿是 6 */
  visibleRows?: number
}>(), {
  visibleRows: 6,
})

const model = defineModel<string | number | null>({ default: null })

const open = ref(false)
const root = ref<HTMLElement | null>(null)

const selected = computed(() => props.options.find(o => String(o.key) === String(model.value)) ?? null)

/** 面板高度 = 列數 × 32 + 上下各 5 的內距，與設計稿的 204（6 列）一致。 */
const panelMaxHeight = computed(() => `${props.visibleRows * 32 + 10}px`)

function choose(option: SelectOption) {
  model.value = option.key
  open.value = false
}

function onDocumentPointerDown(event: Event) {
  if (!root.value?.contains(event.target as Node)) open.value = false
}

onMounted(() => document.addEventListener('pointerdown', onDocumentPointerDown))
onBeforeUnmount(() => document.removeEventListener('pointerdown', onDocumentPointerDown))
</script>

<template>
  <div ref="root">
    <p
      v-if="label"
      class="mb-1"
      :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }"
    >
      {{ label }}
    </p>
    <!-- 展開面板是絕對定位，基準要是欄位本身，不能含小標，不然會多掉一行的高度 -->
    <div class="relative">
      <button
        type="button"
        class="flex h-(--c17-field-h) w-full items-center gap-(--c17-gap-3) rounded-(--c17-field-radius) border bg-surface px-(--c17-gap-3) disabled:opacity-50"
        :class="selected ? 'border-field-line-done' : 'border-field-line'"
        :disabled="disabled"
        :aria-expanded="open"
        aria-haspopup="listbox"
        @click="open = !open"
      >
        <img
          v-if="selected?.image"
          :src="selected.image"
          alt=""
          class="size-[22px] shrink-0 rounded-(--c17-radius-xs) object-contain"
        >
        <span
          class="min-w-0 flex-1 truncate text-left font-bold"
          :style="{
            fontSize: 'var(--c17-text-link)',
            color: selected ? 'var(--c17-ink)' : 'var(--c17-faint)',
          }"
        >{{ selected?.label ?? placeholder ?? '' }}</span>
        <svg
          viewBox="0 0 12 8"
          class="h-2 w-3 shrink-0"
          fill="currentColor"
          :style="{ color: 'var(--c17-ink)' }"
          aria-hidden="true"
        >
          <path d="M0 1.9 1.4.5 6 5.1 10.6.5 12 1.9 6 7.9z" />
        </svg>
      </button>

      <ul
        v-if="open"
        class="absolute inset-x-0 top-[calc(100%+11px)] z-20 overflow-y-auto rounded-(--c17-field-radius) border bg-surface py-[5px]"
        :style="{ maxHeight: panelMaxHeight, borderColor: 'var(--c17-primary)' }"
        role="listbox"
      >
        <li
          v-for="option in options"
          :key="option.key"
        >
          <button
            type="button"
            class="flex h-8 w-full items-center gap-(--c17-gap-3) px-(--c17-gap-3) text-left"
            role="option"
            :aria-selected="String(option.key) === String(model)"
            @click="choose(option)"
          >
            <img
              v-if="option.image"
              :src="option.image"
              alt=""
              class="size-[22px] shrink-0 rounded-(--c17-radius-xs) object-contain"
            >
            <span
              class="min-w-0 flex-1 truncate font-bold"
              :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
            >{{ option.label }}</span>
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>
