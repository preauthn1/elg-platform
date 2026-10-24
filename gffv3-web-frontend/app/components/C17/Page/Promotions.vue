<script setup lang="ts">
import { NuxtLink } from '#components'

/**
 * 促銷活動列表。底部導覽的第二個分頁指到這裡。
 * 資料與首頁的 PROMOTIONS 卡列同一支 event/list，只是這裡一次列完。
 * 點擊由 eventPath 分流到 v1／v2／第三方活動頁。
 *
 * 版型是**三欄直式磚**，比例用 aspect-ratio 表示而不是寫死尺寸。
 *
 * 跟設計稿刻意不同的三點，都是資料給不出來的：
 * 1. **「N claimed」計數**：`event/list` 沒有這個數字，不做——寧可少一塊，
 *    也不要在畫面上編一個數字出來。
 * 2. **分區標題**：後端只給 `params.category` 代號，要變成字得先有 14 語系的翻譯。
 *    先平鋪，翻譯到位再分區。
 *
 * 磚一律**三欄等寬、同一個直式比例**：設計稿的主 banner 只有最上面那張獎池橫幅。
 * 設計稿的磚圖是直式的，後端給的是橫幅，object-cover 會裁掉大半——這要靠後端出直式圖解決，
 * 不要把有圖的磚放大成兩欄來遷就，那會讓畫面出現好幾張看起來像主 banner 的東西。
 */
// 設計稿 Promo 最上面是下載列，沒有餘額列
const {
  eventPath,
  items,
} = await usePromotionsPage()
</script>

<template>
  <div class="flex flex-col gap-(--c17-gap-4) px-(--c17-pad-x) py-(--c17-gap-4)">
    <!--
      獎池橫幅是**寫死在皮裡的素材**，跟登入頁的 WELCOME BONUS、遊戲大廳的類型橫幅一樣。
      這顆**有**陰影（md 有寫，算圖也量得到），與那兩處不同。
    -->
    <img
      src="/skins/c17/promo/prize-pool.webp"
      alt=""
      class="block aspect-[358/120] w-full rounded-(--c17-field-radius) object-cover"
      :style="{ boxShadow: 'var(--c17-shadow-raised)' }"
    >

    <h1
      class="font-extrabold uppercase"
      :style="{
        fontFamily: 'var(--c17-font-display)',
        fontSize: 'var(--c17-text-title)',
        color: 'var(--c17-ink)',
      }"
    >
      {{ $t('site.nav.nav.promotion') }}
    </h1>

    <p
      v-if="!items || items.length === 0"
      class="py-(--c17-gap-6) text-center text-sm text-muted"
    >
      {{ $t('events.list.empty.noEvents') }}
    </p>

    <!--
      **三欄等寬、全部同一個直式比例**，不分有圖沒圖。

      這裡本來讓有圖的活動跨兩欄改用橫幅比例（理由是後端給的是橫幅，塞進一欄會裁掉大半），
      但設計稿上主 banner 只有最上面那一張獎池橫幅，底下的活動磚一律同尺寸；
      放大成兩欄會讓畫面看起來有好幾張主 banner。裁切問題要解就是請後端出直式圖，
      不要靠改版型繞過。
    -->
    <ul
      v-else
      class="grid grid-cols-3 gap-x-(--c17-tile-gap-x) gap-y-(--c17-tile-gap)"
    >
      <li
        v-for="(item, index) in items"
        :key="`${item.event_v1_id ?? ''}-${item.event_v2_id ?? ''}-${index}`"
      >
        <component
          :is="eventPath(item) ? NuxtLink : 'div'"
          :to="eventPath(item) ?? undefined"
          class="relative flex aspect-[11/12] flex-col justify-end overflow-hidden rounded-(--c17-radius-sm)"
          :style="{
            background: 'var(--c17-card-bg)',
            boxShadow: 'var(--c17-shadow-raised)',
          }"
        >
          <img
            v-if="item.image"
            :src="item.image"
            alt=""
            class="absolute inset-0 size-full object-cover"
            loading="lazy"
          >
          <!--
            有圖的磚：名稱壓在底部，墊一層由透明到深色的遮罩才讀得到字。
            沒圖的磚：底是漸層，字直接放，不需要遮罩。
          -->
          <span
            class="relative line-clamp-3 px-(--c17-gap-2) pb-(--c17-gap-2) pt-(--c17-gap-4) font-bold leading-tight"
            :class="item.image ? 'text-inverse' : 'text-ink'"
            :style="{
              fontSize: 'var(--c17-text-label)',
              background: item.image
                ? 'linear-gradient(to top, var(--wf-scrim), transparent)'
                : undefined,
            }"
          >
            {{ item.name }}
          </span>
        </component>
      </li>
    </ul>
  </div>
</template>
