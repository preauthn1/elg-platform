<script setup lang="ts">
import type { HeroSlide } from '#shared/types/ui'

/**
 * 橫幅輪播（設計稿 Home v3 與 Game-page 的 390×140 那條）。
 * 圖片與上面的文字都燒在圖裡，前端不另外疊字。
 *
 * 來源有兩種，所以只認 `HeroSlide` 這個最小形狀，不直接吃 `AdvertiseItem`：
 *   首頁      → advertise path 5 的廣告（呼叫端用 useBannerNavigation 轉成 slide）
 *   遊戲大廳  → 寫死在皮裡的類型橫幅（public/skins/c17/lobby-banner/<typeId>.webp）
 */
const props = defineProps<{ items: HeroSlide[] }>()

const track = ref<HTMLElement | null>(null)
const current = ref(0)

/** 以捲動位置換算目前在第幾張，不另外做 JS 動畫，交給原生 scroll-snap。 */
function onScroll() {
  const el = track.value
  if (!el || el.clientWidth === 0) return
  current.value = Math.round(el.scrollLeft / el.clientWidth)
}

function goTo(index: number) {
  const el = track.value
  if (!el) return
  el.scrollTo({ left: index * el.clientWidth, behavior: 'smooth' })
}

const hasItems = computed(() => props.items.length > 0)
</script>

<template>
  <section
    v-if="hasItems"
    class="relative"
    :aria-label="$t('site.common.label.bannerEvents')"
  >
    <div
      ref="track"
      class="flex snap-x snap-mandatory overflow-x-auto scroll-smooth [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
      @scroll.passive="onScroll"
    >
      <button
        v-for="item in items"
        :key="item.key"
        type="button"
        class="w-full shrink-0 snap-start"
        :class="item.onSelect ? '' : 'cursor-default'"
        :tabindex="item.onSelect ? 0 : -1"
        @click="item.onSelect?.()"
      >
        <!--
          設計稿的 hero 是固定的 390×140（比例 39:14）。後端的 banner 各種比例都有，
          讓它照原圖比例撐會把下面整段推低——量到的是低了 28px。用 aspect 鎖住比例、
          object-cover 裁切，版面才不會跟著素材跑。
        -->
        <img
          :src="item.image"
          :alt="item.alt ?? ''"
          class="block aspect-[39/14] w-full object-cover"
          loading="eager"
        >
      </button>
    </div>

    <!--
      分頁指示器**壓在圖片上**，不是排在圖片下方。
      設計稿量到的：圖片 y 51–191、指示器 y 177–183，也就是距圖片底 8。
      形狀是膠囊不是圓點——作用中 40×6 主色、其餘 20×6 #7a8599 **80% 透明度**
      （交付的 Rectangle 1735/1736.svg 寫著 opacity="0.8"，向量資料沒帶這個欄位），
      彼此間距 20，整組置中。
    -->
    <div
      v-if="items.length > 1"
      class="absolute inset-x-0 bottom-2 flex justify-center gap-[1.25rem]"
    >
      <button
        v-for="(item, index) in items"
        :key="item.key"
        type="button"
        class="h-1.5 rounded-[var(--c17-radius-sm)] transition-[width,background-color]"
        :style="{
          width: index === current ? '2.5rem' : '1.25rem',
          background: index === current
            ? 'var(--c17-primary)'
            : 'color-mix(in srgb, var(--c17-muted) 80%, transparent)',
        }"
        :aria-label="`${index + 1}`"
        :aria-current="index === current ? 'true' : undefined"
        @click="goTo(index)"
      />
    </div>
  </section>
</template>
