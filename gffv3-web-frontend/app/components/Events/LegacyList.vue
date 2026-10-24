<script setup lang="ts">
import { legacyCardKind, legacyShowsProgress, parseIdsQuery, sortLegacyEvents, type LegacyEventPreset } from '~/utils/events-core'

const props = defineProps<{ preset?: LegacyEventPreset | null }>()

const route = useRoute()
const legacy = useLegacyEvents()
const { store: prizeStore } = usePrize()

const filter = computed(() => ({
  ids: parseIdsQuery(route.query.ids),
  category: typeof route.query.category === 'string' ? route.query.category : null,
  preset: props.preset ?? null,
}))

/**
 * 舊皮 Events 頁是一段一段分群排下來的，每一群用自己的卡。
 * 這裡改成單一串列 + 卡別，排序照舊皮的分群順序，畫面結果一樣但少一層巢狀。
 */
const events = computed(() => sortLegacyEvents(legacy.filtered(filter.value)))
const bannerImage = computed(() => legacy.bannerImage(filter.value.ids))

const loading = ref(false)
/** 骨架的出現時機由 useSkeleton 決定，不可以直接綁 loading（見該 composable 的說明） */
const showSkeleton = useSkeleton(() => loading.value)

async function load() {
  loading.value = true
  await legacy.fetchLegacyEvents()
  loading.value = false
}

/** 兌獎彈窗關閉後重抓列表。 */
function onDrawClosed() {
  void load()
}

onMounted(load)
</script>

<template>
  <div class="flex flex-col gap-(--c17-gap-4)">
    <!-- 活動內容是後端依語系給的，所以語言切換要放在最上面（與 V2 詳情彈窗一致） -->
    <EventsLanguageChips @change="load" />

    <img
      v-if="bannerImage"
      :src="bannerImage"
      alt=""
      class="block w-full rounded-(--c17-radius-sm) object-cover"
    >
    <EventsDrawResult
      v-if="prizeStore.lastDraw"
      @close="onDrawClosed"
    />

    <!-- 載入中用骨架，不要只印「載入中」：段落數抓得跟一張活動卡差不多，換上真內容時不會跳 -->
    <template v-if="showSkeleton">
      <UiSkeleton class="aspect-[39/14] w-full rounded-lg" />
      <UiSkeleton :rows="4" />
    </template>
    <p
      v-else-if="events.length === 0"
      :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-muted)' }"
    >
      {{ $t('events.list.empty.noEvents') }}
    </p>

    <template
      v-for="event in events"
      :key="event.id"
    >
      <EventsJackpotCard
        v-if="legacyCardKind(event) === 'jackpot'"
        :event="event"
      />
      <EventsInviteCard
        v-else-if="legacyCardKind(event) === 'invite'"
        :event="event"
        @done="load"
      />
      <EventsNewInviteCard
        v-else-if="legacyCardKind(event) === 'newInvite'"
        :event="event"
      />
      <EventsInviteRechargeCard
        v-else-if="legacyCardKind(event) === 'inviteRecharge'"
        :event="event"
        @done="load"
      />
      <EventsAgentDepositCard
        v-else-if="legacyCardKind(event) === 'agentDeposit'"
        :event="event"
        @done="load"
      />
      <EventsRechargeCard
        v-else-if="legacyCardKind(event) === 'recharge'"
        :event="event"
        @done="load"
      />
      <EventsLoseRebateCard
        v-else-if="legacyCardKind(event) === 'loseRebate'"
        :event="event"
        @done="load"
      />
      <!-- 通用卡：沒有專屬版型的活動，一顆兌換鈕加活動詳情（舊皮 EventItem） -->
      <EventsLegacyCard
        v-else
        :event="event"
      >
        <!-- 舊皮順序：剩餘可領獎次數 → 領獎說明（固定文案＋後台 content） -->
        <template #content>
          <div
            class="flex flex-col gap-(--c17-gap-2)"
            :style="{ fontSize: 'var(--c17-text-label)' }"
          >
            <p
              v-if="event.remaining_number != null"
              class="flex flex-wrap items-baseline gap-x-(--c17-gap-2)"
            >
              <span :style="{ color: 'var(--c17-primary)' }">{{ $t('events.claim.hint.prizesAvailable') }} :</span>
              <span
                class="font-semibold"
                :style="{ color: 'var(--c17-ink)' }"
              >{{ event.remaining_number }}</span>
            </p>
            <p :style="{ color: 'var(--c17-primary)' }">
              {{ $t('common.misc.label.description') }} :
            </p>
            <p
              class="whitespace-pre-line font-semibold"
              :style="{ color: 'var(--c17-ink)' }"
            >
              {{ $t('events.claim.text.remarks') }}
            </p>
            <!-- eslint-disable vue/no-v-html -->
            <div
              v-if="event.content"
              class="whitespace-pre-line font-semibold"
              :style="{ color: 'var(--c17-ink)' }"
              v-html="event.content"
            />
            <!-- eslint-enable vue/no-v-html -->
          </div>
        </template>

        <!-- 達成條件：一個 detail 一段，段與段之間一條細線 -->
        <EventsLegacyDetail
          v-for="(detail, index) in event.detail ?? []"
          :key="String(detail.id)"
          :detail="detail"
          :show-progress="legacyShowsProgress(event)"
          :class="index > 0 ? 'border-t border-line pt-(--c17-gap-3)' : ''"
        />

        <EventsExchangeButton
          :event="event"
          @done="load"
        />
        <NuxtLink
          :to="`/events/description/${event.id}`"
          class="text-center font-semibold underline"
          :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-link)' }"
        >
          {{ $t('events.list.nav.eventDetails') }}
        </NuxtLink>
      </EventsLegacyCard>
    </template>
  </div>
</template>
