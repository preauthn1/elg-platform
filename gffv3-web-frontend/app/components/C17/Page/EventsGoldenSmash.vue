<script setup lang="ts">
/**
 * 黃金敲蛋（設計稿 Golden Smash-pop out / opened）。規格見 docs/golden-smash-spec.md。
 *
 * 量到的值（畫板 390×844）：
 *   舞台 golden-smash-bg   351×408 @20,228
 *   三顆蛋 golden-egg      120×108.97，X = 53.5 / 136 / 219，Y = 417.05
 *   SELECT (1) EGG         Inter ExtraBold 16、#F1F4F8 @133,372
 *   破殼蛋 golden-egg-opend2 320×340 @35,253
 *   獎項文字               Inter Black 22、#FACE55、投影 #1A1F3699 X2 Y2 模糊3 @120,406
 *   Congratulation!        PJS SemiBold 12 #FFFFFF @149,613
 *   Remaining Today        PJS SemiBold 12 #FFFFFF @141,656
 * 位置全部換算成舞台圖的百分比，換裝置寬度不會跑掉。
 *
 * **三顆蛋是純裝飾**，後端沒有「敲了哪一顆」這個參數，也不需要（規格 §2.3）。
 *
 * 狀態來自 event/legacy-list 的 show_exchange（三態，見 dailyPrizeState）。
 * 活動 id 由 ?ids= 帶進來——後端還無法從 event/list 認出哪一個活動是敲蛋
 * （沒有專屬 prizes.type_id，規格 §7.1），與紅包同一個狀況。
 */
const {
  EGG_LEFT,
  phase,
  picked,
  busy,
  prize,
  demo,
  canSmash,
  statusText,
  replay,
  smash,
} = useEventsGoldenSmashPage()
</script>

<template>
  <EventsActivityShell
    activity="goldenSmash"
    fallback="/member"
  >
    <div class="flex flex-col items-center gap-(--c17-gap-3) pt-(--c17-gap-2)">
      <!-- 未敲／敲擊中：舞台 ＋ 三顆蛋。槌子燒在舞台圖裡，所以揮不動（規格 §1.4） -->
      <div
        v-if="phase !== 'opened'"
        class="relative w-full"
        :class="[phase === 'smashing' ? 'wf-stage-hit' : '', canSmash || busy ? '' : 'opacity-60 grayscale-[0.8]']"
      >
        <img
          src="/skins/c17/golden-smash/stage.webp"
          alt=""
          class="block w-full"
        >

        <span
          class="absolute inset-x-0 top-[35.3%] text-center font-extrabold uppercase"
          :style="{
            fontFamily: 'var(--c17-font-display)',
            fontSize: 'var(--c17-text-title)',
            color: 'var(--c17-primary-ink)',
          }"
        >{{ $t('events.goldenSmash.action.selectEgg') }}</span>

        <button
          v-for="(left, index) in EGG_LEFT"
          :key="index"
          type="button"
          class="absolute top-[46.3%] h-[26.7%] w-[34.2%]"
          :class="[
            picked === index ? 'wf-egg-hit' : 'wf-egg',
            canSmash ? '' : 'pointer-events-none',
          ]"
          :style="{ left: `${left}%`, animationDelay: `${index * 0.2}s` }"
          :disabled="!canSmash"
          :aria-label="$t('events.goldenSmash.action.selectEgg')"
          @click="smash(index)"
        >
          <img
            src="/skins/c17/golden-smash/egg.webp"
            alt=""
            class="block size-full object-contain"
          >
        </button>

        <!--
          槌子。設計稿是跟舞台燒在同一張圖裡的，匯出後把它切成獨立的一層
          （舞台左右對稱，用左半鏡射重建出沒有槌子的版本，再用差異切出槌子）。
          位置是它在原圖上的位置，所以不敲的時候看起來跟設計稿一模一樣。
        -->
        <img
          src="/skins/c17/golden-smash/hammer.webp"
          alt=""
          class="pointer-events-none absolute left-[49.86%] top-[0.65%] w-[47.01%]"
          :class="phase === 'smashing' ? 'wf-hammer-swing' : ''"
        >

        <!-- 敲中的那一下：舞台被打得抖一下，白光在被敲的那顆蛋上炸開 -->
        <div
          v-if="phase === 'smashing' && picked >= 0"
          class="wf-smash-flash pointer-events-none absolute top-[46.3%] h-[26.7%] w-[34.2%]"
          :style="{ left: `${EGG_LEFT[picked]}%` }"
        />
      </div>

      <!-- 開啟態。設計稿這一張沒有舞台，只有破殼的蛋，獎項寫在藍緞帶上 -->
      <component
        :is="demo ? 'button' : 'div'"
        v-else
        class="wf-prize-reveal relative block w-[89%]"
        :type="demo ? 'button' : undefined"
        @click="replay"
      >
        <img
          src="/skins/c17/golden-smash/egg-open.webp"
          alt=""
          class="block w-full"
        >
        <!-- 獎項寫在藍緞帶上。緞帶佔圖高 36%～57%，所以框住那一段再置中，
             單位字長短不一也不會掉到緞帶外面 -->
        <span
          class="wf-prize-pop absolute inset-x-0 top-[36%] flex h-[21%] flex-col items-center justify-center px-[12%] text-center leading-tight"
        >
          <span
            class="w-full truncate font-black"
            :style="{
              fontFamily: 'var(--c17-font-display)',
              fontSize: 'var(--c17-text-prize-label)',
              color: 'var(--c17-prize-gold)',
              textShadow: 'var(--c17-shadow-prize-gold)',
            }"
          >{{ prize.value }}</span>
          <span
            v-if="prize.unit"
            class="w-full truncate font-semibold uppercase"
            :style="{
              fontSize: 'var(--c17-text-label)',
              color: 'var(--c17-prize-gold)',
              textShadow: 'var(--c17-shadow-prize-gold)',
            }"
          >{{ prize.unit }}</span>
        </span>
      </component>

      <p
        class="text-center font-semibold text-surface"
        :style="{ fontSize: 'var(--c17-text-label)' }"
      >
        {{ phase === 'opened' ? $t('events.common.msg.congratulation') : statusText }}
      </p>

      <!-- 預覽模式才有：點開啟態可以再敲一次 -->
      <p
        v-if="demo && phase === 'opened'"
        class="text-center font-semibold text-surface underline"
        :style="{ fontSize: 'var(--c17-text-label)' }"
      >
        {{ $t('events.common.action.playAgain') }}
      </p>
    </div>
  </EventsActivityShell>
</template>
