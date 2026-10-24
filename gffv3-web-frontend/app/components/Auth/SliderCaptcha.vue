<script setup lang="ts">
import { onMounted, ref } from 'vue'
import type { CaptchaData } from '~/composables/useGuestValidationCode'

const props = defineProps<{
  /** 發碼 type，決定走 guest 還是 member 出題 */
  type: number
}>()

const emit = defineEmits<{
  verified: [token: [REDACTED]
  failed: [message: string]
}>()

const { fetchCaptcha, verifyCaptcha } = useGuestValidationCode()

const captcha = ref<CaptchaData | null>(null)
const x = ref(0)
const status = ref<'idle' | 'loading' | 'verifying' | 'verified' | 'failed' | 'loadFailed'>('idle')

async function load() {
  status.value = 'loading'
  x.value = 0
  const res = await fetchCaptcha(props.type)
  if (!isApiOk(res)) {
    captcha.value = null
    status.value = 'loadFailed'
    // 出題走 silent（不觸發全站錯誤碼導頁），後端訊息自己丟彈窗；重試鈕仍留在元件內
    useAlert().showResponse(res)
    return
  }
  captcha.value = res.data
  status.value = 'idle'
}

/** 放開滑桿即作答；同一題只能答一次，失敗要重新出題。 */
async function submit() {
  if (!captcha.value || status.value !== 'idle') return
  status.value = 'verifying'
  const res = await verifyCaptcha(props.type, captcha.value.captcha_id, x.value)
  if (isApiOk(res)) {
    status.value = 'verified'
    emit('verified', res.data.captcha_token)
    return
  }
  status.value = 'failed'
  useAlert().showResponse(res)
  emit('failed', res.message)
}

/** captcha_token 一次性：發碼後由父層呼叫，重新出題。 */
function reset() {
  return load()
}

defineExpose({ reset })

onMounted(load)
</script>

<template>
  <div class="space-y-2 rounded border border-line bg-surface p-3">
    <p class="text-sm font-medium">
      {{ $t('auth.captcha.title.securityVerification') }}
    </p>

    <div
      v-if="captcha"
      class="relative overflow-hidden rounded"
      :style="{ width: `${captcha.bg_width}px`, height: `${captcha.bg_height}px`, maxWidth: '100%' }"
    >
      <img
        :src="captcha.bg_image"
        :width="captcha.bg_width"
        :height="captcha.bg_height"
        alt=""
        class="block"
      >
      <img
        :src="captcha.piece_image"
        :width="captcha.piece_width"
        alt=""
        class="absolute"
        :style="{ left: `${x}px`, top: `${captcha.piece_y}px` }"
      >
    </div>

    <input
      v-if="captcha"
      v-model.number="x"
      type="range"
      min="0"
      :max="Math.max(0, captcha.bg_width - captcha.piece_width)"
      step="1"
      class="w-full"
      :disabled="status !== 'idle'"
      :aria-label="$t('auth.captcha.hint.dragSlider')"
      @change="submit"
    >

    <p class="text-xs text-muted">
      <template v-if="status === 'loading'">
        {{ $t('common.ui.label.loading') }}
      </template>
      <template v-else-if="status === 'verifying'">
        {{ $t('auth.captcha.msg.verifying') }}
      </template>
      <template v-else-if="status === 'verified'">
        {{ $t('auth.captcha.msg.verified') }}
      </template>
      <template v-else-if="status === 'failed'">
        {{ $t('auth.captcha.error.verifyFailed') }}
      </template>
      <template v-else-if="status === 'loadFailed'">
        {{ $t('auth.captcha.error.loadFailed') }}
      </template>
      <template v-else>
        {{ $t('auth.captcha.hint.dragSlider') }}
      </template>
    </p>

    <button
      v-if="status === 'failed' || status === 'loadFailed'"
      type="button"
      class="text-sm text-accent underline"
      @click="load"
    >
      {{ $t('auth.captcha.action.refresh') }}
    </button>
  </div>
</template>
