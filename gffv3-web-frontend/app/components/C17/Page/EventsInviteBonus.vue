<script setup lang="ts">
import { formatAmountFixed2 } from '~/utils/events-core'

/**
 * 邀請好友獎勵活動（tp_event = 2）。event/list 的第三方活動卡導到這裡，
 * 之前這個路由不存在，點了就 404。
 *
 * 資料全來自 event-invite/list：setting 是活動與累計數字，reward_card 是每位好友一張卡。
 * 「領取獎金」只在後端 can_receive > 0 時可按，前端不自己算解鎖條件。
 */
const {
  setting,
  rewardCards,
  claiming,
  canReceive,
  loading,
  terms,
  inviteEntry,
  claim,
  cardTasks,
} = useEventsInviteBonusPage()
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-lg font-semibold">
      {{ setting?.title || $t('events.invite.title.referFriend') }}
    </h1>
    <p class="text-sm text-muted">
      {{ setting?.content || $t('events.invite.title.subtitle') }}
    </p>

    <p
      v-if="loading"
      class="text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>

    <template v-if="setting">
      <!-- 累計數字 -->
      <section class="grid grid-cols-2 gap-3">
        <div class="rounded border border-line bg-surface p-3">
          <p class="text-xs text-muted">
            {{ $t('events.invite.label.invitedCount') }}
          </p>
          <p class="text-lg font-semibold">
            {{ setting.invite_count ?? 0 }}
          </p>
        </div>
        <div class="rounded border border-line bg-surface p-3">
          <p class="text-xs text-muted">
            {{ $t('events.invite.label.totalUnlocked') }}
          </p>
          <p class="text-lg font-semibold">
            {{ formatAmountFixed2(setting.total_point) }}
          </p>
        </div>
        <div class="rounded border border-line bg-surface p-3">
          <p class="text-xs text-muted">
            {{ $t('events.invite.label.totalReceived') }}
          </p>
          <p class="text-lg font-semibold">
            {{ formatAmountFixed2(setting.total_receive) }}
          </p>
        </div>
        <div class="rounded border border-line bg-surface p-3">
          <p class="text-xs text-muted">
            {{ $t('events.invite.label.claimableBalance') }}
          </p>
          <p class="text-lg font-semibold text-accent">
            {{ formatAmountFixed2(setting.can_receive) }}
          </p>
        </div>
      </section>

      <div class="flex gap-2">
        <button
          type="button"
          class="flex-1 rounded bg-accent px-4 py-2 text-inverse disabled:opacity-40"
          :disabled="!canReceive"
          @click="claim"
        >
          {{ claiming ? $t('events.invite.action.claiming') : $t('events.invite.action.claimReward') }}
        </button>
        <NuxtLink
          :to="inviteEntry"
          class="flex-1 rounded border border-line px-4 py-2 text-center"
        >
          {{ $t('events.invite.action.inviteFriends') }}
        </NuxtLink>
      </div>

      <!-- 解鎖條件：金額全部照後端給的設定顯示 -->
      <section class="rounded border border-line bg-surface p-4 text-sm">
        <h2 class="mb-2 font-semibold">
          {{ $t('events.invite.hint.taskDescription') }}
        </h2>
        <ul class="space-y-1">
          <li class="flex justify-between gap-3">
            <span>{{ $t('events.invite.hint.taskKyc') }}</span>
            <span>{{ formatAmountFixed2(setting.realname_reward) }}</span>
          </li>
          <li class="flex justify-between gap-3">
            <span>{{ $t('events.invite.hint.taskDepositPrefix') }} {{ formatAmountFixed2(setting.deposit_point) }}</span>
            <span>{{ formatAmountFixed2(setting.deposit_reward) }}</span>
          </li>
          <li class="flex justify-between gap-3">
            <span>{{ $t('events.invite.unit.taskBetPrefix') }} {{ formatAmountFixed2(setting.bet_point) }}</span>
            <span>{{ formatAmountFixed2(setting.bet_reward) }}</span>
          </li>
        </ul>
      </section>
    </template>

    <!-- 好友卡 -->
    <section class="space-y-2">
      <h2 class="font-semibold">
        {{ $t('events.invite.label.friendReward') }}
      </h2>
      <p
        v-if="!rewardCards.length"
        class="text-sm text-muted"
      >
        {{ $t('events.invite.empty.noFriend') }}
      </p>
      <ul
        v-else
        class="space-y-2"
      >
        <li
          v-for="(card, index) in rewardCards"
          :key="`${card.username}-${index}`"
          class="rounded border border-line bg-surface p-3 text-sm"
        >
          <div class="flex items-center justify-between gap-3">
            <span class="font-semibold">{{ card.username }}</span>
            <span class="text-xs text-muted">{{ card.created_at }}</span>
          </div>
          <ul class="mt-2 space-y-1 text-xs">
            <li
              v-for="task in cardTasks(card)"
              :key="task.labelKey"
              class="flex justify-between gap-3"
            >
              <span>{{ $t(task.labelKey) }}</span>
              <span :class="task.done ? 'text-success' : 'text-muted'">
                {{ task.done ? $t('events.invite.label.statusOngoing') : $t('events.invite.label.notAchieved') }}
              </span>
            </li>
            <li class="flex justify-between gap-3">
              <span>{{ $t('events.invite.label.validBet') }}</span>
              <span>{{ formatAmountFixed2(card.bet) }}</span>
            </li>
            <li class="flex justify-between gap-3">
              <span>{{ $t('events.invite.label.pendingReceipt') }}</span>
              <span>{{ formatAmountFixed2(card.point) }}</span>
            </li>
            <li class="flex justify-between gap-3">
              <span>{{ $t('events.invite.label.receivedAmount') }}</span>
              <span>{{ formatAmountFixed2(card.receive) }}</span>
            </li>
          </ul>
        </li>
      </ul>
    </section>

    <!-- 活動條款 -->
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
