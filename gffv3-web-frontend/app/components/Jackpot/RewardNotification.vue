<script setup lang="ts">
import { formatEventAmount } from '~/utils/jackpot'

/**
 * 積寶中獎彈窗，掛在大廳：登入且 event_jackpot_visible 開著才去查未讀通知，
 * 一次顯示一個，關閉時回報已讀後才放下一個。
 */
const { loggedIn } = useUserSession()
const { flag, settings } = useSite()
const notifications = useJackpotNotifications()
const { current, visible, imageUrl, closing } = notifications

const imageError = ref(false)
watch(current, () => {
  imageError.value = false
})

onMounted(() => {
  if (loggedIn.value && flag('event_jackpot_visible')) void notifications.load()
})
</script>

<template>
  <div
    v-if="visible && current"
    class="fixed inset-0 z-50 flex items-center justify-center bg-scrim p-4"
  >
    <div class="relative max-h-[95vh] w-full max-w-md overflow-auto rounded bg-surface p-4 text-center">
      <img
        v-if="!imageError"
        :src="imageUrl"
        class="mx-auto max-h-[80vh] w-full object-contain"
        draggable="false"
        @error="imageError = true"
      >
      <div
        v-else
        class="space-y-2 py-8"
      >
        <p class="text-lg font-semibold">
          {{ $t('events.jackpot.title.congratulations') }}
        </p>
        <p>
          {{ $t('events.claim.msg.congratulations') }}
          <strong class="mx-1 text-accent">{{ formatEventAmount(current.point) }}</strong>
          {{ settings?.text_wallet_point }}
        </p>
      </div>
      <button
        type="button"
        class="mt-3 rounded bg-accent px-4 py-2 text-inverse disabled:opacity-40"
        :disabled="closing"
        @click="notifications.close()"
      >
        {{ $t('common.ui.action.close') }}
      </button>
    </div>
  </div>
</template>
