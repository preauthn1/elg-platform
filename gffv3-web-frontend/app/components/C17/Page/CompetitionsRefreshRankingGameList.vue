<script setup lang="ts">
import { REFRESH_RANKING_PATH } from '#shared/utils/competitions'

/** C17 的畫面，邏輯在 useCompetitionsRefreshRankingGameListPage。 */
const {
  list,
  title,
  typeIds,
  selectedTypeId,
  visibleGames,
  loading,
} = useCompetitionsRefreshRankingGameListPage()
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center gap-3">
      <NuxtLink
        :to="REFRESH_RANKING_PATH"
        class="text-sm text-accent"
      >
        ← 返回
      </NuxtLink>
      <h1 class="text-xl font-semibold">
        {{ title || $t('games.lobby.title.gameList') }}
      </h1>
    </div>

    <p
      v-if="loading"
      class="text-sm text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>

    <nav
      v-if="typeIds.length > 1"
      class="flex flex-wrap gap-2 text-sm"
    >
      <button
        type="button"
        class="rounded border px-3 py-1"
        :class="selectedTypeId === null ? 'border-accent font-semibold' : 'border-line'"
        @click="list.selectType(null)"
      >
        {{ $t('games.category.option.allGames') }}
      </button>
      <button
        v-for="typeId in typeIds"
        :key="typeId"
        type="button"
        class="rounded border px-3 py-1"
        :class="selectedTypeId === typeId ? 'border-accent font-semibold' : 'border-line'"
        @click="list.selectType(typeId)"
      >
        {{ $t('games.category.title.category') }} {{ typeId }}
      </button>
    </nav>

    <p
      v-if="!loading && visibleGames.length === 0"
      class="py-6 text-center text-sm text-muted"
    >
      {{ $t('common.ui.empty.noData') }}
    </p>
    <ul
      v-else
      class="grid grid-cols-2 gap-3 sm:grid-cols-4"
    >
      <li
        v-for="game in visibleGames"
        :key="game.id"
      >
        <button
          type="button"
          class="w-full rounded border border-line bg-surface p-2 text-center text-sm disabled:opacity-40"
          :disabled="!list.canEnterEventGame(game)"
          @click="list.enterGame(game)"
        >
          <img
            v-if="game.image"
            :src="game.image"
            :alt="game.name"
            class="mx-auto mb-2 h-20 object-contain"
          >
          <div>{{ game.name }}</div>
          <div
            v-if="game.is_maintenance === 1"
            class="text-xs text-muted"
          >
            {{ $t('common.ui.label.maintenance') }} {{ game.maintenance_end_time || '' }}
          </div>
        </button>
      </li>
    </ul>
  </div>
</template>
