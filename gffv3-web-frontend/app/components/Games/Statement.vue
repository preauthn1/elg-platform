<script setup lang="ts">
import { FOUR_D_VENDOR_IDS, pickGameUrl, showsVendorBalanceInHeader, VENDOR_ID } from '#shared/utils/games'
import type { FourDMaintenanceTip } from '~/composables/useGameLauncher'

/**
 * 進場前置頁（使用者協議）。設計稿沒有這一張，沿用 C17 內頁的語彙：
 * 深色標題列 ＋ 白卡 ＋ 滿版主要按鈕。
 */
const props = defineProps<{
  vendorId: number
  gameId?: number | null
}>()

const games = useGames()
const launcher = useGameLauncher()

const vendorName = ref('')
const giftcode = ref<0 | 1>(0)
/** 取網址／遊戲清單失敗：訊息已由 useApi 的全站彈窗呈現，這裡只決定要不要擋住進場按鈕 */
const failed = ref(false)
const loading = ref(true)
const agreed = ref(false)
const lastGameUrl = ref<string | null>(null)
const popupBlocked = ref(false)
const maintenanceTip = ref<FourDMaintenanceTip | null>(null)

const is4d = computed(() => FOUR_D_VENDOR_IDS.includes(props.vendorId))
const mustAgree = computed(() => !is4d.value && !agreed.value)
const usesFirstGame = computed(() => props.vendorId === VENDOR_ID.FIRST_GAME_FROM_LIST)

onMounted(async () => {
  maintenanceTip.value = launcher.fourDMaintenanceTip(props.vendorId)
  launcher.storeGameFromUrl()
  loading.value = true
  try {
    await launcher.syncSingleWallet(props.vendorId)
    const vendor = await games.fetchVendor(props.vendorId)
    if (vendor.data) {
      vendorName.value = vendor.data.name ?? ''
      giftcode.value = vendor.data.giftcode ?? 0
    }
    if (!usesFirstGame.value) {
      const res = await launcher.fetchGameUrl({ vendorId: props.vendorId, gameId: props.gameId ?? undefined })
      if (!isApiOk(res)) {
        failed.value = true
        return
      }
      lastGameUrl.value = pickGameUrl(res.data, launcher.isMobile.value)
    }
  }
  finally {
    loading.value = false
  }
})

async function onClick() {
  if (mustAgree.value) {
    agreed.value = true
    return
  }
  if (usesFirstGame.value) {
    let gameId = props.gameId ?? null
    if (gameId === null) {
      const res = await games.fetchGameList({ vendorId: props.vendorId, page: 1, perPage: 500 })
      if (!isApiOk(res)) {
        failed.value = true
        return
      }
      gameId = res.data.games[0]?.id ?? null
      if (gameId === null) return
    }
    await navigateTo(`/game/${gameId}`)
    return
  }
  if (!lastGameUrl.value) return
  try {
    launcher.openWindow(lastGameUrl.value)
    popupBlocked.value = false
  }
  catch {
    popupBlocked.value = true
  }
}
</script>

<template>
  <div>
    <C17PageHeader
      :title="vendorName || $t('site.legal.title.userAgreement')"
      fallback="/vendors"
      :balance="showsVendorBalanceInHeader(vendorId)"
      balance-source="vendor"
      :giftcode="giftcode"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <span
        v-if="giftcode === 1"
        class="self-start rounded-(--c17-field-radius) bg-surface-2 px-(--c17-gap-2) py-(--c17-gap-1) font-bold"
        :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-primary)' }"
      >{{ $t('games.vendor.label.rollingGames') }}</span>

      <p
        v-if="failed"
        class="text-(length:--c17-text-link) text-danger"
      >
        {{ $t('games.launch.error.network') }}
      </p>

      <template v-else>
        <section
          v-if="!is4d"
          class="rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]"
        >
          <p
            class="whitespace-pre-wrap"
            :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
          >
            {{ $t('site.legal.text.userAgreement') }}
          </p>
        </section>

        <UiButton
          :disabled="loading"
          @click="onClick"
        >
          {{ $t(mustAgree ? 'games.launch.action.agree' : 'games.launch.action.clickToEnter') }}
        </UiButton>

        <p
          v-if="popupBlocked"
          class="rounded-(--c17-field-radius) border border-warning bg-warning-soft p-(--c17-gap-3) text-(length:--c17-text-link) text-warning"
        >
          {{ $t('games.launch.hint.newWindow') }}
        </p>

        <p
          v-if="!is4d"
          class="text-(length:--c17-text-label) text-danger"
        >
          {{ $t('site.legal.text.voidAbnormalBet') }}
        </p>

        <p
          v-if="is4d"
          class="text-(length:--c17-text-label) text-muted"
        >
          {{ $t('site.legal.text.abnormalBetting4d') }}
        </p>

        <p
          v-if="maintenanceTip"
          class="rounded-(--c17-field-radius) border border-warning bg-warning-soft p-(--c17-gap-3) text-(length:--c17-text-link) text-warning"
        >
          {{ $t('games.launch.text.maintenance4d', maintenanceTip) }}
        </p>
      </template>
    </div>
  </div>
</template>
