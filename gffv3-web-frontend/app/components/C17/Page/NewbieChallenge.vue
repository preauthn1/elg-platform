<script setup lang="ts">
import { formatAmountFixed2 } from '~/utils/events-core'

/**
 * 新人專享挑戰（tp_event = 1）。event/list 的第三方活動卡導到這裡，
 * 之前這個路由不存在，點了就 404。
 *
 * 五個步驟對應後端 stage：
 *   未參加 → 1 領取活動金
 *   stage 1 → 2 挑戰（跑流水）
 *   stage 2 → 3 解鎖（倒數內充值）
 *   stage 3 → 4 彩金（跑彩金流水）
 *   stage 4 → 5 完成
 * 每顆按鈕能不能按一律看後端旗標（can_complete_challenge / can_confirm_deposit /
 * can_claim_bonus），前端不自己算流水。
 */
const {
  newbie,
  detail,
  progress,
  bonusWindowHours,
  step,
  expired,
  ended,
  canCompleteChallenge,
  canConfirmDeposit,
  canClaimBonus,
  joining,
  busy,
  loading,
  confirmingQuit,
  STEP_LABELS,
  allowedVendorNames,
  countdown,
  statusLabel,
  onQuit,
} = useNewbieChallengePage()
</script>

<template>
  <div class="space-y-4">
    <header class="space-y-1">
      <h1 class="text-lg font-semibold">
        {{ detail.name || $t('events.newcomer.title.challengeEvent') }}
      </h1>
      <p class="text-sm text-muted">
        {{ statusLabel }}
      </p>
    </header>

    <p
      v-if="loading"
      class="text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>

    <template v-if="!loading">
      <!-- 步驟指示 -->
      <ol class="flex items-center gap-1 text-xs">
        <li
          v-for="(labelKey, index) in STEP_LABELS"
          :key="labelKey"
          class="flex-1 rounded px-2 py-1 text-center"
          :class="step >= index + 1 ? 'bg-accent text-inverse' : 'bg-surface text-muted'"
        >
          {{ $t(labelKey) }}
        </li>
      </ol>

      <!-- 已放棄／已逾期 -->
      <section
        v-if="ended"
        class="space-y-2 rounded border border-line bg-surface p-4 text-sm"
      >
        <h2 class="font-semibold">
          {{ expired ? $t('events.newcomer.title.expired') : $t('events.newcomer.title.quit') }}
        </h2>
        <p class="text-muted">
          {{ expired
            ? $t('events.newcomer.hint.expiredNoDeposit', { value: bonusWindowHours })
            : $t('events.newcomer.text.quit') }}
        </p>
        <NuxtLink
          to="/promotions"
          class="inline-block rounded border border-line px-3 py-1"
        >
          {{ $t('events.newcomer.action.viewOtherEvents') }}
        </NuxtLink>
      </section>

      <template v-else>
        <!-- 步驟 1：尚未參加 -->
        <section
          v-if="step === 1"
          class="space-y-3 rounded border border-line bg-surface p-4 text-sm"
        >
          <p>{{ detail.content }}</p>
          <p class="text-muted">
            {{ $t('events.newcomer.hint.maxBonus', { value: formatAmountFixed2(detail.max_unlock_amount) }) }}
          </p>
          <button
            type="button"
            class="w-full rounded bg-accent px-4 py-2 text-inverse disabled:opacity-40"
            :disabled="joining"
            @click="newbie.join()"
          >
            {{ $t('events.newcomer.action.claimFund') }}
          </button>
        </section>

        <!-- 步驟 2：挑戰流水 -->
        <section
          v-else-if="step === 2 && progress"
          class="space-y-3 rounded border border-line bg-surface p-4 text-sm"
        >
          <h2 class="font-semibold">
            {{ $t('events.newcomer.title.ongoing') }}
          </h2>
          <p class="text-muted">
            {{ $t('events.newcomer.hint.ongoing', { value: allowedVendorNames || '-' }) }}
          </p>
          <p>{{ $t('events.newcomer.label.activityFund', { value: formatAmountFixed2(progress.activity_reward) }) }}</p>
          <div class="space-y-1">
            <div class="flex justify-between">
              <span>{{ $t('events.newcomer.label.turnoverDone') }}</span>
              <span>{{ formatAmountFixed2(progress.challenge_flow_reach) }}</span>
            </div>
            <div class="flex justify-between">
              <span>{{ $t('events.newcomer.label.turnoverLeft') }}</span>
              <span>{{ formatAmountFixed2(progress.challenge_flow_remaining) }}</span>
            </div>
            <div class="h-2 overflow-hidden rounded bg-line">
              <div
                class="h-full bg-accent"
                :style="{ width: `${Number(progress.challenge_flow_percentage ?? 0)}%` }"
              />
            </div>
            <p class="text-xs text-muted">
              {{ $t('events.newcomer.label.targetTurnover', { value: formatAmountFixed2(progress.challenge_flow_target) }) }}
            </p>
          </div>
          <button
            type="button"
            class="w-full rounded bg-accent px-4 py-2 text-inverse disabled:opacity-40"
            :disabled="!canCompleteChallenge || busy === 'newbie-challenge/unlock-bonus'"
            @click="newbie.unlockBonus()"
          >
            {{ busy === 'newbie-challenge/unlock-bonus'
              ? $t('events.newcomer.label.unlocking')
              : $t('events.newcomer.action.unlockBonus') }}
          </button>
        </section>

        <!-- 步驟 3：倒數內充值解鎖 -->
        <section
          v-else-if="step === 3 && progress"
          class="space-y-3 rounded border border-line bg-surface p-4 text-sm"
        >
          <h2 class="font-semibold">
            {{ $t('events.newcomer.title.unlocked') }}
          </h2>
          <p class="text-muted">
            {{ $t('events.newcomer.hint.unlockRange', {
              min: formatAmountFixed2(detail.min_unlock_amount),
              max: formatAmountFixed2(detail.max_unlock_amount),
            }) }}
          </p>
          <div class="flex justify-between">
            <span>{{ $t('events.newcomer.label.unlockCountdown') }}</span>
            <span class="font-mono font-semibold">{{ countdown }}</span>
          </div>
          <p class="text-xs text-muted">
            {{ $t('events.newcomer.hint.countdown') }}
          </p>
          <p>{{ $t('events.newcomer.label.unlockableBonus') }}: {{ formatAmountFixed2(progress.unlocked_bonus_amount) }}</p>
          <div class="flex gap-2">
            <NuxtLink
              to="/topup"
              class="flex-1 rounded bg-accent px-4 py-2 text-center text-inverse"
            >
              {{ $t('events.newcomer.action.depositUnlock') }}
            </NuxtLink>
            <button
              type="button"
              class="flex-1 rounded border border-line px-4 py-2 disabled:opacity-40"
              :disabled="!canConfirmDeposit || busy === 'newbie-challenge/confirm-deposit'"
              @click="newbie.confirmDeposit()"
            >
              {{ busy === 'newbie-challenge/confirm-deposit'
                ? $t('events.newcomer.label.confirming')
                : $t('events.newcomer.action.nextStep') }}
            </button>
          </div>
          <p class="text-xs text-muted">
            {{ $t('events.newcomer.text.depositNotice') }}
          </p>
        </section>

        <!-- 步驟 4：彩金流水 -->
        <section
          v-else-if="step === 4 && progress"
          class="space-y-3 rounded border border-line bg-surface p-4 text-sm"
        >
          <h2 class="font-semibold">
            {{ $t('events.newcomer.title.bonusTurnover') }}
          </h2>
          <p class="text-muted">
            {{ $t('events.newcomer.hint.bonusTurnover') }}
          </p>
          <p>{{ $t('events.newcomer.label.statusBonusPending') }}: {{ formatAmountFixed2(progress.pending_bonus_amount) }}</p>
          <div class="space-y-1">
            <div class="flex justify-between">
              <span>{{ $t('events.newcomer.label.bonusTurnoverDone') }}</span>
              <span>{{ formatAmountFixed2(progress.bonus_flow_reach) }}</span>
            </div>
            <div class="flex justify-between">
              <span>{{ $t('events.newcomer.label.bonusTurnoverLeft') }}</span>
              <span>{{ formatAmountFixed2(progress.bonus_flow_remaining) }}</span>
            </div>
            <div class="h-2 overflow-hidden rounded bg-line">
              <div
                class="h-full bg-accent"
                :style="{ width: `${Number(progress.bonus_flow_percentage ?? 0)}%` }"
              />
            </div>
            <p class="text-xs text-muted">
              {{ $t('events.newcomer.label.targetBonusTurnover', { value: formatAmountFixed2(progress.bonus_flow_target) }) }}
            </p>
          </div>
          <p>{{ $t('events.newcomer.label.lockedPrincipal') }}: {{ formatAmountFixed2(progress.principal_total) }}</p>
          <button
            type="button"
            class="w-full rounded bg-accent px-4 py-2 text-inverse disabled:opacity-40"
            :disabled="!canClaimBonus || busy === 'newbie-challenge/claim-bonus'"
            @click="newbie.claimBonus()"
          >
            {{ busy === 'newbie-challenge/claim-bonus'
              ? $t('events.newcomer.label.claiming')
              : $t('events.newcomer.action.claimBonus') }}
          </button>
        </section>

        <!-- 步驟 5：完成 -->
        <section
          v-else-if="step === 5 && progress"
          class="space-y-2 rounded border border-line bg-surface p-4 text-sm"
        >
          <h2 class="font-semibold">
            {{ $t('events.newcomer.title.completed') }}
          </h2>
          <p class="text-muted">
            {{ $t('events.newcomer.hint.completedLocked', { value: formatAmountFixed2(progress.pending_bonus_amount) }) }}
          </p>
          <p>{{ $t('events.newcomer.label.bonusClaimed') }}</p>
        </section>

        <!-- 放棄挑戰：參加後才顯示 -->
        <div v-if="progress">
          <button
            type="button"
            class="text-sm text-muted underline"
            @click="confirmingQuit = true"
          >
            {{ $t('events.newcomer.action.quit') }}
          </button>
          <div
            v-if="confirmingQuit"
            class="mt-2 rounded border border-line p-3 text-sm"
          >
            <p>{{ $t('events.newcomer.msg.quitConfirm') }}</p>
            <div class="mt-2 flex gap-2">
              <button
                type="button"
                class="rounded border px-3 py-1"
                @click="onQuit"
              >
                {{ $t('common.ui.action.confirm') }}
              </button>
              <button
                type="button"
                class="rounded border px-3 py-1"
                @click="confirmingQuit = false"
              >
                {{ $t('common.ui.action.cancel') }}
              </button>
            </div>
          </div>
        </div>
      </template>
    </template>
  </div>
</template>
