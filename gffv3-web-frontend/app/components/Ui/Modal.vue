<script setup lang="ts">
/**
 * 置底浮出的彈窗。C17 的登入／註冊／活動都是這個形狀：
 * 半透明遮罩 + 白色圓角面板 + 標題列（左標題、右關閉）。
 *
 * 關閉一律由呼叫端決定要導去哪（@close），元件本身不碰路由。
 */
withDefaults(defineProps<{
  title?: string
  /** 點遮罩是否關閉；表單類建議關掉避免誤觸 */
  dismissible?: boolean
  /**
   * 卡片撐到跟設計稿一樣高。設計稿 login / register / login-reset-pw 四張的卡都是
   * [16,45 358×754]（844 的畫板上下各留 45），而且「還沒有帳號？立即註冊」這種頁尾
   * 是**貼在卡片底部**，不是緊接著按鈕。內容短的頁面不要開，不然會空一大塊。
   */
  fill?: boolean
  /**
   * 卡片外框色。設計稿同一個 `Rectangle 1821` 在不同彈窗用不同顏色：
   * login／register 是 `#1565FF`（primary），VIP 等級說明是 `#D8FF1A`（highlight）。
   * 兩者都是 2pt 內框，所以只有顏色要選。
   */
  accent?: 'primary' | 'highlight'
}>(), {
  dismissible: true,
  accent: 'primary',
})

const emit = defineEmits<{ close: [] }>()

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('close')
}

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <!-- 卡片本身在畫面上左右各留 16（設計稿卡片 x=16）；卡片**裡面**的內距才是 18 -->
  <div class="fixed inset-0 z-50 flex items-center justify-center px-(--c17-pad-x)">
    <!--
      進場動畫做在彈窗自己身上，呼叫端什麼都不用包：遮罩淡入、卡片同時往上 10，
      照 bc.game 的 dialog（220ms，起步快尾巴長的那條曲線）。
      退場沒有動畫——呼叫端的 v-if 一關這棵樹就沒了，bc.game 也只做進場。
    -->
    <div
      class="wf-in-fade absolute inset-0 bg-scrim"
      @click="dismissible && emit('close')"
    />
    <div
      role="dialog"
      aria-modal="true"
      class="wf-in-slide-up relative flex w-full max-w-md flex-col overflow-hidden rounded-(--c17-field-radius) border-2 bg-surface"
      :class="[
        fill ? 'h-[89.3vh]' : 'max-h-[88vh]',
        accent === 'highlight' ? 'border-highlight' : 'border-accent',
      ]"
      :style="{ boxShadow: 'var(--c17-shadow-raised)' }"
    >
      <header class="flex items-center justify-between gap-2 px-(--c17-modal-pad) pt-(--c17-gap-4) pb-(--c17-gap-3)">
        <div class="flex min-w-0 items-center gap-(--c17-gap-2)">
          <h2
            v-if="title"
            class="truncate font-extrabold uppercase"
            :style="{ fontFamily: 'var(--c17-font-display)', fontSize: 'var(--c17-text-title)' }"
          >
            {{ title }}
          </h2>
          <!-- 標題右邊的動作（例如錢包面板的重新整理），沒有就不佔位 -->
          <slot name="title-action" />
        </div>
        <button
          type="button"
          class="-mr-1 flex size-8 items-center justify-center text-ink"
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
      <div class="flex min-h-0 flex-1 flex-col overflow-y-auto px-(--c17-modal-pad) pb-(--c17-modal-pad)">
        <slot />
      </div>
    </div>
  </div>
</template>
