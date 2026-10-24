<script setup lang="ts">
import type { EventListItem } from '#shared/api/endpoints/events-core'
// 動態元件要傳**元件本身**，不能傳 'NuxtLink' 字串——字串不會被解析，
// 會原樣渲染成 <nuxtlink> 自訂元素，沒有 href 也點不動。
import { NuxtLink } from '#components'

/**
 * PROMOTIONS 橫向卡列。資料來自 event/list，點擊導向該活動頁（eventPath 負責分流 v1/v2/第三方）。
 *
 * **第一張是長方形、後面都是方形**（設計稿的排法，已確認）。依位置決定，不是依有沒有圖：
 * 有圖沒圖是資料的事，版型不該跟著資料跳。
 */
defineProps<{ items: EventListItem[] }>()

const { eventPath } = useEvents()
</script>

<template>
  <!--
    設計稿（home-v3 的 "Scroll Group 4"）是橫向捲動列，卡片兩種寬度、**高度都是 120**：
      type B「3 banner」234×120（有圖的）
      type A「1 banner」110×120（沒圖、用漸層底的）
    圓角 8、間距 10、投影 3,3 模糊 2 #7a8599/26%（= --c17-shadow-raised）。
    卡片本身就是圖，不是白底卡包一張圖——所以沒有 bg-surface。

    寬度規則取「有沒有圖」：後端沒有欄位說哪張該佔寬版，而有圖的本來就是橫幅，
    塞進 110 寬會裁掉大半。與 /promotions 頁用同一套規則。
  -->
  <div
    class="flex gap-[0.625rem] overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
    :style="{ paddingInline: 'var(--c17-pad-x)' }"
  >
    <component
      :is="eventPath(item) ? NuxtLink : 'div'"
      v-for="(item, index) in items"
      :key="`${item.event_v1_id ?? ''}-${item.event_v2_id ?? ''}-${index}`"
      :to="eventPath(item) ?? undefined"
      class="relative flex h-[7.5rem] shrink-0 flex-col justify-end overflow-hidden rounded-[var(--c17-radius-sm)]"
      :class="index === 0 ? 'aspect-[39/20]' : 'aspect-square'"
      :style="{ background: 'var(--c17-card-bg)', boxShadow: 'var(--c17-shadow-raised)' }"
    >
      <img
        v-if="item.image"
        :src="item.image"
        :alt="item.name ?? ''"
        class="absolute inset-0 size-full object-cover"
        loading="lazy"
      >
      <p
        v-else
        class="line-clamp-3 px-[var(--c17-gap-2)] pb-[var(--c17-gap-2)] font-bold leading-tight"
        :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-ink)' }"
      >
        {{ item.name }}
      </p>
    </component>
  </div>
</template>
