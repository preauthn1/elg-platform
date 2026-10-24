<script setup lang="ts">
import { PUSH_PROMPT_BRANDS, pwaAppFor } from '#shared/constants/pwa'
import { detectPlatform } from '#shared/utils/games'

/**
 * 「開啟通知」提示，對應舊前台 components/common/PushNotificationPrompt.vue（掛在 C9／C14 首頁）。
 * 只在從主畫面開啟（PWA）、支援通知、還沒授權的品牌跳，一天最多一次。
 */
const { brand, template } = useSite()
const push = usePushNotification()

type Permission = NotificationPermission | 'unsupported'

const visible = ref(false)
const permission = ref<Permission>('unsupported')

const storageKey = computed(() => `pushNotificationPromptShownDate:${template.value?.skin ?? ''}${template.value?.variant ?? ''}`)
const isIOS = computed(() => import.meta.client && detectPlatform(navigator.userAgent).isIOS)
/** 系統設定裡看到的 App 名稱就是 manifest 的 name，舊前台用 document.title（載入 manifest 後同值） */
const appName = computed(() => pwaAppFor(brand.value)?.name ?? '')

function readPermission(): Permission {
  return 'Notification' in window ? Notification.permission : 'unsupported'
}

function isStandalone(): boolean {
  return window.matchMedia?.('(display-mode: standalone)').matches === true
    || (window.navigator as { standalone?: boolean }).standalone === true
}

function today(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function readShownDate(): string | null {
  try {
    return localStorage.getItem(storageKey.value)
  }
  catch {
    return null
  }
}

function markShownToday() {
  try {
    localStorage.setItem(storageKey.value, today())
  }
  catch {
    // 無痕模式等存不了就算了，頂多同一天再跳一次
  }
}

function syncPermission() {
  permission.value = readPermission()
  if (permission.value === 'granted') visible.value = false
}

async function confirm() {
  if (permission.value === 'denied') {
    visible.value = false
    return
  }
  try {
    permission.value = await Notification.requestPermission()
    if (permission.value === 'granted') {
      await push.init()
      await push.listenForeground()
    }
  }
  finally {
    visible.value = false
  }
}

onMounted(() => {
  permission.value = readPermission()
  const shouldShow = PUSH_PROMPT_BRANDS.includes(brand.value)
    && isStandalone()
    && canUseNotification()
    && permission.value !== 'granted'
    && readShownDate() !== today()
  if (shouldShow) {
    visible.value = true
    markShownToday()
  }
  window.addEventListener('focus', syncPermission)
})

onBeforeUnmount(() => window.removeEventListener('focus', syncPermission))
</script>

<template>
  <UiModal
    v-if="visible"
    @close="visible = false"
  >
    <div class="flex flex-col items-center gap-(--c17-gap-3) text-center">
      <span class="flex size-14 items-center justify-center rounded-full bg-accent-soft text-accent">
        <svg
          viewBox="0 0 24 24"
          class="size-8"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
          <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
        </svg>
      </span>
      <p
        class="font-extrabold"
        :style="{ fontSize: 'var(--c17-text-title)' }"
      >
        {{ $t('messages.push.title.enablePrompt') }}
      </p>
      <p :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-muted)' }">
        {{ $t('messages.push.msg.enablePrompt') }}
      </p>
      <p
        v-if="permission === 'denied'"
        :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-muted)' }"
      >
        {{ $t(isIOS ? 'messages.push.hint.iosGuide' : 'messages.push.hint.androidGuide', { value: appName }) }}
      </p>
      <div class="mt-(--c17-gap-2) flex w-full gap-(--c17-gap-3)">
        <UiButton
          variant="secondary"
          @click="visible = false"
        >
          {{ $t('common.ui.action.cancel') }}
        </UiButton>
        <UiButton @click="confirm">
          {{ $t(permission === 'denied' ? 'common.ui.action.determine' : 'common.ui.action.continue') }}
        </UiButton>
      </div>
    </div>
  </UiModal>
</template>
