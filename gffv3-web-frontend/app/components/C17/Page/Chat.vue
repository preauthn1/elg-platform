<script setup lang="ts">
/**
 * 線上客服。舊前台 C13 的 /chat：把客服系統以 iframe 嵌進來，
 * 兩邊用 postMessage 溝通。之前這個路由不存在（提款頁的「聯繫客服」點了 404）。
 *
 * 客服站台網址是每個部署不同的環境變數（舊專案的 API_CHATPATH），
 * 這裡改讀 runtimeConfig.public.chatBaseUrl；沒設定就顯示未設定提示，不硬寫網址。
 *
 * postMessage 只收兩種訊息，而且一定先比對 origin：
 *   type 1 → 客服要前台換頁（clearParent 為真時先登出）
 *   type 5 → 客服要跑馬燈文字，回傳給它
 */
const {
  chatBaseUrl,
  ready,
  showHelp,
} = useChatPage()
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between gap-3">
      <h1 class="text-lg font-semibold">
        {{ $t('site.cs.nav.onlineService') }}
      </h1>
      <button
        type="button"
        class="size-7 rounded-full border border-line text-sm"
        :aria-label="$t('common.ui.title.prompt')"
        @click="showHelp = true"
      >
        ?
      </button>
    </div>

    <iframe
      v-if="ready"
      :src="chatBaseUrl"
      class="h-[70vh] w-full rounded border border-line"
      allow="camera *;"
      title="customer service"
    />
    <p
      v-else
      class="rounded border border-warning bg-warning-soft px-3 py-2 text-sm text-warning"
    >
      客服站台網址尚未設定（NUXT_PUBLIC_CHAT_BASE_URL）。
    </p>

    <!-- 開不起來時的自救說明，沿用舊皮的兩個解法 -->
    <div
      v-if="showHelp"
      class="space-y-2 rounded border border-line bg-surface p-4 text-sm"
    >
      <p class="font-semibold">
        {{ $t('site.cs.nav.onlineService') }}
      </p>
      <p>
        <a
          v-if="ready"
          :href="chatBaseUrl"
          target="_blank"
          rel="noopener"
          class="text-accent underline"
        >{{ $t('site.cs.nav.contact') }}</a>
      </p>
      <button
        type="button"
        class="rounded border border-line px-3 py-1"
        @click="showHelp = false"
      >
        {{ $t('common.ui.action.close') }}
      </button>
    </div>

    <EventsPrizeReminder />
  </div>
</template>
