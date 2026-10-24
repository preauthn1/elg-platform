<script setup lang="ts">
import { decFormat } from '#shared/utils/amount'
import { buildTgShareUrl } from '#shared/utils/agent'

/**
 * 設計稿 Invite-Friends 的上半部：banner ＋ 推薦連結 ＋ 分享列 ＋ 本期佣金 ＋ 四格人數。
 * 資料來自 member-team/info。
 *
 * 量到的值：
 *   banner      358×120 @16,67  圓角 8、投影 #7A859942 X3 Y3 模糊 4
 *   連結膠囊    240×28  @29,147 圓角 8、白 80%、投影 #1A1F3642 X2 Y2 模糊 2
 *   Copy        80×32   @281,145 圓角 8、#D8FF1A、字 PJS ExtraBold 14 #1A1F36
 *   My QR Code  172×32  @17,201 圓角 8、底 #F1F4F8、1pt #1565FF 內框、字 PJS ExtraBold 14 #1565FF
 *   分享鈕      50×32   @201/262/323,201 圓角 8、白底，圖示 20×16
 *   佣金卡      358×60  @16,274 圓角 8、#FFFFFF→#D8FF1A 172°、投影 #7A859933 X3 Y3 模糊 6
 *   人數磚      80×80   @17/109/201/293,348 圓角 8、白底、投影 #1A1F3642 X2 Y2 模糊 2
 */
const props = defineProps<{
  tgBotUsername?: string | null
  tgShareText?: string
}>()

const { info, shareLinks, fetchInfo } = useMemberTeam()
const { currencyLabel: currencyName } = useSite()
const { t } = useI18n()

const copied = ref(false)
const refreshing = ref(false)

const inviteLink = computed(() => shareLinks.value?.copyLink ?? '')
const tgShareUrl = computed(() => (inviteLink.value ? buildTgShareUrl(inviteLink.value, props.tgShareText ?? '') : ''))
const fbShareUrl = computed(() => (
  inviteLink.value ? `https://example.invalid/sharer/sharer.php?u=${encodeURIComponent(inviteLink.value)}` : ''
))

/** 四格人數，順序照設計稿由左至右。 */
const tiles = computed(() => [
  { key: 'member', labelKey: 'agent.team.label.directMembers', value: info.value?.directPlayerCount ?? 0 },
  { key: 'new-member', labelKey: 'agent.team.label.todayNewMembers', value: info.value?.addedToday ?? 0 },
  { key: 'new-team', labelKey: 'agent.team.label.todayNewTeamMembers', value: info.value?.addedTodayGroupMember ?? 0 },
  { key: 'total-team', labelKey: 'agent.team.label.totalMembers', value: info.value?.totalGroupMember ?? 0 },
])

async function load() {
  if (refreshing.value) return
  refreshing.value = true
  try {
    await fetchInfo({ tgBotUsername: props.tgBotUsername })
  }
  finally {
    refreshing.value = false
  }
}

async function copyLink() {
  if (!inviteLink.value || !import.meta.client) return
  try {
    await navigator.clipboard.writeText(inviteLink.value)
    copied.value = true
  }
  catch {
    copied.value = false
  }
}

/**
 * 系統分享面板。桌機與不支援的瀏覽器沒有 navigator.share，
 * 那時就退回複製連結，不要讓按鈕點下去毫無反應。
 */
async function shareNative() {
  if (!inviteLink.value || !import.meta.client) return
  const nav = navigator as Navigator & { share?: (data: ShareData) => Promise<void> }
  if (!nav.share) {
    await copyLink()
    return
  }
  try {
    await nav.share({ url: inviteLink.value, text: props.tgShareText ?? t('events.invite.action.inviteFriends') })
  }
  catch {
    // 使用者自己取消分享，不是錯誤
  }
}

onMounted(load)
</script>

<template>
  <section class="flex flex-col gap-(--c17-gap-3)">
    <!-- banner 與壓在上面的推薦連結：連結與 Copy 貼齊 banner 底部往上 10 -->
    <div class="relative">
      <img
        src="/skins/c17/invite/banner.webp"
        alt=""
        class="block aspect-[358/120] w-full rounded-(--c17-radius-sm) object-cover"
        :style="{ boxShadow: 'var(--c17-shadow-banner)' }"
      >
      <div class="absolute inset-x-[13px] bottom-[10px] flex items-center gap-[12px]">
        <span
          class="flex h-7 min-w-0 flex-1 items-center truncate rounded-(--c17-radius-sm) px-(--c17-gap-2) font-semibold"
          :style="{
            background: 'var(--c17-profile-pill-bg)',
            boxShadow: 'var(--c17-shadow-panel)',
            fontSize: 'var(--c17-text-link)',
            color: 'var(--c17-ink)',
          }"
        >{{ inviteLink }}</span>
        <button
          type="button"
          class="h-8 w-20 shrink-0 rounded-(--c17-radius-sm) font-extrabold"
          :style="{
            background: 'var(--c17-accent)',
            boxShadow: 'var(--c17-shadow-panel)',
            fontSize: 'var(--c17-text-body)',
            color: 'var(--c17-accent-ink)',
          }"
          @click="copyLink"
        >
          {{ copied ? $t('common.ui.msg.copied') : $t('common.ui.action.copy') }}
        </button>
      </div>
    </div>

    <!-- QR Code ＋ 三顆分享鈕 -->
    <div class="flex items-center gap-[11px]">
      <NuxtLink
        to="/agent/qrcode"
        class="flex h-8 min-w-0 flex-1 items-center justify-center gap-(--c17-gap-2) rounded-(--c17-radius-sm) border border-accent font-extrabold"
        :style="{
          background: 'var(--c17-primary-ink)',
          fontSize: 'var(--c17-text-body)',
          color: 'var(--c17-primary)',
        }"
      >
        <img
          src="/skins/c17/invite/qr.webp"
          alt=""
          class="h-4 w-5 shrink-0 object-contain"
        >
        <span class="truncate">{{ $t('agent.promote.label.qrCode') }}</span>
      </NuxtLink>
      <a
        :href="tgShareUrl"
        target="_blank"
        rel="noopener"
        class="flex h-8 w-[50px] shrink-0 items-center justify-center rounded-(--c17-radius-sm) bg-surface"
        :aria-label="$t('agent.promote.action.shareTelegram')"
      >
        <img
          src="/skins/c17/invite/telegram.webp"
          alt=""
          class="h-4 w-5 object-contain"
        >
      </a>
      <a
        :href="fbShareUrl"
        target="_blank"
        rel="noopener"
        class="flex h-8 w-[50px] shrink-0 items-center justify-center rounded-(--c17-radius-sm) bg-surface"
        :aria-label="$t('agent.promote.action.share')"
      >
        <img
          src="/skins/c17/invite/facebook.webp"
          alt=""
          class="h-4 w-5 object-contain"
        >
      </a>
      <button
        type="button"
        class="flex h-8 w-[50px] shrink-0 items-center justify-center rounded-(--c17-radius-sm) bg-surface"
        :aria-label="$t('agent.promote.action.share')"
        @click="shareNative"
      >
        <img
          src="/skins/c17/invite/share.webp"
          alt=""
          class="h-4 w-5 object-contain"
        >
      </button>
    </div>

    <span
      class="block h-px"
      :style="{ background: 'var(--c17-accent)' }"
      aria-hidden="true"
    />

    <!-- 本期佣金：白→螢光的漸層卡，右邊一顆重新整理 -->
    <div
      class="flex h-15 flex-col items-center justify-center rounded-(--c17-radius-sm)"
      :style="{ background: 'var(--c17-wallet-bg)', boxShadow: 'var(--c17-shadow-card)' }"
    >
      <span :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }">
        {{ $t('agent.commission.label.current') }}
      </span>
      <span class="flex items-center gap-(--c17-gap-2)">
        <span :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-primary)' }">{{ currencyName }}</span>
        <b
          class="font-extrabold"
          :style="{ fontSize: 'var(--c17-text-hero)', color: 'var(--c17-primary)' }"
        >{{ decFormat(info?.todayCommission ?? 0, 2) }}</b>
        <button
          type="button"
          class="disabled:opacity-40"
          :disabled="refreshing"
          :aria-label="$t('auth.captcha.action.refresh')"
          @click="load"
        >
          <img
            src="/skins/c17/invite/refresh.webp"
            alt=""
            class="size-[22px] object-contain"
            :class="refreshing ? 'animate-spin' : ''"
          >
        </button>
      </span>
    </div>

    <!-- 四格人數 -->
    <ul class="grid grid-cols-4 gap-[12px]">
      <li
        v-for="tile in tiles"
        :key="tile.key"
        class="flex aspect-square flex-col items-center justify-center gap-(--c17-gap-1) rounded-(--c17-radius-sm) bg-surface px-(--c17-gap-1)"
        :style="{ boxShadow: 'var(--c17-shadow-panel)' }"
      >
        <span
          class="text-center font-semibold"
          :style="{ fontSize: 'var(--c17-text-note)', color: 'var(--c17-ink)', lineHeight: '12px' }"
        >{{ $t(tile.labelKey) }}</span>
        <span class="flex items-center gap-[6px]">
          <img
            :src="`/skins/c17/invite/${tile.key}.webp`"
            alt=""
            class="size-[22px] shrink-0 rounded-(--c17-radius-xs) object-contain"
          >
          <b
            class="font-extrabold"
            :style="{ fontSize: 'var(--c17-text-hero)', color: 'var(--c17-ink)' }"
          >{{ tile.value }}</b>
        </span>
      </li>
    </ul>

    <span
      class="block h-px"
      :style="{ background: 'var(--c17-accent)' }"
      aria-hidden="true"
    />
  </section>
</template>
