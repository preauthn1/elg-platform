<script setup lang="ts">
import { formatEventAmount } from '~/utils/jackpot'
import { growthStatusTextKey, growthTierHasProgressBar } from '~/utils/progressive-jackpot'

/** C17 的畫面，邏輯在 useProgressiveJackpotPage。 */
const {
  growth,
  setting,
  summary,
  hasDetail,
  tiers,
  currentTier,
  hasCurrentTier,
  currentReward,
  isRestricted,
  ratios,
  loading,
  openedLevels,
  openTier,
  showFormula,
  showRule,
  eventName,
  completedText,
  tierName,
  countdownText,
  summaryDeadlineText,
  tierLimitText,
  tierRewardText,
  toggleTier,
  claimDisabled,
} = useProgressiveJackpotPage()
</script>

<template>
  <section class="space-y-4">
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold">
          {{ eventName }}
        </h1>
        <p class="text-sm text-muted">
          {{ $t('events.growthPlan.title.subtitle') }}
        </p>
      </div>
      <button
        type="button"
        class="rounded border border-line bg-surface px-3 py-1 text-sm"
        @click="showRule = !showRule"
      >
        {{ $t('events.detail.title.description') }}
      </button>
    </header>

    <p
      v-if="loading"
      class="text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>

    <section
      v-if="showRule && setting"
      class="rounded border border-line bg-surface p-4"
    >
      <h2 class="font-semibold">
        {{ eventName }}
      </h2>
      <!-- eslint-disable vue/no-v-html -->
      <div
        class="prose mt-2 max-w-none text-sm"
        v-html="setting.content || ''"
      />
      <!-- eslint-enable vue/no-v-html -->
    </section>

    <div
      v-if="hasDetail && summary"
      class="space-y-4"
    >
      <img
        v-if="setting?.image"
        :src="setting.image"
        class="w-full rounded"
        alt=""
      >

      <p
        v-if="isRestricted"
        class="rounded bg-warning-soft px-3 py-2 text-sm text-warning"
      >
        {{ $t('events.growthPlan.text.restricted') }}
      </p>

      <div class="rounded border border-line bg-surface p-4">
        <div class="flex items-center justify-between">
          <h2 class="font-semibold">
            {{ $t('events.growthPlan.label.currentTier') }}: {{ currentTier ? tierName(currentTier) : '-' }}
          </h2>
          <span class="text-sm text-muted">{{ completedText }}</span>
        </div>
        <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
          <dt class="text-muted">
            {{ $t('events.growthPlan.label.totalBonus') }}
          </dt>
          <dd class="tabular-nums">
            {{ formatEventAmount(currentReward) }}
          </dd>
          <dt class="text-muted">
            {{ $t('events.growthPlan.label.claimable') }}
          </dt>
          <dd class="tabular-nums">
            {{ formatEventAmount(summary.can_receive) }}
          </dd>
          <dt class="text-muted">
            {{ $t('events.growthPlan.label.toUnlock') }}
          </dt>
          <dd class="tabular-nums">
            {{ formatEventAmount(summary.pending_unlock) }}
          </dd>
          <dt class="text-muted">
            {{ $t('events.growthPlan.label.unlockProgress') }}
          </dt>
          <dd>{{ Number(summary.progress) }}%</dd>
          <template v-if="hasCurrentTier">
            <dt class="text-muted">
              {{ $t('events.progressiveJackpot.label.timeLimit') }}
            </dt>
            <dd class="tabular-nums">
              {{ summaryDeadlineText }}
            </dd>
          </template>
        </dl>
        <button
          type="button"
          class="mt-3 text-sm text-accent underline"
          @click="showFormula = !showFormula"
        >
          {{ $t('events.growthPlan.title.unlockFormula') }}
        </button>
        <div
          v-if="showFormula"
          class="mt-2 rounded bg-page p-3 text-sm"
        >
          <p>{{ $t('events.growthPlan.hint.unlockFormula') }}</p>
          <ul class="mt-2 space-y-1">
            <li
              v-for="ratio in ratios"
              :key="ratio.gameTypeId"
            >
              {{ $t('games.common.label.category') }} #{{ ratio.gameTypeId }}: {{ $t('events.growthPlan.label.unlockRate') }} {{ ratio.rate }}%
            </li>
          </ul>
        </div>
      </div>

      <section class="space-y-2">
        <h2 class="font-semibold">
          {{ $t('events.growthPlan.title.tiers') }}
        </h2>
        <article
          v-for="tier in tiers"
          :key="tier.level"
          class="rounded border border-line bg-surface p-4"
        >
          <button
            type="button"
            class="flex w-full items-center justify-between text-left"
            @click="toggleTier(tier)"
          >
            <span class="font-semibold">{{ tierName(tier) }}</span>
            <span class="text-sm text-muted">{{ $t(growthStatusTextKey(tier.status)) }}</span>
          </button>
          <p class="mt-1 text-sm">
            {{ tierRewardText(tier) }}
          </p>
          <p
            v-if="!tier.isFirstDeposit"
            class="text-sm text-muted"
          >
            {{ $t('events.growthPlan.label.depositRequired', { value: formatEventAmount(tier.depositPoint) }) }}
          </p>
          <p class="text-sm text-muted">
            {{ tierLimitText(tier) }}
          </p>

          <div
            v-if="openTier === tier.level"
            class="mt-3 space-y-2 border-t border-line pt-3 text-sm"
          >
            <p
              v-if="tier.status === 'reached'"
              class="text-muted"
            >
              {{ $t('events.growthPlan.hint.autoStart') }}
            </p>
            <template v-if="growthTierHasProgressBar(tier)">
              <div class="h-2 w-full rounded bg-line">
                <div
                  class="h-2 rounded bg-accent"
                  :style="{ width: `${Math.max(0, Math.min(100, tier.progress))}%` }"
                />
              </div>
              <dl class="grid grid-cols-2 gap-x-6 gap-y-1 sm:grid-cols-3">
                <dt class="text-muted">
                  {{ $t('events.growthPlan.label.unlocked') }}
                </dt>
                <dd class="tabular-nums">
                  {{ formatEventAmount(tier.unlocked) }}
                </dd>
                <dt class="text-muted">
                  {{ $t('events.growthPlan.label.claimed') }}
                </dt>
                <dd class="tabular-nums">
                  {{ formatEventAmount(tier.claimed) }}
                </dd>
                <dt class="text-muted">
                  {{ $t('events.growthPlan.label.toUnlock') }}
                </dt>
                <dd class="tabular-nums">
                  {{ formatEventAmount(tier.remaining) }}
                </dd>
              </dl>
              <p
                v-if="tier.expireAt"
                class="tabular-nums text-muted"
              >
                {{ $t('events.common.label.remainingTime') }}: {{ countdownText(growth.tierSeconds(tier.expireAt)) }}
              </p>
              <p
                v-if="tier.status === 'done' && tier.claimable <= 0"
                class="text-muted"
              >
                {{ $t('events.growthPlan.msg.tierCompleted') }}
              </p>
              <button
                v-else
                type="button"
                class="rounded bg-accent px-4 py-2 text-inverse disabled:opacity-40"
                :disabled="claimDisabled(tier)"
                @click="growth.claim(tier.level)"
              >
                {{ $t('events.growthPlan.action.claim') }} {{ formatEventAmount(tier.claimable) }}
              </button>
            </template>
          </div>
        </article>
      </section>
    </div>
    <p
      v-else-if="!loading"
      class="text-muted"
    >
      {{ $t('common.ui.empty.noData') }}
    </p>

    <div
      v-if="openedLevels.length"
      class="fixed inset-0 z-50 flex items-center justify-center bg-scrim p-4"
    >
      <div class="w-full max-w-sm rounded bg-surface p-4 text-center">
        <h2 class="font-semibold">
          {{ $t('common.ui.title.prompt') }}
        </h2>
        <p class="mt-2">
          {{ $t('events.growthPlan.msg.bonusOpened') }}
        </p>
        <button
          type="button"
          class="mt-4 rounded bg-accent px-4 py-2 text-inverse"
          @click="growth.dismissOpenedNotice()"
        >
          {{ $t('common.ui.action.confirm') }}
        </button>
      </div>
    </div>
  </section>
</template>
