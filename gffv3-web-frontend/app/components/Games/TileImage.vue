<script setup lang="ts">
/**
 * 遊戲方形縮圖。後端給的遊戲圖是 300×300、每張 150～200KB 的 PNG，
 * 直接放 <img> 會看到瀏覽器邊下載邊由上往下畫，出現「上半張有圖、下半張空白」。
 *
 * 掛載後還沒載完的圖先隱藏、蓋骨架，載完才淡入；載入失敗就一直留著骨架，不露出破圖。
 *
 * **SSR 輸出時圖一定是可見的**：hydration 之前 JS 還沒跑，若伺服器就輸出 opacity-0，
 * 圖早就下載完也要等 JS 才看得到（慢網路下會空白好幾秒）。所以 hydration 前只靠底色墊著，
 * 掛載後才接手隱藏與淡入。
 *
 * 首屏的幾張傳 eager：lazy 要等版面算完才開始抓，首屏看得到的圖不該排在後面。
 */
const props = defineProps<{
  src: string
  alt?: string
  eager?: boolean
}>()

const img = ref<HTMLImageElement | null>(null)
const mounted = ref(false)
const loaded = ref(false)
/** 快取命中的圖幾乎立刻載完，交給 useSkeleton 的延遲判斷就不會閃一下骨架 */
const showSkeleton = useSkeleton(() => mounted.value && !loaded.value)

function isComplete(el: HTMLImageElement | null): boolean {
  return !!el?.complete && el.naturalWidth > 0
}

/** SSR 輸出的圖可能在 hydration 前就載完，load 事件已經錯過，掛載時要自己補看一次。 */
onMounted(() => {
  loaded.value = isComplete(img.value)
  mounted.value = true
})

watch(() => props.src, () => {
  loaded.value = false
})
</script>

<template>
  <span
    class="relative block aspect-square w-full overflow-hidden rounded-[var(--c17-radius-sm)]"
    :style="{ background: 'var(--c17-tile-bg)', boxShadow: 'var(--c17-shadow-category)' }"
  >
    <UiSkeleton
      v-if="showSkeleton"
      class="absolute inset-0"
    />
    <img
      ref="img"
      :src="src"
      :alt="alt ?? ''"
      class="absolute inset-0 size-full object-cover transition-opacity duration-200"
      :class="mounted && !loaded ? 'opacity-0' : 'opacity-100'"
      :loading="eager ? 'eager' : 'lazy'"
      :fetchpriority="eager ? 'high' : undefined"
      decoding="async"
      @load="loaded = true"
    >
  </span>
</template>
