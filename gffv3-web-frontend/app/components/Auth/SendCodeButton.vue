<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import type { ApiEnvelope } from '#shared/types/api'
import { useAuthStore } from '~/stores/auth'

const props = withDefaults(defineProps<{
  type: number
  verifyBy: 1 | 2
  mobile?: string
  email?: string
  username?: string
  guestValidateToken?: string
  disabled?: boolean
  /**
   * 設計稿那顆固定寬度的 SEND（140×32、主色底、ExtraBold 14 大寫）。
   * 預設 false 是舊的外框小鈕，其餘還沒改過的地方維持原樣。
   */
  flush?: boolean
}>(), { disabled: false, flush: false })

const emit = defineEmits<{
  sent: [res: ApiEnvelope<unknown>]
  failed: [res: ApiEnvelope<unknown>]
  /** 訪客型/會員型 type 與登入狀態不符 */
  illegal: []
}>()

const store = useAuthStore()
const { captchaRequired, sendCode, timerKey } = useGuestValidationCode()

const captchaRef = ref<{ reset: () => Promise<void> } | null>(null)
const captchaToken = [REDACTED] | null>(null)
const showCaptcha = ref(false)
const sending = ref(false)

/** 倒數由 store 的到期時間算，這裡只在瀏覽器端每秒重算一次。 */
const now = ref(Date.now())
let ticker: ReturnType<typeof setInterval> | null = null
onMounted(() => {
  ticker = setInterval(() => {
    now.value = Date.now()
  }, 1000)
})
onBeforeUnmount(() => {
  if (ticker) clearInterval(ticker)
})

const target = computed(() => (props.verifyBy === 1 ? props.mobile : props.email) ?? '')
const remaining = computed(() => store.codeTimerRemaining(timerKey({ type: props.type, verify_by: props.verifyBy, mobile: props.mobile, email: props.email }), now.value))
const blocked = computed(() => props.disabled || sending.value || remaining.value > 0 || !target.value)

async function onClick() {
  if (blocked.value) return
  if (captchaRequired.value && !captchaToken.value) {
    showCaptcha.value = true
    return
  }
  await send()
}

async function send() {
  sending.value = true
  const { scope, res } = await sendCode({
    type: props.type,
    verify_by: props.verifyBy,
    mobile: props.verifyBy === 1 ? props.mobile : undefined,
    email: props.verifyBy === 2 ? props.email : undefined,
    username: props.username || undefined,
    guest_validate_token: [REDACTED] || undefined,
    captcha_token: [REDACTED] ?? undefined,
  })
  sending.value = false
  // captcha_token 用過即失效，不論成敗都要重新出題
  captchaToken.value = null
  if (scope === 'illegal') {
    emit('illegal')
    return
  }
  if (!res) return
  if (isApiOk(res)) {
    showCaptcha.value = false
    emit('sent', res)
  }
  else {
    emit('failed', res)
    if (captchaRequired.value) await captchaRef.value?.reset()
  }
}

async function onCaptchaVerified(token: [REDACTED] {
  captchaToken.value = token
  await send()
}
</script>

<template>
  <div
    class="space-y-2"
    :class="flush ? 'w-(--c17-field-action-w) shrink-0' : ''"
  >
    <!--
      舊的是欄位旁邊一顆外框小鈕，沒有鎖寬度，"Send Verification Code" 這種長字串
      會把輸入框擠掉一半、自己也被截斷。flush 鎖成設計稿的 140（外觀見 UiCodeSendButton）。
      （設計稿是把鈕壓在欄位右緣裡；這裡排在欄位旁邊留 8px——差在那 8px 的留白，
        但驗證碼區塊還帶滑塊驗證與錯誤訊息，做成絕對定位會把那兩塊也拖進欄位裡。）
    -->
    <UiCodeSendButton
      v-if="flush"
      :remaining="remaining"
      :disabled="blocked"
      @click="onClick"
    />
    <button
      v-else
      type="button"
      class="rounded border border-accent px-3 py-1 text-sm text-accent disabled:opacity-50"
      :disabled="blocked"
      @click="onClick"
    >
      <template v-if="remaining > 0">
        {{ $t('auth.otp.action.resend') }} ({{ remaining }})
      </template>
      <template v-else>
        {{ $t('auth.otp.action.sendCode') }}
      </template>
    </button>
    <AuthSliderCaptcha
      v-if="showCaptcha && captchaRequired"
      ref="captchaRef"
      :type="type"
      @verified="onCaptchaVerified"
    />
  </div>
</template>
