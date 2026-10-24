<script setup lang="ts">
/**
 * ready：可以接續原本的動作（等同舊版的 reInit／prize-reminder-ready），**每次掛載最多發一次**，
 * 呼叫端直接 `@ready="..."` 就好，不用自己再擋重複。
 * 沒有待領獎勵（含未登入、站台關掉 prize_remind、查詢失敗）或放棄領取成功時發出；
 * 彈窗開著、前往領取、關閉回首頁都不發。
 * 遊戲頁／遊戲列表的轉入要等這個事件，舊版有待領獎勵時不會先把錢轉進遊戲商。
 */
const props = defineProps<{
  /** true：掛載時不檢查，由呼叫端在點擊時呼叫 check()（舊版 VendorListGrid 點遊戲商才檢查） */
  manual?: boolean
}>()
const emit = defineEmits<{ dismissed: [], ready: [] }>()

const { loggedIn } = useUserSession()
const { flag } = useSite()
const { fetchRemind, hasPendingPrize } = useEvents()
const { cancelPrize } = usePrize()

const shown = ref(false)
const busy = ref(false)

let readyEmitted = false
function emitReady() {
  if (readyEmitted) return
  readyEmitted = true
  emit('ready')
}

/** 照舊版 PrizeReminder.checkPrizeReminder：已登入、站台開了 prize_remind、count > 0 才彈窗。 */
async function check() {
  if (!loggedIn.value || !flag('prize_remind')) {
    emitReady()
    return
  }
  const res = await fetchRemind()
  shown.value = isApiOk(res) && hasPendingPrize.value
  if (!shown.value) emitReady()
}

onMounted(() => {
  if (!props.manual) void check()
})

/** manual 模式每次點擊是一次新的檢查，各自可以發一次 ready。 */
defineExpose({
  check: () => {
    readyEmitted = false
    return check()
  },
})

/** 前往領取：關窗並導去活動頁領獎。 */
function goClaim() {
  shown.value = false
  return navigateTo('/promotions')
}

/** 放棄領取：prize/cancel 成功後關窗，發 ready 讓呼叫端接續原動作。 */
async function giveUp() {
  if (busy.value) return
  busy.value = true
  try {
    const res = await cancelPrize()
    if (isApiOk(res)) {
      shown.value = false
      emitReady()
    }
  }
  finally {
    busy.value = false
  }
}

/** 右上 X 或點遮罩關閉：回首頁，等同舊皮的 onCloseToHome。 */
function close() {
  shown.value = false
  emit('dismissed')
  return navigateTo('/')
}
</script>

<template>
  <UiModal
    v-if="shown"
    :title="$t('common.ui.title.prompt')"
    @close="close"
  >
    <div class="flex flex-col gap-(--c17-gap-4) pt-(--c17-gap-2)">
      <!-- 舊文案含 <br>，依 nuxt.config i18n 設定以 HTML 呈現 -->
      <!-- eslint-disable vue/no-v-html -->
      <p
        class="text-center text-ink"
        :style="{ fontSize: 'var(--c17-text-body)' }"
        v-html="$t('wallet.exchange.text.unclaimedBonus')"
      />
      <!-- eslint-enable vue/no-v-html -->
      <div class="flex gap-(--c17-gap-3)">
        <UiButton
          variant="secondary"
          :disabled="busy"
          @click="giveUp"
        >
          {{ $t('common.misc.action.giveUp') }}
        </UiButton>
        <UiButton @click="goClaim">
          {{ $t('events.claim.action.claim') }}
        </UiButton>
      </div>
    </div>
  </UiModal>
</template>
