<script setup lang="ts">
import { truncateAmount } from '#shared/utils/competitions'

/**
 * 贏家榜。設計稿沒有這一張，沿用 C17 內頁的語彙：
 * 深色標題列 ＋ 分段切換 ＋ 前三名磚 ＋ 共用資料表（UiTable）。
 *
 * 前三名與其餘名次由 splitLeaderBoard 切開，兩個分頁共用同一組版型。
 */
const {
  tabs,
  podiumLabels,
  winner,
  tab,
  board,
  loading,
  columns,
  rows,
} = useRankingWinnersPage()

/** 骨架的出現時機由 useSkeleton 決定，不可以直接綁 loading（見該 composable 的說明） */
const showSkeleton = useSkeleton(() => loading.value)
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('competitions.ranking.title.winnerList')"
      fallback="/member"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <!-- 分段切換：與代理頁同一顆圓角槽，選中的那半是實心藍 -->
      <div class="flex h-8 overflow-hidden rounded-(--c17-radius-sm) border border-field-line bg-surface-2">
        <button
          v-for="item in tabs"
          :key="item.key"
          type="button"
          class="flex-1 font-bold"
          :style="{
            fontSize: 'var(--c17-text-link)',
            background: tab === item.key ? 'var(--c17-primary)' : 'transparent',
            color: tab === item.key ? 'var(--c17-primary-ink)' : 'var(--c17-muted)',
          }"
          @click="winner.selectTab(item.key)"
        >
          {{ $t(item.label) }}
        </button>
      </div>

      <!-- 前三名載入中先畫三格磚，換上真資料時高度不跳 -->
      <div
        v-if="showSkeleton"
        class="grid grid-cols-3 gap-(--c17-tile-gap)"
      >
        <UiSkeleton
          v-for="i in 3"
          :key="i"
          class="aspect-square rounded-lg"
        />
      </div>

      <template v-else>
        <!-- 前三名。第一名用螢光底強調，二三名是一般白卡 -->
        <ol class="grid grid-cols-3 gap-(--c17-tile-gap)">
          <li
            v-for="(item, index) in board.podium"
            :key="index"
            class="flex flex-col items-center gap-1 rounded-(--c17-radius-sm) p-(--c17-gap-2) text-center"
            :style="{
              background: index === 0 ? 'var(--c17-wallet-bg)' : 'var(--c17-surface)',
              boxShadow: 'var(--c17-shadow-panel)',
            }"
          >
            <span :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }">
              {{ $t(podiumLabels[index] ?? 'competitions.ranking.label.rank') }}
            </span>
            <template v-if="item">
              <img
                v-if="item.image"
                :src="item.image"
                :alt="item.username ?? ''"
                class="size-12 rounded-full object-cover"
              >
              <span
                class="w-full truncate font-bold"
                :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
              >{{ item.username }}</span>
              <span
                class="font-extrabold tabular-nums"
                :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-primary)' }"
              >{{ truncateAmount(item.total_win_lose) }}</span>
              <span
                v-if="'vendor_name' in item && item.vendor_name"
                class="w-full truncate"
                :style="{ fontSize: 'var(--c17-text-badge)', color: 'var(--c17-muted)' }"
              >{{ item.vendor_name }}</span>
            </template>
            <span
              v-else
              :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-muted)' }"
            >-</span>
          </li>
        </ol>
      </template>

      <!-- 名次表自己畫骨架列，不跟著上面的 v-if 整塊換掉：欄寬與列高都是真的 -->
      <UiTable
        :columns="columns"
        :rows="rows"
        :loading="showSkeleton"
      />
    </div>
  </div>
</template>
