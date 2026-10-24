<script setup lang="ts">
// 動態元件要傳**元件本身**，不能傳 'NuxtLink' 字串——字串不會被解析，
// 會原樣渲染成 <nuxtlink> 自訂元素，沒有 href 也點不動。
import { NuxtLink } from '#components'
import { ACTIVITY_POPUPS, findAngpowEventId, type ActivityKey } from '~/utils/events-core'

/**
 * 四個每日活動彈窗（每日簽到／紅包／輪盤／敲蛋）共用的外殼。
 *
 * 這幾張不是一般的表單彈窗——標題與活動晶片在**白卡外面**、疊在遮罩上，
 * 所以不用 UiModal（它的標題列在卡片裡），自己組遮罩＋關閉鈕。
 *
 * 量到的值（畫板 390×844）：
 *   關閉鈕 39×40 @326,45
 *   圖示   60×60 @20,78；標題 Inter ExtraBold 16 全大寫 #F1F4F8 @92,98
 *   晶片   107×60 @20/141/262,148，混合模式 Luminosity
 *
 * 晶片列出的是**其他三個**活動，彼此互跳。四個都有頁面了，但 `to` 仍允許是 null——
 * 之後新增活動時，還沒做頁面的那一張會自動變成去色且不可點，不用改這支。
 */
const props = defineProps<{
  /** 目前這一張，用來決定圖示、標題與要列出哪三顆晶片 */
  activity: ActivityKey
  /** 關閉時退回哪裡 */
  fallback: string
  /** 後台設定的活動名稱；沒有時用該活動的預設標題 */
  title?: string
}>()

const current = computed(() => ACTIVITY_POPUPS[props.activity])

/**
 * 晶片列出的是**其他三個**活動。預覽模式互跳時把 `?demo=1` 帶著走，
 * 這樣連每日簽到那張（沒有預覽模式）也看得出是在預覽流程裡。
 *
 * 紅包例外：接的是真資料，網址帶 event/list 找到的紅包活動 id，站上沒有紅包活動就變灰不可點。
 */
const demo = useActivityDemo()
const events = useEvents()
const angpowId = computed(() => findAngpowEventId(events.events.value))
const chips = computed(() => Object.values(ACTIVITY_POPUPS)
  .filter(item => item.key !== props.activity)
  .map((item) => {
    if (item.key === 'redPacket') {
      return { ...item, to: angpowId.value === null ? null : `${item.to}?ids=${angpowId.value}` }
    }
    return { ...item, to: item.to && demo.value ? `${item.to}?demo=1` : item.to }
  }))

function close() {
  void backOr(props.fallback)
}

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') close()
}

onMounted(() => {
  window.addEventListener('keydown', onKey)
  if (events.events.value.length === 0) void events.listEvents()
})
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <div class="fixed inset-0 z-50 overflow-y-auto">
    <div
      class="absolute inset-0 bg-scrim"
      @click="close"
    />

    <div class="relative mx-auto flex w-full max-w-shell flex-col gap-(--c17-gap-3) px-(--c17-pad-x) pt-[45px] pb-(--c17-gap-6)">
      <button
        type="button"
        class="self-end"
        :aria-label="$t('common.ui.action.close')"
        @click="close"
      >
        <img
          src="/skins/c17/activity/close.webp"
          alt=""
          class="h-10 w-[39px]"
        >
      </button>

      <div class="flex items-center gap-(--c17-gap-3)">
        <img
          :src="`/skins/c17/activity/${current.icon}.webp`"
          alt=""
          class="size-[60px] shrink-0 object-contain"
        >
        <h1
          class="font-extrabold uppercase"
          :style="{
            fontFamily: 'var(--c17-font-display)',
            fontSize: 'var(--c17-text-title)',
            color: 'var(--c17-primary-ink)',
          }"
        >
          {{ title || $t(current.titleKey) }}
        </h1>
      </div>

      <ul class="flex gap-(--c17-gap-3)">
        <li
          v-for="chip in chips"
          :key="chip.key"
          class="min-w-0 flex-1"
        >
          <!--
            晶片互跳用 replace 不用 push：四張是同一層的分頁，不是一層一層點進去的。
            用 push 的話瀏覽記錄會疊一堆彈窗，關閉鈕（走上一頁）會變成在彈窗之間倒退，
            要按好幾次才回得到原本那一頁。
          -->
          <component
            :is="chip.to ? NuxtLink : 'span'"
            :to="chip.to ?? undefined"
            replace
            class="block"
          >
            <img
              :src="`/skins/c17/activity/${chip.chip}.webp`"
              :alt="$t(chip.titleKey)"
              class="block h-[60px] w-full object-contain"
              :class="chip.to ? '[mix-blend-mode:luminosity]' : 'grayscale'"
            >
          </component>
        </li>
      </ul>

      <slot />
    </div>
  </div>
</template>
