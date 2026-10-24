<script setup lang="ts">
import type { GamesByTagItem } from '#shared/api/endpoints/games'
import { gameTypeLabelKey, isGamePlayable, isRtpVisible } from '#shared/utils/games'

/** C17 標籤遊戲列表（熱門／最新／推薦）。資料與篩選在 useTagGames，進遊戲在 useGamePlay。 */
const { titleKey, status, typeId, typeTabs, visibleGames } = await useTagGames()
const { notice, vpowerGame, play } = useGamePlay<GamesByTagItem>()
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-xl font-semibold">
      {{ $t(titleKey) }}
    </h1>

    <div
      v-if="typeTabs.length > 1"
      class="flex flex-wrap gap-2 text-sm"
    >
      <button
        type="button"
        class="rounded border px-3 py-1"
        :class="typeId === null ? 'border-accent text-accent' : 'border-line'"
        @click="typeId = null"
      >
        {{ $t('games.category.option.allGames') }}
      </button>
      <button
        v-for="id in typeTabs"
        :key="id"
        type="button"
        class="rounded border px-3 py-1"
        :class="typeId === id ? 'border-accent text-accent' : 'border-line'"
        @click="typeId = id"
      >
        {{ $t(gameTypeLabelKey(id)) }}
      </button>
    </div>

    <p
      v-if="notice === 'popup'"
      class="text-sm text-warning"
    >
      {{ $t('games.launch.hint.newWindow') }}
    </p>

    <GamesVpowerEntryModal
      v-if="vpowerGame"
      :game="vpowerGame"
      @close="vpowerGame = null"
      @blocked="vpowerGame = null; notice = 'popup'"
    />

    <p
      v-if="status === 'pending'"
      class="text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>
    <p
      v-else-if="visibleGames.length === 0"
      class="text-muted"
    >
      {{ $t('common.ui.empty.noData') }}
    </p>

    <ul
      v-else
      class="grid grid-cols-3 gap-3 md:grid-cols-5"
    >
      <li
        v-for="game in visibleGames"
        :key="game.id"
        class="rounded border border-line bg-surface p-2"
      >
        <button
          type="button"
          class="w-full text-left disabled:opacity-50"
          :disabled="!isGamePlayable(game)"
          @click="play(game)"
        >
          <img
            v-if="game.image"
            :src="game.image"
            :alt="game.name ?? ''"
            class="mb-1 aspect-square w-full object-cover"
          >
          <div class="truncate text-sm">
            {{ game.name }}
          </div>
          <div class="text-xs text-muted">
            {{ game.vendor_name }}
            <!-- rtp 後端回的是「%數」本身，顯示要帶 %（與首頁遊戲列一致） -->
            <span v-if="isRtpVisible(game.rtp)"> · {{ $t('games.lobby.unit.rtp') }} {{ game.rtp }}%</span>
          </div>
          <div
            v-if="game.is_maintenance === 1"
            class="text-xs text-warning"
          >
            {{ $t('common.ui.label.maintenance') }}
            <span v-if="game.maintenance_end_time">· {{ game.maintenance_end_time }}</span>
          </div>
        </button>
      </li>
    </ul>
  </div>
</template>
