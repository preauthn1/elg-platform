<script setup lang="ts">
import type { GameListItem } from '#shared/api/endpoints/games'
import { isGamePlayable } from '#shared/utils/games'

/** C17 遊戲搜尋。搜尋邏輯在 useGameSearch，進遊戲在 useGamePlay。 */
const { keyword, page, list, pagination, pending, changePage } = useGameSearch()
const { notice, vpowerGame, play } = useGamePlay<GameListItem>()
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-xl font-semibold">
      {{ $t('common.ui.action.search') }}
    </h1>

    <input
      v-model="keyword"
      type="search"
      class="w-full rounded border border-line px-3 py-2"
      :placeholder="$t('games.search.placeholder.name')"
    >

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
      v-if="pending"
      class="text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>
    <p
      v-else-if="list.length === 0"
      class="text-muted"
    >
      {{ $t('common.ui.empty.noData') }}
    </p>

    <ul
      v-else
      class="grid grid-cols-3 gap-3 md:grid-cols-5"
    >
      <li
        v-for="game in list"
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
          </div>
        </button>
      </li>
    </ul>

    <UiPagination
      v-if="!pending && pagination"
      :page="page"
      :pages="pagination.pages"
      :total="pagination.total"
      @update:page="changePage"
    />
  </div>
</template>
