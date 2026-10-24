<script setup lang="ts">
import { decFormat } from '#shared/utils/amount'
import { checkinSuccessText } from '~/utils/events-core'

/**
 * 每日簽到的七天磚（設計稿 Daily-check in-pop out 白卡內容）。
 *
 * 量到的值：
 *   磚   66×114，第一列 4 顆 @X39/121/203/285 Y248，第二列 3 顆 @X76/158/240 Y374，間距 82
 *   DAY  Inter Bold 12 全大寫 #F1F4F8，磚頂 +12
 *   金幣 30×26，磚頂 +41；金額 PJS Bold 16 置中 #1A1F36，磚頂 +76
 *   已領 16×16 綠勾（#63D27F→#409254 180°）＋ PJS SemiBold 10 的「已領取」
 *
 * 磚底是兩張圖：`daily-1`（藍）與 `daily-2`（金，只有最後一天用）。
 * **已領取的磚在設計稿是同一張圖套 Luminosity**，所以這裡用 `grayscale(1)` 去色，
 * 不是另外一張素材。金幣有三種份量，設計稿是照天數由小到大換，不是照金額。
 */
const { loggedIn } = useUserSession()
const { settings } = useSite()
const { t } = useI18n()
const { fetchRecords, records, available, status, checkin } = useCheckin()
const alert = useAlert()

const busy = ref(false)
const loaded = ref(false)

/** 未登入不顯示；登入後抓一次，沒活動（400）就整塊隱藏。 */
onMounted(async () => {
  if (!loggedIn.value) return
  await fetchRecords()
  loaded.value = true
})

/** 金幣份量照天數換，與設計稿一致：前五天小堆、第六天大堆、最後一天寶箱。 */
function coinArt(index: number, total: number): string {
  if (index === total - 1) return 'coin-3'
  if (index === total - 2) return 'coin-2'
  return 'coin-1'
}

const tiles = computed(() => records.value.map((record, index) => ({
  key: record.day,
  day: record.day,
  claimed: record.is_checkin,
  today: record.checkin_day,
  coin: coinArt(index, records.value.length),
  final: index === records.value.length - 1,
  amount: record.prize?.[0] ? decFormat(record.prize[0].prize_amount, 0) : '',
})))

/** 第一列 4 顆、第二列剩下的置中，與設計稿的 4＋3 一致。 */
const firstRow = computed(() => tiles.value.slice(0, 4))
const secondRow = computed(() => tiles.value.slice(4))

async function doCheckin() {
  if (busy.value) return
  busy.value = true
  try {
    const res = await checkin()
    // 失敗時 useApi 已經用全站彈窗顯示後端訊息，這裡只處理領到獎項的提示
    if (isApiOk(res)) {
      const prize = checkinSuccessText(res.data ?? [], {
        platformPoints: t('common.amount.label.platformPoint'),
        walletPointName: settings.value?.text_wallet_point || '',
      })
      alert.show(prize ? `${t('events.claim.msg.congratulations')} ${prize}` : t('events.checkin.msg.success'), 'success')
    }
  }
  finally {
    busy.value = false
  }
}
</script>

<template>
  <section
    v-if="loggedIn && loaded && available"
    class="flex flex-col items-center gap-(--c17-gap-4) rounded-(--c17-radius-sm) border-2 border-accent bg-surface px-(--c17-gap-3) py-(--c17-gap-4)"
    :style="{ boxShadow: 'var(--c17-shadow-banner)' }"
  >
    <ul
      v-for="(row, rowIndex) in [firstRow, secondRow]"
      :key="rowIndex"
      class="flex justify-center gap-[16px]"
    >
      <li
        v-for="tile in row"
        :key="tile.key"
        class="relative w-[66px] shrink-0"
      >
        <img
          :src="`/skins/c17/checkin/${tile.final ? 'tile-final' : 'tile'}.webp`"
          alt=""
          class="block w-full"
          :class="tile.claimed ? 'grayscale' : ''"
        >
        <!-- 磚上的文字與金幣照設計稿疊在圖上：DAY 在頂、金幣居中、金額在下 -->
        <span
          class="absolute inset-x-0 top-[12px] text-center font-bold uppercase"
          :style="{
            fontFamily: 'var(--c17-font-display)',
            fontSize: 'var(--c17-text-link)',
            color: 'var(--c17-primary-ink)',
          }"
        >{{ $t('events.checkin.label.day', { day: tile.day }) }}</span>
        <img
          :src="`/skins/c17/checkin/${tile.coin}.webp`"
          alt=""
          class="absolute inset-x-0 top-[41px] mx-auto h-[26px] w-auto"
          :class="tile.claimed ? 'grayscale' : ''"
        >
        <span
          v-if="tile.claimed"
          class="absolute inset-x-0 top-[68px] flex flex-col items-center gap-[2px]"
        >
          <img
            src="/skins/c17/checkin/check.webp"
            alt=""
            class="size-4"
          >
          <span
            class="font-semibold"
            :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }"
          >{{ $t('events.checkin.label.completed') }}</span>
        </span>
        <span
          v-else
          class="absolute inset-x-0 top-[76px] text-center font-bold"
          :style="{ fontSize: 'var(--c17-text-title)', color: 'var(--c17-ink)' }"
        >{{ tile.amount }}</span>
      </li>
    </ul>

    <!--
      CHECK-IN：設計稿是 120×40、圓角 20 的一張圖，字燒在圖裡，
      所以照取樣的顏色用 CSS 重建（token 見 skins/C17/theme.css）。
    -->
    <button
      type="button"
      class="h-10 w-[120px] rounded-[20px] font-extrabold uppercase disabled:grayscale"
      :style="{
        background: 'var(--c17-checkin-btn-bg)',
        borderBottom: '2px solid var(--c17-checkin-btn-lip)',
        color: 'var(--c17-checkin-btn-ink)',
        fontFamily: 'var(--c17-font-display)',
        fontSize: 'var(--c17-text-body)',
      }"
      :disabled="busy || status.checkedToday"
      @click="doCheckin"
    >
      {{ status.checkedToday ? $t('events.checkin.label.completed') : $t('events.checkin.title.dailyCheckin') }}
    </button>
  </section>
</template>
