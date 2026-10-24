<script setup lang="ts">
/**
 * C17 遊戲進行中（設計稿 Game-playing）：上方一條窄的遊戲列（返回＋遊戲名／餘額＋儲值），
 * 其餘整片黑底滿版，**沒有底部分頁**（頁面設 layout: false），
 * 自己畫那條列，遊戲 iframe 佔滿剩下的高度。
 * 載入中／開新視窗／錯誤這幾種狀態一樣置中顯示在那片黑底上。
 * 進場順序在 useGamePlaying。
 */
const { title, src, openedInWindow, popupBlocked, failed, loading, back, init, reopen } = useGamePlaying()
</script>

<template>
  <div class="flex h-dvh w-full flex-col bg-black text-white">
    <!--
      遊戲列（設計稿 Game-playing 上方那條，量到的值）：
        底板     390×52，線性漸層 #1565FF → #1A1F36 **260°**，投影 #7A859933 X3 Y3 模糊6
                 → 與頂部列同一顆 token（--c17-header-bg），不要另外寫一個角度
        返回     8×12 的箭頭，X=16，#F1F4F8
        遊戲名   X=36（箭頭右緣 24 + 12），Plus Jakarta Sans SemiBold 12，#F1F4F8
        右側     與頂部列**同一組**餘額膠囊＋存款鈕，見 C17BalancePill
    -->
    <header
      class="flex h-[52px] shrink-0 items-center gap-(--c17-gap-3) px-(--c17-pad-x)"
      :style="{ background: 'var(--c17-header-bg)', boxShadow: 'var(--c17-shadow-card)' }"
    >
      <button
        type="button"
        class="flex shrink-0 items-center py-2"
        :aria-label="$t('common.ui.action.close')"
        @click="back"
      >
        <svg
          viewBox="0 0 8 12"
          class="h-3 w-2"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
          :style="{ color: 'var(--c17-primary-ink)' }"
        >
          <path d="M7 1 1 6l6 5" />
        </svg>
      </button>
      <span
        class="min-w-0 flex-1 truncate font-semibold"
        :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-primary-ink)' }"
      >{{ title }}</span>
      <!-- 遊戲頁顯示遊戲商餘額：進場已帶 vendor_id 轉入，主錢包此時是 0 -->
      <C17BalancePill
        class="shrink-0"
        source="vendor"
      />
    </header>

    <iframe
      v-if="src && !openedInWindow"
      :src="src"
      class="min-h-0 w-full flex-1 border-0"
      allowfullscreen
    />

    <div
      v-else
      class="flex min-h-0 flex-1 flex-col items-center justify-center"
    >
      <p
        v-if="failed"
        class="px-6 text-center text-sm"
      >
        {{ $t('games.launch.error.network') }}
      </p>
      <p
        v-else-if="loading"
        class="px-6 text-center text-sm opacity-70"
      >
        {{ title || $t('common.ui.label.loading') }}
      </p>

      <div
        v-else-if="openedInWindow"
        class="flex flex-col items-center gap-3 px-6 text-center text-sm"
      >
        <p>{{ $t('games.launch.msg.openedNewWindow') }}</p>
        <p
          v-if="popupBlocked"
          class="text-warning"
        >
          {{ $t('games.launch.hint.newWindow') }}
        </p>
        <button
          type="button"
          class="rounded bg-accent px-4 py-2 text-inverse"
          @click="reopen"
        >
          {{ $t('games.launch.action.open') }}
        </button>
      </div>
    </div>

    <!-- 不在 onMounted 直接 init：跟舊版一樣等領獎提醒放行才轉入、取遊戲網址 -->
    <EventsPrizeReminder @ready="init" />
    <!-- layout: false 沒有外殼，API 錯誤的全站彈窗要自己掛 -->
    <UiAlert />
  </div>
</template>
