<script setup lang="ts">
/**
 * 從右側滑出的抽屜。設計稿的 Menu 是這個形狀：
 * 蓋住畫面右側約七成，左邊仍看得到底下的頁面，右上角一個關閉。
 *
 * **開關由 `open` prop 控制，內容一直掛著。** 不要用 v-if 切它：
 * 每開一次就重建一次，裡面的 useAsyncData／ensure* 全部重跑，清單會先少幾列再閃出來，
 * 而且關閉時瞬間消失、沒有滑出（實際量過：每次展開重打 4 支 API，6 列 → 10 列閃一下）。
 *
 * 進場／離場都是 200ms：遮罩淡入淡出、抽屜從右側滑入滑出。
 * 關閉後等動畫跑完才 v-show 隱藏，滑出才看得到；隱藏期間 inert，鍵盤與讀屏都摸不到。
 */
const props = withDefaults(defineProps<{
  /** 開／關。預設 true 是為了「掛上去就打開」的舊用法 */
  open?: boolean
  title?: string
  /** 點遮罩是否關閉 */
  dismissible?: boolean
  /** 抽屜寬度上限，預設照設計稿 */
  widthClass?: string
}>(), {
  open: true,
  dismissible: true,
  // 設計稿 Menu：抽屜 [118,0 272x844]，390 寬的畫面上佔 272 → 69.7%
  widthClass: 'w-[69.7%] max-w-sm',
})

const emit = defineEmits<{ close: [] }>()

const DURATION_MS = 200

const root = ref<HTMLElement | null>(null)
/** 打開前的焦點（通常是頂部列的選單鈕），關閉時還給它 */
let opener: HTMLElement | null = null

/**
 * 關閉前先把焦點移出抽屜。焦點留在裡面（例如剛點的選單連結）時，
 * 接著套上的 aria-hidden 會被瀏覽器擋下並警告，讀屏也會停在看不見的元素上。
 * watch 預設 flush: 'pre'，這裡跑在 DOM 更新之前，所以要在這裡移。
 */
function releaseFocus() {
  const active = document.activeElement
  if (!(active instanceof HTMLElement) || !root.value?.contains(active)) return
  if (opener?.isConnected) opener.focus()
  else active.blur()
}

/** 元素在不在 DOM 裡（離場動畫跑完才拿掉） */
const visible = ref(props.open)
/** 動畫的目標狀態：true = 滑入定位、遮罩全亮 */
const shown = ref(false)
let hideTimer: ReturnType<typeof setTimeout> | null = null

watch(() => props.open, (next) => {
  if (hideTimer) {
    clearTimeout(hideTimer)
    hideTimer = null
  }
  if (next) {
    opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
    visible.value = true
    // 先以「收起」狀態畫一幀，下一幀再切到定位，transition 才有起點
    nextTick(() => requestAnimationFrame(() => {
      shown.value = true
    }))
  }
  else {
    releaseFocus()
    shown.value = false
    hideTimer = setTimeout(() => {
      visible.value = false
    }, DURATION_MS)
  }
}, { immediate: false })

/** 掛載時若是開的，同樣先畫一幀再滑入（舊的「掛上去就打開」用法） */
onMounted(() => {
  if (props.open) {
    requestAnimationFrame(() => {
      shown.value = true
    })
  }
})

function onKey(e: KeyboardEvent) {
  if (props.open && e.key === 'Escape') emit('close')
}

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  if (hideTimer) clearTimeout(hideTimer)
})
</script>

<template>
  <div
    v-show="visible"
    ref="root"
    class="fixed inset-0 z-50 flex justify-end"
    :inert="!open || undefined"
    :aria-hidden="!open || undefined"
  >
    <div
      class="absolute inset-0 bg-scrim transition-opacity duration-200"
      :class="shown ? 'opacity-100' : 'opacity-0'"
      @click="dismissible && emit('close')"
    />
    <aside
      role="dialog"
      aria-modal="true"
      class="relative flex h-full flex-col transition-transform duration-200 ease-out"
      :class="[widthClass, shown ? 'translate-x-0' : 'translate-x-full']"
      :style="{ background: 'var(--c17-drawer-bg)', boxShadow: 'var(--c17-shadow-raised)' }"
    >
      <header class="flex items-center justify-between gap-2 px-(--c17-drawer-pad) pt-(--c17-gap-4) pb-(--c17-gap-3)">
        <!-- 設計稿 menu 左上是 90×34 的站台 logo；沒給 slot 時退回站名文字 -->
        <h2
          class="min-w-0 flex-1 truncate font-extrabold"
          :style="{ fontFamily: 'var(--c17-font-display)', fontSize: 'var(--c17-text-title)' }"
        >
          <slot name="title">
            {{ title }}
          </slot>
        </h2>
        <button
          type="button"
          class="-mr-1 flex size-8 shrink-0 items-center justify-center text-ink"
          :aria-label="$t('common.ui.action.close')"
          @click="emit('close')"
        >
          <svg
            viewBox="0 0 24 24"
            class="size-5"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
          >
            <path d="M5 5l14 14M19 5L5 19" />
          </svg>
        </button>
      </header>
      <div class="min-h-0 flex-1 overflow-y-auto px-(--c17-drawer-pad) pb-(--c17-gap-6)">
        <slot />
      </div>
    </aside>
  </div>
</template>
