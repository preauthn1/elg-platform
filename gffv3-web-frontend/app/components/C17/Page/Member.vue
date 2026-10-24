<script setup lang="ts">
import { MEMBER_PATH, hasValue } from '#shared/utils/member'

/**
 * 個人資料頁（設計稿 Profile-page，390×844）。量到的規格：
 *
 *   會員卡外框  Rectangle 1969  358×200 @ 17,67   r8  #1565FF 10%
 *     會員列                    358×56            頭像 32 @ 29,78、帳號 @ 68,87、
 *                                                 VIP 膠囊 40×20 r4 @ 151,84、
 *                                                 徽章 36 @ 309,76、箭頭 8×12 @ 355,88
 *     錢包卡    bg44            358×144 @ 17,123  r8  #FFFFFF→#D8FF1A 176°
 *       餘額列  Rectangle 1976  338×28  @ 27,151  r8  #FFFFFF 80%
 *       三格    deposit…        104×60  @ 27,193  r8  #FFFFFF→#E1E9FC 133° 80%
 *   功能格      Rectangle 1791  80×80   @ 17,287  r8  #FFFFFF（第二列 @ 17,381）
 *
 * 外框只有最上面 56 露出來——錢包卡與它同寬同底邊，剛好蓋住其餘部分。
 * 頂部沒有 AppHeader，下載列下面直接就是會員卡，所以 hideAppHeader。
 *
 * 頭像旁邊顯示的是**帳號**（設計稿寫 USER123456），不是暱稱——舊前台的個人資料卡
 * 也是把 username 與 nickname 當兩個不同欄位分開列，混成一個會看不出登入用的是哪一個。
 *
 * 設計稿沒有畫「補真實姓名／實名認證」這兩個入口，但它們是**有條件才出現的流程閘門**，
 * 拿掉等於那兩條流程沒有入口，所以留在功能格下面當提示列。
 */
const {
  vipStore,
  profile,
  uploadFailed,
  uploading,
  checking,
  refreshing,
  currency,
  balanceText,
  showSetFullname,
  showRealNameVerification,
  walletActions,
  entries,
  vipVisible,
  onAvatarChange,
  onRefresh,
} = useMemberCenter()

/** 骨架的出現時機由 useSkeleton 決定，不可以直接綁 loading（見該 composable 的說明） */
const showSkeleton = useSkeleton(() => checking.value)
</script>

<template>
  <div class="flex flex-col gap-5 px-(--c17-pad-x) py-(--c17-gap-4)">
    <template v-if="showSkeleton">
      <UiSkeleton class="aspect-[39/14] w-full rounded-lg" />
      <div
        v-for="i in 3"
        :key="i"
        class="flex items-center gap-3"
      >
        <UiSkeleton class="size-10 shrink-0 rounded-lg" />
        <UiSkeleton
          class="flex-1"
          :rows="2"
        />
      </div>
    </template>

    <template v-else>
      <!-- 會員列與錢包卡共用同一個外框（Rectangle 1969），露出來的只有上面那 56 -->
      <section
        class="overflow-hidden rounded-(--c17-radius-sm)"
        :style="{ background: 'var(--c17-profile-card-bg)' }"
      >
        <div class="flex h-14 items-center px-(--c17-gap-3)">
          <!-- 設計稿沒畫換頭像的入口，但這個功能本來就有：點頭像就是換 -->
          <label class="relative size-8 shrink-0 cursor-pointer">
            <img
              v-if="hasValue(profile?.image)"
              :src="String(profile?.image)"
              alt=""
              class="size-8 rounded-full object-cover"
            >
            <svg
              v-else
              viewBox="0 0 32 32"
              class="size-8"
              fill="none"
              :style="{ color: 'var(--c17-ink)' }"
              aria-hidden="true"
            >
              <circle
                cx="16"
                cy="16"
                r="15"
                stroke="currentColor"
                stroke-width="2"
              />
              <circle
                cx="16"
                cy="12.5"
                r="4.5"
                fill="currentColor"
              />
              <path
                d="M7.5 25.5a8.5 8.5 0 0 1 17 0z"
                fill="currentColor"
              />
            </svg>
            <input
              type="file"
              accept="image/*"
              class="absolute inset-0 size-full opacity-0"
              :disabled="uploading"
              @change="onAvatarChange"
            >
          </label>

          <p
            class="ml-[7px] min-w-0 truncate font-bold uppercase"
            :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
          >
            {{ profile?.username }}
          </p>

          <span
            v-if="example.invalid"
            class="ml-(--c17-gap-2) flex h-5 w-10 shrink-0 items-center justify-center rounded-(--c17-radius-xs) font-bold uppercase"
            :style="{
              background: 'var(--c17-accent)',
              color: 'var(--c17-accent-ink)',
              fontSize: 'var(--c17-text-link)',
            }"
          >VIP {{ vipStore.level }}</span>

          <div class="flex-1" />

          <NuxtLink
            v-if="vipVisible"
            to="/vip"
            class="flex shrink-0 items-center gap-(--c17-gap-2)"
            :aria-label="$t('vip.overview.title.vip')"
          >
            <img
              v-if="vipStore.currentVip?.image"
              :src="vipStore.currentVip.image"
              alt=""
              class="size-9 object-contain"
            >
            <svg
              viewBox="0 0 8 12"
              class="h-3 w-2"
              fill="currentColor"
              :style="{ color: 'var(--c17-primary)' }"
              aria-hidden="true"
            >
              <path d="M1.9 0 .5 1.4 5.1 6 .5 10.6 1.9 12 7.9 6z" />
            </svg>
          </NuxtLink>
        </div>

        <!-- 錢包卡 bg44：與外框同寬同底邊 -->
        <div
          class="rounded-(--c17-radius-sm) px-[10px] py-[14px]"
          :style="{ background: 'var(--c17-wallet-bg)', boxShadow: 'var(--c17-shadow-card)' }"
        >
          <p
            class="px-5"
            :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }"
          >
            {{ $t('wallet.overview.label.balance') }}
          </p>

          <div
            class="mt-[2px] flex h-7 items-center gap-[26px] rounded-(--c17-radius-sm) px-5"
            :style="{ background: 'var(--c17-profile-pill-bg)', boxShadow: 'var(--c17-shadow-panel)' }"
          >
            <span :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-primary)' }">{{ currency }}</span>
            <span
              class="truncate font-bold tabular-nums"
              :style="{ fontSize: 'var(--c17-text-title)', color: 'var(--c17-primary)' }"
            >{{ balanceText }}</span>
            <button
              type="button"
              class="ml-auto shrink-0 disabled:opacity-40"
              :disabled="refreshing"
              :aria-label="$t('auth.captcha.action.refresh')"
              @click="onRefresh"
            >
              <img
                src="/skins/c17/profile/refresh.webp"
                alt=""
                class="size-[22px] object-contain"
                :class="refreshing ? 'animate-spin' : ''"
              >
            </button>
          </div>

          <div class="mt-[14px] grid grid-cols-3 gap-x-(--c17-gap-3)">
            <NuxtLink
              v-for="action in walletActions"
              :key="action.key"
              :to="action.to"
              class="flex h-[60px] flex-col items-center justify-center gap-px rounded-(--c17-radius-sm) px-1"
              :style="{ background: 'var(--c17-profile-tile-bg)', boxShadow: 'var(--c17-shadow-panel)' }"
            >
              <img
                :src="`/skins/c17/profile/${action.key}.webp`"
                alt=""
                class="size-[26px] shrink-0 object-contain"
              >
              <span
                class="w-full truncate text-center font-semibold"
                :style="{ fontSize: 'var(--c17-text-note)', color: 'var(--c17-ink)' }"
              >
                {{ $t(action.labelKey) }}
              </span>
            </NuxtLink>
          </div>
        </div>
      </section>

      <!-- 功能格：4 欄 80×80，欄距 12、列距 14 -->
      <ul class="grid grid-cols-4 gap-x-(--c17-gap-3) gap-y-[14px]">
        <li
          v-for="entry in entries"
          :key="entry.key"
        >
          <NuxtLink
            :to="entry.to"
            class="flex aspect-square flex-col items-center justify-center gap-1 rounded-(--c17-radius-sm) px-1"
            :style="{ background: 'var(--c17-surface)', boxShadow: 'var(--c17-shadow-panel)' }"
          >
            <img
              :src="`/skins/c17/profile/${entry.key}.webp`"
              alt=""
              class="size-[26px] shrink-0 object-contain"
            >
            <span
              class="line-clamp-2 h-[26px] w-full text-center font-semibold leading-[13px]"
              :style="{ fontSize: 'var(--c17-text-note)', color: 'var(--c17-ink)' }"
            >
              {{ $t(entry.labelKey) }}
            </span>
          </NuxtLink>
        </li>
      </ul>

      <!-- 設計稿沒有這兩條，但它們是有條件才出現的流程閘門，拿掉等於那兩條流程沒有入口 -->
      <NuxtLink
        v-if="showSetFullname"
        :to="MEMBER_PATH.setFullname"
        class="text-accent"
        :style="{ fontSize: 'var(--c17-text-link)' }"
      >
        {{ $t('member.realname.action.addRealName') }}
      </NuxtLink>
      <NuxtLink
        v-if="showRealNameVerification"
        :to="MEMBER_PATH.realNameVerification"
        class="text-accent"
        :style="{ fontSize: 'var(--c17-text-link)' }"
      >
        {{ $t('member.realname.title.verification') }}
      </NuxtLink>

      <p
        v-if="uploadFailed"
        class="text-sm text-danger"
      >
        {{ $t('common.file.error.uploadFailed') }}
      </p>
    </template>
  </div>
</template>
