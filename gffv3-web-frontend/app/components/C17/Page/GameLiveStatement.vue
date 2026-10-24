<script setup lang="ts">
/**
 * 真人類型（type 1）與 LIVE_STATEMENT_VENDOR_IDS 的進場前置頁：贏分上限聲明。
 * 設計稿沒有這一張，與 GamesStatement 一樣沿用 C17 內頁的語彙。
 */
const {
  site,
  game,
  agreed,
  notice,
  currencyName,
  onClick,
} = useLiveStatement()
</script>

<template>
  <div v-if="game">
    <C17PageHeader
      :title="game.name ?? $t('site.legal.title.userAgreement')"
      fallback="/vendors"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <section class="rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]">
        <p
          class="whitespace-pre-wrap"
          :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
        >
          {{ $t('games.launch.text.winLimitStatement', { name: site?.raw.title ?? '', amount: '500,000', currencyName }) }}
        </p>
      </section>

      <UiButton @click="onClick">
        {{ $t(agreed ? 'games.launch.action.clickToEnter' : 'games.launch.action.agree') }}
      </UiButton>

      <p
        v-if="notice === 'popup'"
        class="rounded-(--c17-field-radius) border border-warning bg-warning-soft p-(--c17-gap-3) text-(length:--c17-text-link) text-warning"
      >
        {{ $t('games.launch.hint.newWindow') }}
      </p>

      <a
        href="https://one.one.one.one/"
        target="_blank"
        rel="noopener"
        class="text-(length:--c17-text-label) font-bold text-danger underline"
      >{{ $t('games.launch.hint.loginIssue') }}</a>
    </div>
  </div>
</template>
