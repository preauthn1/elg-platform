<script setup lang="ts">
import { decFormat } from '#shared/utils/amount'

/**
 * VIP（設計稿 VIP）：等級卡 → 「V<n> BENEFITS」分隔 → 權益清單 → 兩顆鈕。
 *
 * 權益列的四顆圖示已經到位（`public/skins/c17/vip/`，見 VipBenefitRows）。
 *
 * 等級卡的背景照（`clear-empty-photographer-studio-background-…`，358×160、圓角 20）
 * 在 XD 裡沒有被標記成可匯出資源，下載不到，所以改用 CSS 近似：`--c17-vip-card-bg`
 * 的取樣值來自設計稿算圖。之後拿到原圖只要換這一層背景，版面不用動。
 *
 * 獎勵領取、獎勵紀錄、紅利說明是既有功能，設計稿沒畫但不能拿掉，收在兩顆鈕下面。
 */
// 設計稿 VIP 最上面是下載列，沒有餘額列
const {
  loading,
  claimingId,
  info,
  contents,
  level,
  currentVip,
  nextVip,
  privileges,
  rewardHistory,
  panel,
  walletPointText,
  ruleParagraphs,
  showNextLevel,
  upgradeGap,
  relegationGap,
  progressPercent,
  panelTabs,
  togglePanel,
  openRecords,
  onClaim,
} = await useVipPage()
</script>

<template>
  <div class="flex flex-col gap-[var(--c17-gap-4)] pb-[var(--c17-gap-4)]">
    <h1
      class="font-extrabold uppercase"
      :style="{
        paddingInline: 'var(--c17-pad-x)',
        fontFamily: 'var(--c17-font-display)',
        fontSize: 'var(--c17-text-title)',
      }"
    >
      {{ $t('vip.overview.title.vip') }}
    </h1>

    <p
      v-if="loading && !info"
      class="text-center text-sm text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>

    <template v-if="info">
      <!--
        等級卡：設計稿的背景是一張攝影棚底圖（還沒交），先用既有的淺藍白漸層頂著。
        **不加陰影**：md 沒寫、算圖四邊取樣都是頁面底色。
      -->
      <section
        class="mx-[var(--c17-pad-x)] rounded-[1.25rem] p-[var(--c17-gap-4)]"
        :style="{ background: 'var(--c17-vip-card-bg)' }"
      >
        <div
          class="flex items-center justify-between gap-[var(--c17-gap-2)] font-bold"
          :style="{ fontSize: 'var(--c17-text-link)' }"
        >
          <span>{{ $t('vip.overview.label.currentLevel') }}</span>
          <!-- 設計稿把等級寫在一顆 40×20 圓角 4 的螢光色膠囊上 -->
          <span
            class="rounded-[var(--c17-radius-xs)] px-[var(--c17-gap-2)] py-[2px] uppercase"
            :style="{ background: 'var(--c17-accent)', color: 'var(--c17-accent-ink)' }"
          >VIP {{ level }}</span>
        </div>

        <div class="mt-[var(--c17-gap-3)] flex items-end gap-[var(--c17-gap-4)]">
          <img
            v-if="currentVip?.image"
            :src="currentVip.image"
            :alt="currentVip.name ?? ''"
            class="size-[100px] shrink-0 rounded-[var(--c17-radius-xs)] object-contain"
          >
          <div class="min-w-0 flex-1">
            <p
              class="text-right font-normal uppercase"
              :style="{ fontSize: 'var(--c17-text-link)' }"
            >
              <span v-if="showNextLevel">VIP {{ level + 1 }}</span>
            </p>
            <!-- 進度條：設計稿 260×12 圓角 4，底 #b4b4b4，已完成段是螢光漸層 -->
            <div
              class="mt-[var(--c17-gap-1)] h-3 w-full overflow-hidden rounded-[var(--c17-radius-xs)]"
              :style="{ background: 'var(--c17-faint)' }"
              role="progressbar"
              :aria-valuenow="Math.round(progressPercent)"
              aria-valuemin="0"
              aria-valuemax="100"
            >
              <div
                class="h-full rounded-[var(--c17-radius-xs)]"
                :style="{
                  width: `${progressPercent}%`,
                  background: 'linear-gradient(90deg, var(--c17-accent) 0%, var(--c17-accent-deep) 100%)',
                }"
              />
            </div>
            <p
              class="mt-[var(--c17-gap-2)] text-right font-bold"
              :style="{ fontSize: 'var(--c17-text-link)' }"
            >
              {{ decFormat(info.deposit_amount) }}
              <template v-if="nextVip">
                / {{ decFormat(nextVip.target_amount) }}
              </template>
              {{ $t('vip.condition.label.accumulatedDeposit') }}
            </p>
          </div>
        </div>
      </section>

      <!--
        設計稿沒畫升級／保級差額，但那是舊頁面就有的資訊（而且是會員最在意的數字），
        收成等級卡下面的小字，不要因為設計沒畫就掉掉。
      -->
      <div
        v-if="info"
        class="flex flex-col gap-[2px]"
        :style="{ paddingInline: 'var(--c17-pad-x)', fontSize: 'var(--c17-text-label)' }"
      >
        <p v-if="showNextLevel && nextVip">
          {{ $t('vip.upgrade.label.needMore') }}:
          <b>{{ decFormat(upgradeGap) }} {{ walletPointText }}</b>
          ({{ $t('vip.condition.title.depositTurnover', { value: 1 }) }})
        </p>
        <p v-if="currentVip">
          {{ $t('vip.condition.label.retention') }}:
          <b v-if="relegationGap > 0">{{ decFormat(relegationGap) }} {{ walletPointText }}</b>
          <span v-else>{{ $t('vip.upgrade.hint.relegationReached') }}</span>
        </p>
      </div>

      <!--
        分隔：「V<n> BENEFITS」置中，兩側各一條藍色細長三角。

        設計稿量到的是 `Polygon 1/2`：**4 寬 × 36 高、旋轉 270°／90°**，
        也就是畫面上 **36 長 × 4 厚**的細長條，不是短粗的三角形
        （原本寫成 8×8，看起來像兩顆實心箭頭）。
        文字 Plus Jakarta Sans Bold 12 #1565FF，與兩側各距 15。

        **尖端朝外**：把設計稿放大看，左邊那條是「左細右粗」，尖的一端在外側、
        粗的平頭貼著文字；右邊鏡像。所以左邊要用 border-right（畫出左尖的三角）、
        右邊用 border-left，寫反了會變成兩顆箭頭對著文字。
      -->
      <div
        class="flex items-center justify-center gap-[15px] font-bold uppercase"
        :style="{ color: 'var(--c17-primary)', fontSize: 'var(--c17-text-link)' }"
      >
        <span
          class="size-0 border-y-2 border-r-[36px] border-y-transparent"
          :style="{ borderRightColor: 'var(--c17-primary)' }"
          aria-hidden="true"
        />
        VIP {{ level }} {{ $t('vip.condition.title.benefit') }}
        <span
          class="size-0 border-y-2 border-l-[36px] border-y-transparent"
          :style="{ borderLeftColor: 'var(--c17-primary)' }"
          aria-hidden="true"
        />
      </div>

      <VipBenefitRows :content="currentVip ?? null" />

      <div
        class="flex flex-col gap-[var(--c17-gap-2)]"
        :style="{ paddingInline: 'var(--c17-pad-x)' }"
      >
        <UiButton @click="togglePanel('levels')">
          {{ $t('vip.overview.title.vip') }} {{ $t('vip.level.title.description') }}
        </UiButton>
        <UiButton
          variant="secondary"
          @click="togglePanel('rules')"
        >
          {{ $t('site.legal.title.termsConditions') }}
        </UiButton>
      </div>

      <!-- 以下是設計稿沒畫、但既有的功能：紅利說明、領取紀錄、獎勵領取 -->
      <div
        class="flex flex-wrap gap-(--c17-gap-2)"
        :style="{ paddingInline: 'var(--c17-pad-x)' }"
      >
        <button
          v-for="tab in panelTabs"
          :key="tab.key"
          type="button"
          class="flex h-8 shrink-0 items-center rounded-(--c17-field-radius) border px-(--c17-gap-3) font-bold"
          :style="{
            fontSize: 'var(--c17-text-link)',
            background: panel === tab.key ? 'var(--c17-primary)' : 'var(--c17-surface)',
            color: panel === tab.key ? 'var(--c17-primary-ink)' : 'var(--c17-ink)',
            borderColor: panel === tab.key ? 'var(--c17-primary)' : 'var(--c17-faint)',
          }"
          @click="tab.key === 'records' ? openRecords() : togglePanel(tab.key)"
        >
          {{ tab.label }}
        </button>
      </div>

      <section
        v-if="panel === 'bonus'"
        class="mx-(--c17-pad-x) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]"
      >
        <h2
          class="mb-(--c17-gap-2) font-bold"
          :style="{ fontSize: 'var(--c17-text-body)', color: 'var(--c17-ink)' }"
        >
          {{ $t('vip.rules.title.bonusExplanation') }}
        </h2>
        <VipLevelTable
          :contents="contents"
          mode="bonus"
        />
      </section>

      <!--
        等級說明是**彈窗**，不是頁面上展開的一塊——設計稿 VIP-open-all-VIP 就是
        疊在 VIP 頁上的白卡（358×600、圓角 8、**#D8FF1A 螢光綠 2pt 內框**，
        與 login 那張同一個 Rectangle 但框色不同，所以 UiModal 要指定 accent）。

        內容是一次看一級、左右箭頭翻頁的卡片，不是整張表格（表格在手機上要橫向捲）。
      -->
      <UiModal
        v-if="panel === 'levels'"
        accent="highlight"
        :title="`VIP ${$t('vip.level.title.description')}`"
        @close="panel = 'none'"
      >
        <VipLevelCarousel
          :contents="contents"
          :initial-level="level"
        />
      </UiModal>

      <!--
        條款與規則：舊專案是 VIP 頁自己的「規則說明」彈窗（vip2Rule1~5 ＋ 等級規則表），
        不是「關於」底下那份站台條款，所以不要導去 /about。
      -->
      <UiModal
        v-if="panel === 'rules'"
        accent="highlight"
        :title="$t('site.legal.title.termsConditions')"
        @close="panel = 'none'"
      >
        <div class="flex flex-col gap-(--c17-gap-2) text-(length:--c17-text-link) text-ink">
          <p
            v-for="(paragraph, index) in ruleParagraphs"
            :key="index"
            class="whitespace-pre-line"
          >
            {{ paragraph }}
          </p>
        </div>
        <VipLevelTable
          class="mt-(--c17-gap-3)"
          :contents="contents"
          mode="rules"
        />
      </UiModal>

      <section
        v-if="panel === 'records'"
        class="mx-(--c17-pad-x) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]"
      >
        <h2
          class="mb-(--c17-gap-2) font-bold"
          :style="{ fontSize: 'var(--c17-text-body)', color: 'var(--c17-ink)' }"
        >
          {{ $t('agent.common.nav.collectionRecord') }}
        </h2>
        <table
          v-if="rewardHistory.length > 0"
          class="w-full text-(length:--c17-text-link) [&_td]:p-(--c17-gap-2) [&_th]:p-(--c17-gap-2)"
        >
          <thead>
            <tr
              class="text-left font-bold"
              :style="{ background: 'var(--c17-primary-ink)', color: 'var(--c17-ink)' }"
            >
              <th class="p-2">
                {{ $t('common.time.label.time') }}
              </th>
              <th class="p-2">
                {{ $t('common.ui.label.type') }}
              </th>
              <th class="p-2 text-right">
                {{ $t('common.amount.label.amount') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(item, index) in rewardHistory"
              :key="index"
              class="border-t border-line"
            >
              <td class="p-2">
                {{ item.date }}
              </td>
              <td class="p-2">
                {{ item.reward_name }}
              </td>
              <td class="p-2 text-right">
                {{ decFormat(item.point) }}
              </td>
            </tr>
          </tbody>
        </table>
        <p
          v-else
          class="text-muted"
        >
          {{ $t('common.ui.empty.noData') }}
        </p>
      </section>

      <section class="mx-(--c17-pad-x) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]">
        <h2
          class="font-bold"
          :style="{ fontSize: 'var(--c17-text-body)', color: 'var(--c17-ink)' }"
        >
          {{ $t('vip.privilege.title.exclusive') }}
        </h2>
        <p class="mb-(--c17-gap-3) text-(length:--c17-text-label) text-muted">
          {{ $t('vip.upgrade.hint.bonusClaimInstant') }}
        </p>
        <ul
          v-if="privileges.length > 0"
          class="flex flex-col gap-(--c17-gap-2)"
        >
          <VipPrivilegeItem
            v-for="(item, index) in privileges"
            :key="index"
            :item="item"
            :busy="claimingId !== null"
            @claim="onClaim"
          />
        </ul>
        <p
          v-else
          class="text-muted"
        >
          {{ $t('common.ui.empty.noData') }}
        </p>
      </section>
    </template>
  </div>
</template>
