<script setup lang="ts">
/**
 * 活動 V2 參加中的進度區塊，照舊專案 C13 的 ActivityAccountStatus＋BalanceInfo：
 *   標題「活動進度」、進度條、目前流水／目標流水（flow_limit 為 0 的活動改看目前餘額／目標餘額）、
 *   流水模式另列目前餘額；領取鈕未達標時停用，放棄鈕只在未達標時出現。
 * 掛在首頁（取代錢包列，帶活動詳情連結）與遊戲商頁；**活動詳情頁不掛**，舊專案那頁也不打 join-detail。
 */
const props = defineProps<{
  /** 首頁才有「活動詳情 >」連結（舊專案 BalanceInfo 的 handleEventDetail） */
  showDetailLink?: boolean
}>()

const v2 = useEventV2()
const { settings } = useSite()
const { ct } = useContentI18n()

const { loggedIn } = useUserSession()
const { refreshBalance } = useWallet()

const confirming = ref<'claim' | 'forfeit' | null>(null)
const busy = ref(false)
const alert = useAlert()

onMounted(() => {
  if (loggedIn.value) void v2.fetchJoinDetail()
})

const detail = computed(() => v2.joinDetail.value)
const progress = computed(() => v2.progress.value)
/** flow_limit 不為 0 就是看流水，否則看餘額（舊專案 isFlowMode） */
const flowMode = computed(() => Number(detail.value?.flow_limit ?? 0) !== 0)
const reached = computed(() => !!progress.value && progress.value.progress >= progress.value.requirement)
const detailPath = computed(() => (detail.value ? `/events/platform-event?ids=${detail.value.event_detail_id}` : ''))

/** 領取：確認後呼叫；成功彈出恭喜文案，關掉彈窗後整頁重載。 */
async function withdraw() {
  confirming.value = null
  if (busy.value) return
  busy.value = true
  try {
    const res = await v2.withdraw()
    // 失敗的彈窗與 324 關掉後導綁手機由 useApi 全域處理，這裡不再自己開第二個
    if (isApiOk(res) && res.data?.withdraw != null) {
      alert.show(
        `${ct('events.claim.msg.congratulations')} ${res.data.withdraw} ${settings.value?.text_wallet_point || ct('common.amount.label.platformPoint')}`,
        'success',
        { action: 'reload' },
      )
    }
  }
  finally {
    busy.value = false
  }
}

/**
 * 放棄：確認後呼叫。照舊專案不重整頁面，只重抓狀態（v2.quit 內已重抓 join-detail）與餘額，
 * 進度區塊隨 joined 變 false 自己收掉。
 */
async function quit() {
  confirming.value = null
  if (busy.value) return
  busy.value = true
  try {
    const res = await v2.quit()
    // 失敗的彈窗由 useApi 全域處理
    if (isApiOk(res)) await refreshBalance()
  }
  finally {
    busy.value = false
  }
}
</script>

<template>
  <section
    v-if="v2.joined.value && detail && progress"
    class="flex flex-col gap-(--c17-gap-2) rounded-(--c17-radius) border border-line bg-surface p-(--c17-gap-3)"
    :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
  >
    <div class="flex items-center justify-between gap-2">
      <h2 class="font-bold">
        {{ ct('events.detail.label.progress') }}
      </h2>
      <NuxtLink
        v-if="props.showDetailLink"
        :to="detailPath"
        class="shrink-0 font-bold"
        :style="{ color: 'var(--c17-primary)' }"
      >
        {{ ct('events.list.nav.eventDetails') }} &gt;
      </NuxtLink>
    </div>

    <div class="h-2 overflow-hidden rounded-full bg-line">
      <span
        class="block h-full rounded-full bg-accent transition-[width]"
        :style="{ width: `${progress.percent}%` }"
      />
    </div>

    <div class="flex flex-col">
      <span>{{ flowMode ? ct('withdrawal.turnover.label.currentTurnover') : ct('common.amount.label.currentBalance') }} {{ progress.progress }}</span>
      <span>{{ flowMode ? ct('events.mission.label.targetTurnover') : ct('events.mission.label.targetBalance') }} {{ progress.requirement }}</span>
      <span v-if="flowMode">{{ ct('common.amount.label.currentBalance') }} {{ detail.balance }}</span>
    </div>

    <div class="flex gap-(--c17-gap-2)">
      <UiButton
        :disabled="busy || !reached"
        @click="confirming = 'claim'"
      >
        {{ ct('events.claim.action.claim') }}
      </UiButton>
      <UiButton
        v-if="!reached"
        variant="secondary"
        :disabled="busy"
        @click="confirming = 'forfeit'"
      >
        {{ ct('common.misc.action.giveUp') }}
      </UiButton>
    </div>

    <!-- 確認彈窗：舊專案 BalanceInfo 的 base-modal（標題「提示」、取消＋確定） -->
    <Teleport to="#teleports">
      <UiModal
        v-if="confirming"
        :title="ct('common.ui.title.prompt')"
        @close="confirming = null"
      >
        <div class="flex flex-col gap-(--c17-gap-4)">
          <p :style="{ fontSize: 'var(--c17-text-body)' }">
            {{ confirming === 'claim' ? ct('events.claim.msg.confirmClaim') : ct('events.claim.msg.confirmForfeit') }}
          </p>
          <div class="flex gap-(--c17-gap-3)">
            <UiButton
              variant="secondary"
              @click="confirming = null"
            >
              {{ ct('common.ui.action.cancel') }}
            </UiButton>
            <UiButton @click="confirming === 'claim' ? withdraw() : quit()">
              {{ ct('common.ui.action.determine') }}
            </UiButton>
          </div>
        </div>
      </UiModal>
    </Teleport>
  </section>
</template>
