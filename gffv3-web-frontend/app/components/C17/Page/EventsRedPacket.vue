<script setup lang="ts">
/**
 * 紅包（設計稿 Daily Red packet-pop out / opened）。活動 V2 的 AngpowService，
 * 對接說明見 target/tickets#4234 的「紅包前台接口對接說明」。
 *
 * 量到的值（畫板 390×844）：
 *   關閉態 angpao   316.67×380   @36.67,260
 *   TAP TO OPEN     Inter ExtraBold 16、#F1F4F8 @142,328.9
 *   開啟態 angpao3  316×487.39   @37,228
 *   金額            Inter Black 42、#EC0D18、投影 #D8FF1A99 X4 Y4 模糊 6 @129,384
 *   BONUS           Inter ExtraBold 22、#F1F4F8、投影 #EC0D1899 X3 Y3 模糊 4 @155,435
 *   Remaining Today PJS SemiBold 12 #FFFFFF @141,629
 * 版面用比例不用絕對定位：畫板寬 390、紅包寬 316.67 → 81.2%。
 *
 * 活動 id（event_v2_id）由 event/list 的卡片或活動彈窗晶片帶進 ?ids=；
 * 沒帶時（直接開網址）自己從 event/list 找紅包活動。只有 ?demo=1 才是預覽。
 */
// key 帶完整網址：只換 ?ids= 時 Nuxt 會沿用同一個元件，上一檔的開獎結果與 info 會留著
const {
  currency,
  angpow,
  phase,
  prizeText,
  demo,
  eventName,
  showPacket,
  canOpen,
  statusText,
  toastVisible,
  depositOpen,
  goDeposit,
  replay,
  open,
} = useEventsRedPacketPage()
</script>

<template>
  <EventsActivityShell
    activity="redPacket"
    fallback="/"
    :title="eventName"
  >
    <div class="flex flex-col items-center gap-(--c17-gap-3) pt-(--c17-gap-4)">
      <!-- 關閉態。整張是按鈕 -->
      <button
        v-if="showPacket && phase !== 'opened'"
        type="button"
        class="wf-red-packet relative block w-[81.2%] transition-[transform,opacity] duration-300"
        :class="phase === 'opening' ? 'scale-75 opacity-0' : 'scale-100 opacity-100'"
        :disabled="!canOpen"
        @click="open"
      >
        <img
          src="/skins/c17/red-packet/packet-closed.webp"
          alt=""
          class="block w-full"
        >
        <span
          class="absolute inset-x-0 top-[18%] font-extrabold uppercase"
          :style="{
            fontFamily: 'var(--c17-font-display)',
            fontSize: 'var(--c17-text-title)',
            color: 'var(--c17-primary-ink)',
          }"
        >{{ $t('events.redPacket.action.tapToOpen') }}</span>
      </button>

      <!-- 開啟態 -->
      <component
        :is="demo ? 'button' : 'div'"
        v-else-if="phase === 'opened'"
        class="wf-prize-reveal relative block w-[81%]"
        :type="demo ? 'button' : undefined"
        @click="replay"
      >
        <img
          src="/skins/c17/red-packet/packet-open.webp"
          alt=""
          class="block w-full"
        >
        <span class="wf-prize-pop absolute inset-x-0 top-[27%] flex flex-col items-center gap-1">
          <span
            v-if="currency"
            class="font-extrabold"
            :style="{
              fontFamily: 'var(--c17-font-display)',
              fontSize: 'var(--c17-text-title)',
              color: 'var(--c17-prize-red)',
            }"
          >{{ currency }}</span>
          <span
            class="font-black whitespace-nowrap"
            :style="{
              fontFamily: 'var(--c17-font-display)',
              fontSize: 'var(--c17-text-prize)',
              color: 'var(--c17-prize-red)',
              textShadow: 'var(--c17-shadow-prize)',
            }"
          >{{ prizeText }}</span>
          <span
            class="font-extrabold uppercase"
            :style="{
              fontFamily: 'var(--c17-font-display)',
              fontSize: 'var(--c17-text-prize-label)',
              color: 'var(--c17-primary-ink)',
              textShadow: 'var(--c17-shadow-prize-label)',
            }"
          >{{ $t('common.amount.label.bonus') }}</span>
        </span>
      </component>

      <p
        class="text-center font-semibold text-surface"
        :style="{ fontSize: 'var(--c17-text-label)' }"
      >
        {{ phase === 'opened' ? $t('events.common.msg.congratulation') : statusText }}
      </p>

      <!-- 預覽模式才有：點開啟態可以再拆一次 -->
      <p
        v-if="demo && phase === 'opened'"
        class="text-center font-semibold text-surface underline"
        :style="{ fontSize: 'var(--c17-text-label)' }"
      >
        {{ $t('events.common.action.playAgain') }}
      </p>
    </div>

    <Teleport to="#teleports">
      <div
        v-if="toastVisible && angpow.waitSeconds.value > 0"
        role="status"
        class="wf-in-fade fixed inset-x-0 top-(--c17-gap-4) z-[60] mx-auto w-fit max-w-[90%] rounded-(--c17-field-radius) bg-surface px-4 py-2 text-center font-semibold"
        :style="{ fontSize: 'var(--c17-text-label)', boxShadow: 'var(--c17-shadow-raised)' }"
      >
        {{ $t('events.redPacket.msg.waitSeconds', { seconds: angpow.waitSeconds.value }) }}
      </div>

      <UiModal
        v-if="depositOpen"
        :title="$t('common.ui.title.prompt')"
        @close="depositOpen = false"
      >
        <div class="flex flex-col gap-(--c17-gap-3) p-(--c17-gap-4) text-center">
          <p>{{ $t('events.redPacket.text.depositForPoints') }}</p>
          <UiButton @click="goDeposit">
            {{ $t('deposit.entry.nav.deposit') }}
          </UiButton>
        </div>
      </UiModal>
    </Teleport>
  </EventsActivityShell>
</template>
