<script setup lang="ts">
/** C17 的畫面，邏輯在 useCompetitionsRefreshRankingGamePage。 */
const {
  title,
  game,
  gameSrc,
  openWindow,
  popupBlocked,
  loading,
  failed,
  backPath,
} = useCompetitionsRefreshRankingGamePage()
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center gap-3">
      <NuxtLink
        :to="backPath"
        class="text-sm text-accent"
      >
        ← 返回
      </NuxtLink>
      <h1 class="text-xl font-semibold">
        {{ title || $t('games.common.label.game') }}
      </h1>
    </div>

    <div
      v-if="failed"
      class="space-y-2 text-sm"
    >
      <p class="text-danger">
        {{ $t('games.launch.error.network') }}
      </p>
      <button
        type="button"
        class="rounded border border-line px-3 py-1"
        @click="game.launch()"
      >
        {{ $t('competitions.ranking.action.startGame') }}
      </button>
    </div>
    <p
      v-else-if="loading || (!gameSrc && !openWindow)"
      class="text-sm text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>
    <div
      v-else-if="openWindow"
      class="space-y-3 py-8 text-center text-sm"
    >
      <p>{{ $t('games.launch.msg.openedNewWindow') }}</p>
      <button
        v-if="popupBlocked"
        type="button"
        class="rounded bg-accent px-4 py-2 text-inverse"
        @click="game.openInNewWindow()"
      >
        {{ $t('games.launch.action.open') }}
      </button>
    </div>
    <iframe
      v-else
      :src="gameSrc"
      class="h-[75vh] w-full rounded border border-line bg-surface"
      allowfullscreen
    />
  </div>
</template>
