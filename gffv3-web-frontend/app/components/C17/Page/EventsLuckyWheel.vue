<script setup lang="ts">
import { WHEEL_SPIN_MS } from '~/utils/motion'

/**
 * 幸運輪盤（設計稿 Lucky Wheel-pop out）。規格見 docs/lucky-wheel-spec.md。
 *
 * **盤面分兩層**：轉的是扇形與獎項文字，金框、燈泡、指針、SPIN 鈕疊在上面不動。
 * 設計稿的 `lucky-spin` 是把四樣東西燒在同一張點陣圖裡，整張轉的話指針會跟著轉，
 * 所以匯出後把扇形挖掉，只留外框那層（wheel-frame.webp，規格 §5.0）。
 * 扇形改用 conic-gradient：**格數由獎項數決定**，給圖就把六格寫死了。
 *
 * 量到的值（畫板 390×844）：
 *   盤面        380×380 @5,228（畫布補過邊讓圓心落在正中央，所以是 aspect-square 滿版）
 *   獎項數值    Inter ExtraBold 18、彩色格 #D8FF1A／米白格 #1343BB，投影 #1A1F3699 X1 Y1 模糊2
 *   獎項單位    PJS SemiBold 10、同色
 *   Remaining   PJS SemiBold 12 #FFFFFF @141,623
 *
 * 活動 id 走 `?ids=`，與紅包、活動列表同一套。
 */
const {
  loading,
  spinning,
  angle,
  wonText,
  slots,
  slice,
  face,
  canSpin,
  statusText,
  spin,
} = useEventsLuckyWheelPage()

/** 骨架的出現時機由 useSkeleton 決定，不可以直接綁 loading（見該 composable 的說明） */
const showSkeleton = useSkeleton(loading)
</script>

<template>
  <EventsActivityShell
    activity="luckyWheel"
    fallback="/events"
  >
    <div class="flex flex-col items-center gap-(--c17-gap-3) pt-(--c17-gap-2)">
      <div class="relative aspect-square w-full">
        <UiSkeleton
          v-if="showSkeleton"
          class="absolute inset-[6%] rounded-full"
        />

        <template v-else>
          <!-- 會轉的那層：扇形 ＋ 沿半徑排的獎項文字 -->
          <div
            class="wf-wheel absolute inset-0"
            :class="canSpin || spinning ? '' : 'opacity-60 grayscale-[0.8]'"
            :style="{ transform: `rotate(${angle}deg)`, transitionDuration: `${WHEEL_SPIN_MS}ms` }"
          >
            <div
              class="absolute left-1/2 top-1/2 size-[78.5%] -translate-x-1/2 -translate-y-1/2 rounded-full"
              :style="{ background: face }"
            />
            <div
              v-for="(slot, index) in slots"
              :key="slot.id"
              class="absolute inset-0"
              :style="{ transform: `rotate(${index * slice}deg)` }"
            >
              <span
                class="absolute left-1/2 top-[19%] flex w-[30%] -translate-x-1/2 flex-col items-center leading-tight"
                :style="{ color: slot.ink, textShadow: 'var(--c17-shadow-wheel-label)' }"
              >
                <span
                  class="w-full truncate text-center font-extrabold"
                  :style="{ fontFamily: 'var(--c17-font-display)', fontSize: 'var(--c17-text-lg)' }"
                >{{ slot.value }}</span>
                <span
                  v-if="slot.unit"
                  class="w-full truncate text-center font-semibold uppercase"
                  :style="{ fontSize: 'var(--c17-text-label)' }"
                >{{ slot.unit }}</span>
              </span>
            </div>
          </div>

          <!-- 不動的那層：金框、燈泡、指針、SPIN 鈕都在這張圖裡 -->
          <img
            src="/skins/c17/lucky-wheel/wheel-frame.webp"
            alt=""
            class="pointer-events-none absolute inset-0 size-full"
          >
          <!-- SPIN 鈕的長相在上面那張圖裡，這顆只負責接點擊 -->
          <button
            type="button"
            class="absolute left-1/2 top-1/2 size-[22%] -translate-x-1/2 -translate-y-1/2 rounded-full"
            :disabled="!canSpin"
            :aria-label="$t('events.luckyDraw.action.spinWheel')"
            @click="spin"
          />
        </template>
      </div>

      <p
        class="text-center font-semibold text-surface"
        :style="{ fontSize: 'var(--c17-text-label)' }"
      >
        {{ wonText || statusText }}
      </p>
    </div>
  </EventsActivityShell>
</template>
