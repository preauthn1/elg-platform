<script setup lang="ts">
/** C17 場館前頁：設計稿沒有這一張，沿用 C17 內頁的語彙（深色標題列 ＋ 白卡 ＋ 滿版主要按鈕）。邏輯在 useVendorAmount。 */
const {
  vendor,
  bets,
  entering,
  amounts,
  carriedBalance,
  exchangeText,
  targetPath,
  start,
} = useVendorAmount()
</script>

<template>
  <div v-if="vendor">
    <C17PageHeader
      :title="vendor.name ?? ''"
      fallback="/vendors"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <img
        v-if="vendor.front_page_image"
        :src="vendor.front_page_image"
        :alt="vendor.name ?? ''"
        class="block w-full rounded-(--c17-radius-sm)"
        :style="{ boxShadow: 'var(--c17-shadow-panel)' }"
      >

      <!-- 帶入餘額：舊版放在錢包列上面那一塊 -->
      <section
        class="flex items-center justify-between gap-(--c17-gap-3) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3)"
        :style="{ boxShadow: 'var(--c17-shadow-panel)', fontSize: 'var(--c17-text-link)' }"
      >
        <span class="text-muted">{{ $t('wallet.overview.label.carriedBalance') }}</span>
        <span
          class="font-bold tabular-nums"
          :style="{ color: 'var(--c17-primary)', fontSize: 'var(--c17-text-body)' }"
        >{{ carriedBalance }}</span>
      </section>

      <!-- 錢包餘額 + 儲值入口 -->
      <section
        class="flex items-center gap-(--c17-gap-3) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3)"
        :style="{ boxShadow: 'var(--c17-shadow-panel)' }"
      >
        <ul class="flex flex-1 flex-col gap-(--c17-gap-1)">
          <li
            v-for="row in amounts"
            :key="row.key"
            class="flex justify-between gap-(--c17-gap-3)"
            :style="{ fontSize: 'var(--c17-text-link)' }"
          >
            <span class="text-muted">{{ row.label }}</span>
            <span
              class="font-bold tabular-nums"
              :style="{ color: 'var(--c17-primary)' }"
            >{{ row.value }}</span>
          </li>
        </ul>
        <NuxtLink
          to="/topup"
          class="flex h-8 shrink-0 items-center rounded-(--c17-field-radius) border border-accent px-(--c17-gap-3) font-bold"
          :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-primary)' }"
        >
          {{ $t('deposit.entry.title.deposit') }}
        </NuxtLink>
      </section>

      <p
        v-if="exchangeText"
        :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }"
      >
        {{ $t('common.amount.label.exchangeRate') }}: {{ exchangeText }}
      </p>

      <UiButton
        :disabled="!targetPath || entering"
        @click="start"
      >
        {{ $t('competitions.ranking.action.startGame') }}
      </UiButton>

      <p
        v-if="!targetPath"
        class="text-center"
        :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-muted)' }"
      >
        {{ $t('common.ui.label.maintenance') }}
      </p>

      <!-- 今日／昨日押注 -->
      <section
        v-if="bets"
        class="grid grid-cols-2 gap-(--c17-gap-3)"
      >
        <div
          class="rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3)"
          :style="{ boxShadow: 'var(--c17-shadow-panel)' }"
        >
          <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }">
            {{ $t('agent.performance.label.todayBet') }}
          </p>
          <p
            class="font-bold tabular-nums"
            :style="{ fontSize: 'var(--c17-text-body)', color: 'var(--c17-ink)' }"
          >
            {{ decFormat(bets.today_accumulated_bet, 2) }}
          </p>
        </div>
        <div
          class="rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3)"
          :style="{ boxShadow: 'var(--c17-shadow-panel)' }"
        >
          <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }">
            {{ $t('agent.performance.label.yesterdayBet') }}
          </p>
          <p
            class="font-bold tabular-nums"
            :style="{ fontSize: 'var(--c17-text-body)', color: 'var(--c17-ink)' }"
          >
            {{ decFormat(bets.yesterday_accumulated_bet, 2) }}
          </p>
        </div>
      </section>

      <!--
        舊皮這裡還有兩塊，新專案先不畫，理由寫在這裡：

        1. 遊戲返水表（gameReward）：要 three-hierarchy 的返水規則與各場館遊戲分類，
           新專案還沒有這兩支端點，不自己編。

        2. 規則提示（ruleTips）：舊皮是依品牌 if/else 挑 10 支不同的 key
           （games.vendor.text.limitNotice01～10）。照抄會把某一站的贏金上限
           顯示給別站看，是實際的錯誤。正確作法是收斂成一支 key、
           再用 i18n/overrides/<brand>/ 覆寫——這是還沒定案的 i18n 合併題，先留空。
      -->

      <!-- 已參加 V2 活動時的活動帳號進度（舊皮在這頁也有） -->
      <EventsV2AccountBar />
    </div>

    <EventsPrizeReminder />
  </div>
</template>
