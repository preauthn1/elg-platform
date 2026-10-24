<script setup lang="ts">
import type { GamesByTagItem } from '#shared/api/endpoints/games'
import { isRtpVisible } from '#shared/utils/games'
import { decFormat, decIsZero, isDecimalText } from '#shared/utils/amount'

/**
 * 橫向遊戲卡列（設計稿的 FREE GAME 1+1）。
 * 卡片上方的數字是彩池 lottery_pool，下方綠標是 rtp，兩者都來自 lobby/games。
 */
defineProps<{ items: GamesByTagItem[] }>()

const { enterGame } = useGameLauncher()

const vpowerGame = ref<GamesByTagItem | null>(null)

async function play(game: GamesByTagItem) {
  const entry = await enterGame(game)
  if (entry.kind === 'vpower-notice') vpowerGame.value = game
}

/** 後端沒有彩池時回 "0.000"，那種不該顯示徽章。 */
function hasPool(value: string | null | undefined): boolean {
  return isDecimalText(value) && !decIsZero(value)
}
</script>

<template>
  <div
    class="flex gap-[0.625rem] overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
    :style="{ paddingInline: 'var(--c17-pad-x)' }"
  >
    <!--
      卡片構造照設計稿（量到的）：82×82 的圖（圓角 4）＋ **往下隔 4 的一條獨立深色 RTP 條**
      （82×20，圓角 4，左邊 "RTP" 用 #f1f4f8、右邊數值用螢光 #d8ff1a）。
      圖與條是兩塊分開的圓角，不是一張白卡包起來——所以沒有白底也沒有卡片陰影。

      **沒有遊戲名稱**：設計稿的卡面不放文字，名稱本來就印在遊戲商的縮圖裡。
      名稱仍然留在 alt，讀屏與圖片載不出來時還讀得到。

      設計稿左上那顆膠囊是「綠點 + 線上人數」，但 lobby/games 沒有人數欄位
      （只有 rtp 與 lottery_pool），所以那個數字做不出來。這裡沿用同一個位置與形狀
      放我們真的有的彩池金額，**沒有綠點**——綠點是「線上」的意思，沒資料就不要畫。
    -->
    <button
      v-for="game in items"
      :key="game.id"
      type="button"
      class="w-[5.125rem] shrink-0 text-left"
      @click="play(game)"
    >
      <div class="relative overflow-hidden rounded-[var(--c17-radius-xs)]">
        <img
          :src="game.image ?? ''"
          :alt="game.name ?? ''"
          class="block aspect-square w-full object-cover"
          loading="lazy"
        >
        <span
          v-if="hasPool(game.lottery_pool)"
          class="absolute left-0 top-1 max-w-full truncate rounded-[var(--c17-radius-xs)] px-[3px] py-[1px] font-semibold"
          :style="{ background: 'var(--c17-ink)', color: 'var(--c17-accent)', fontSize: 'var(--c17-text-badge)' }"
        >
          {{ decFormat(game.lottery_pool, 0) }}
        </span>
      </div>

      <p
        v-if="isRtpVisible(game.rtp)"
        class="mt-1 flex h-5 items-center justify-between rounded-[var(--c17-radius-xs)] px-[6px]"
        :style="{ background: 'var(--c17-ink)' }"
      >
        <span :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-primary-ink)' }">RTP</span>
        <!--
          設計稿寫的是 `RTP 97.25%`——**要帶百分比符號**。
          後端的 rtp 欄位回的就是「%數」本身（97.25 代表 97.25%），直接印再補上 %，
          不要再除以 100。
        -->
        <span
          class="truncate font-semibold"
          :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-accent)' }"
        >{{ game.rtp }}%</span>
      </p>
      <span
        v-else
        class="mt-1 block h-5"
      />
    </button>

    <GamesVpowerEntryModal
      v-if="vpowerGame"
      :game="vpowerGame"
      @close="vpowerGame = null"
      @blocked="vpowerGame = null"
    />
  </div>
</template>
