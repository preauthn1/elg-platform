<script setup lang="ts">
import { formatAmountFixed2 } from '~/utils/events-core'

/**
 * 每日任務。missionActionPath 的預設落點就是這一頁（MISSION_HOME_PATH），
 * 之前這個路由不存在，任務卡的「前往」導到自己時會 404。
 *
 * 完成與否由 missionState 判斷：progress >= requirement 才算完成，
 * 已領取看 is_claimed。綁定類任務（生日／實名／手機／信箱／銀行卡）在會員資料
 * 已經填好、但後端進度還沒同步時顯示「系統確認中」，不讓使用者重複去填。
 */
const {
  missionsApi,
  missions,
  loading,
  claimingId,
  stateOf,
  percentOf,
  claim,
  terms,
} = useMissionsPage()
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-lg font-semibold">
      {{ $t('events.mission.title.dailyMission') }}
    </h1>

    <p
      v-if="loading"
      class="text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>

    <p
      v-if="!loading && !missions.length"
      class="text-muted"
    >
      {{ $t('common.ui.empty.noData') }}
    </p>

    <ul class="space-y-3">
      <li
        v-for="mission in missions"
        :key="mission.id"
        class="space-y-2 rounded border border-line bg-surface p-4 text-sm"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <h2 class="font-semibold">
              {{ mission.name }}
            </h2>
            <p
              v-if="mission.description"
              class="text-xs text-muted"
            >
              {{ mission.description }}
            </p>
          </div>
          <span
            v-if="mission.reward_value != null"
            class="shrink-0 font-semibold text-accent"
          >
            {{ formatAmountFixed2(mission.reward_value) }}
          </span>
        </div>

        <!-- 進度 -->
        <div
          v-if="percentOf(mission) !== null"
          class="space-y-1"
        >
          <div class="flex justify-between text-xs">
            <span>{{ $t('events.mission.label.currentProgress') }}</span>
            <span>{{ mission.progress ?? 0 }} / {{ mission.requirement }}</span>
          </div>
          <div class="h-2 overflow-hidden rounded bg-line">
            <div
              class="h-full bg-accent"
              :style="{ width: `${percentOf(mission)}%` }"
            />
          </div>
        </div>
        <p
          v-else
          class="text-xs text-muted"
        >
          {{ $t('events.mission.label.unlimitedTimes') }}
        </p>

        <!-- 動作：已領取 / 可領取 / 系統確認中 / 前往達成 -->
        <div class="flex items-center justify-between gap-3">
          <span class="text-xs text-muted">{{ mission.reset_type }}</span>

          <span
            v-if="stateOf(mission).claimed"
            class="text-xs text-muted"
          >
            {{ $t('events.claim.action.redeemed') }}
          </span>

          <button
            v-else-if="stateOf(mission).claimable"
            type="button"
            class="rounded bg-accent px-3 py-1 text-inverse disabled:opacity-40"
            :disabled="claimingId !== null"
            @click="claim(mission)"
          >
            {{ $t('events.claim.action.claim') }}
          </button>

          <span
            v-else-if="stateOf(mission).systemConfirming"
            class="text-xs text-muted"
          >
            {{ $t('events.mission.label.achieved') }}
          </span>

          <NuxtLink
            v-else
            :to="missionsApi.actionPath(mission)"
            class="rounded border border-line px-3 py-1 text-xs"
          >
            {{ $t('events.mission.label.achieveConditions') }}
          </NuxtLink>
        </div>
      </li>
    </ul>

    <details class="rounded border border-line bg-surface p-4 text-sm">
      <summary class="cursor-pointer font-semibold">
        {{ $t('events.detail.title.termsConditions') }}
      </summary>
      <p class="mt-2 whitespace-pre-line text-muted">
        {{ terms }}
      </p>
    </details>
  </div>
</template>
