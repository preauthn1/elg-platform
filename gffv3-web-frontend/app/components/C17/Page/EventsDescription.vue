<script setup lang="ts">
import { decFormat } from '#shared/utils/amount'
import { legacyShowsProgress } from '~/utils/events-core'

/**
 * 舊版活動詳情（設計稿 Promo-pop out）。EventsLegacyCard 的「活動詳情」連到這裡。
 *
 * **是彈窗不是整頁**：設計稿把它畫成疊在促銷頁上的白卡
 * （358×754 @16,45、圓角 8、#1565FF 2pt 內框、投影 #7A859942 X3 Y3 模糊 4），
 * 內距 20，所以內容寬 318。關閉時 backOr 退回 /promotions。
 *
 * event/legacy-detail 固定回一筆（陣列），composable 已取 [0]。
 * content 是後台編輯器存的 HTML，照舊前台以 v-html 呈現。
 *
 * 最上面是語言晶片（EventsLanguageChips），只切這個彈窗的內容語言、不動站台語系。
 *
 * 設計稿有、但後端給不出來所以沒做的兩塊：GET THE BONUS 三格的圖示、
 * Allowed Provider 那一列。三格磚改用 legacy-detail 真的有回的 prize（名稱＋點數）。
 */
const {
  ct,
  legacyDetail,
  loading,
  row,
  banner,
  reloadDetail,
  period,
  prizes,
  isBetEvent,
  exchangeDate,
  repeatable,
} = useEventsDescriptionPage()

/** 骨架的出現時機由 useSkeleton 決定，不可以直接綁 loading（見該 composable 的說明） */
const showSkeleton = useSkeleton(() => loading.value)
</script>

<template>
  <UiModal @close="backOr('/promotions')">
    <div class="flex flex-col gap-(--c17-gap-3)">
      <EventsLanguageChips @change="reloadDetail" />

      <UiSkeleton
        v-if="showSkeleton"
        :rows="6"
      />

      <template v-if="legacyDetail">
        <!-- 設計稿的 promo1：318×130、圓角 8，貼齊內距 -->
        <img
          v-if="banner"
          :src="banner"
          :alt="legacyDetail.title ?? ''"
          class="block aspect-[318/130] w-full rounded-(--c17-radius-sm) object-cover"
        >

        <h1
          class="flex items-center gap-(--c17-gap-2) font-extrabold"
          :style="{ fontFamily: 'var(--c17-font-display)', fontSize: 'var(--c17-text-body)', color: 'var(--c17-ink)' }"
        >
          <img
            v-if="repeatable"
            src="/skins/c17/promo/repeatable.webp"
            :alt="ct('events.claim.label.repeatable')"
            class="h-7 w-7 shrink-0 object-contain"
          >
          {{ legacyDetail.title }}
        </h1>

        <template v-if="prizes.length">
          <!--
            分隔標題：兩側是設計稿的 `Polygon 1`（4 寬 × 36 高、旋轉 270°／90°），
            也就是 36 長 4 厚的細長條，尖端朝外，與文字各距 10。
          -->
          <div
            class="flex items-center justify-center gap-[10px] font-bold uppercase"
            :style="{ color: 'var(--c17-primary)', fontSize: 'var(--c17-text-link)' }"
          >
            <span
              class="size-0 border-y-2 border-r-[36px] border-y-transparent"
              :style="{ borderRightColor: 'var(--c17-primary)' }"
              aria-hidden="true"
            />
            {{ ct('events.detail.title.getTheBonus') }}
            <span
              class="size-0 border-y-2 border-l-[36px] border-y-transparent"
              :style="{ borderLeftColor: 'var(--c17-primary)' }"
              aria-hidden="true"
            />
          </div>

          <!-- 三格磚：100×60、圓角 4、白底、#EBEBEB 1pt 外框，欄距 9 -->
          <ul class="grid grid-cols-3 gap-[9px]">
            <li
              v-for="prize in prizes"
              :key="String(prize.id)"
              class="flex h-[60px] flex-col items-center justify-center rounded-(--c17-radius-xs) border border-line bg-surface px-1"
            >
              <span
                v-if="prize.point != null"
                class="font-extrabold"
                :style="{ fontSize: 'var(--c17-text-title)', color: 'var(--c17-primary)' }"
              >{{ decFormat(prize.point) }}</span>
              <span
                class="line-clamp-1 text-center font-semibold"
                :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }"
              >{{ prize.name }}</span>
            </li>
          </ul>
        </template>

        <!-- 資訊列：設計稿是 26×26 圖示 ＋ 藍色小標 ＋ 值，圖示與小標間距 10 -->
        <div
          v-if="period"
          class="flex items-center gap-[10px]"
        >
          <img
            src="/skins/c17/promo/period.webp"
            alt=""
            class="size-[26px] shrink-0 rounded-(--c17-radius-xs) object-contain"
          >
          <span class="flex min-w-0 flex-1 flex-col gap-[2px]">
            <span
              class="font-semibold"
              :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-primary)' }"
            >{{ ct('events.detail.label.eventPeriod') }}</span>
            <span
              class="font-semibold"
              :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }"
            >{{ period }}</span>
          </span>
        </div>

        <hr
          class="border-0 border-t"
          :style="{ borderTopColor: 'var(--c17-accent)' }"
        >

        <template v-if="isBetEvent">
          <p
            v-if="exchangeDate"
            class="flex flex-col gap-[2px]"
            :style="{ fontSize: 'var(--c17-text-label)' }"
          >
            <span :style="{ color: 'var(--c17-primary)' }">{{ ct('vip.privilege.label.exchangeDate') }} :</span>
            <span
              class="font-semibold"
              :style="{ color: 'var(--c17-ink)' }"
            >{{ exchangeDate }}</span>
          </p>
          <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-primary)' }">
            {{ ct('common.misc.label.description') }} :
          </p>
          <p
            class="whitespace-pre-line font-semibold"
            :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }"
          >
            {{ ct('events.claim.text.remarks') }}
          </p>
        </template>

        <!-- eslint-disable vue/no-v-html -->
        <article
          v-if="legacyDetail.content"
          class="whitespace-pre-line font-semibold"
          :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }"
          v-html="legacyDetail.content"
        />
        <!-- eslint-enable vue/no-v-html -->

        <template v-if="isBetEvent && legacyDetail.detail?.length">
          <hr
            class="border-0 border-t"
            :style="{ borderTopColor: 'var(--c17-accent)' }"
          >
          <h2
            class="font-extrabold"
            :style="{ fontFamily: 'var(--c17-font-display)', fontSize: 'var(--c17-text-body)', color: 'var(--c17-ink)' }"
          >
            {{ ct('events.mission.label.achieveConditions') }}
          </h2>
          <EventsLegacyDetail
            v-for="(detail, index) in legacyDetail.detail"
            :key="String(detail.id)"
            :detail="detail"
            :show-progress="legacyShowsProgress(legacyDetail, 'description')"
            with-game-list
            :class="index > 0 ? 'border-t border-line pt-(--c17-gap-3)' : ''"
          />
        </template>

        <!-- CLAIM NOW：設計稿 318×32、圓角 8、#1565FF、PJS ExtraBold 14 全大寫 -->
        <EventsExchangeButton
          v-if="row"
          class="mt-(--c17-gap-2)"
          :event="row"
        />
        <!-- 兌換紀錄（舊皮 EventDescription 的第二顆鈕） -->
        <UiButton
          v-if="isBetEvent"
          variant="secondary"
          @click="navigateTo('/record/event')"
        >
          {{ ct('wallet.exchange.nav.record') }}
        </UiButton>
      </template>

      <p
        v-else-if="!loading"
        class="text-(length:--c17-text-link) text-muted"
      >
        {{ ct('events.list.empty.noEvents') }}
      </p>
    </div>
  </UiModal>
</template>
