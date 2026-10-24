<script setup lang="ts">
/**
 * 頂部 APP 提示列（設計稿首頁最上方）。可關閉，關掉後這一輪不再出現。
 *
 * **已經不提供 APK／IPA 下載，改成把網站加到主畫面（PWA）。**
 * 按下去叫瀏覽器的安裝視窗；叫不出來（iOS Safari 或瀏覽器判定不可安裝）
 * 就顯示手動加入主畫面的說明。作法參考舊前台 C13 的 ChangeIcon 頁。
 */
const { site } = useSite()
const { canShow, install } = usePwaInstall()

const dismissed = ref(false)
const busy = ref(false)
const showManualHint = ref(false)

/** SSR 時 canShow 恆為 true（讀不到瀏覽器狀態），水合後才會收斂。 */
const visible = computed(() => !dismissed.value && canShow.value)
const icon = computed(() => site.value?.raw.icon || '')

async function onInstall() {
  if (busy.value) return
  busy.value = true
  try {
    const outcome = await install()
    showManualHint.value = outcome === 'unsupported'
  }
  finally {
    busy.value = false
  }
}
</script>

<template>
  <div
    v-if="visible"
    class="relative z-30"
    :style="{ background: 'var(--c17-downloadbar-bg)', boxShadow: 'var(--c17-downloadbar-shadow)' }"
  >
    <div
      class="mx-auto flex h-[52px] max-w-shell items-center gap-[var(--c17-gap-3)]"
      :style="{ paddingInline: 'var(--c17-pad-x)' }"
    >
      <button
        type="button"
        class="grid size-6 shrink-0 place-items-center"
        :style="{ color: 'var(--c17-downloadbar-close)' }"
        :aria-label="$t('common.ui.action.close')"
        @click="dismissed = true"
      >
        <svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          class="size-5"
          aria-hidden="true"
        >
          <path d="M6 6l12 12M18 6L6 18" />
        </svg>
      </button>

      <img
        v-if="icon"
        :src="icon"
        alt=""
        class="size-8 shrink-0 rounded-[var(--c17-radius-sm)] object-cover"
      >

      <!--
        設計稿在圖示與按鈕之間留了一大塊空白（文案是站台自訂的，所以沒畫死）。
        這裡放一行短促銷詞，兩行封頂避免把 52 高的列撐開。
      -->
      <p
        class="line-clamp-2 min-w-0 flex-1 font-semibold leading-tight text-inverse"
        :style="{ fontSize: 'var(--c17-text-label)' }"
      >
        {{ $t('site.pwa.text.barPromo') }}
      </p>

      <!--
        高度 **32**：設計稿素材裡膠囊的實際範圍是 @2x 158×64（含外框與圓端），換算 79×32。
        量的時候要比對「膠囊位置 vs 同一列的背板顏色」求邊界，
        只抓內部填色會少算外框，得到偏小的 26。寬度不鎖，14 個語系字長不一。
      -->
      <button
        type="button"
        class="inline-flex h-[32px] items-center rounded-[var(--c17-radius-pill)] border px-4 font-bold text-inverse disabled:opacity-60"
        :style="{
          fontSize: 'var(--c17-text-link)',
          background: 'var(--c17-downloadbar-pill-bg)',
          borderColor: 'var(--c17-downloadbar-pill-line)',
        }"
        :disabled="busy"
        @click="onInstall"
      >
        {{ $t('site.pwa.action.clickDownload') }}
      </button>
    </div>

    <p
      v-if="showManualHint"
      class="mx-auto max-w-shell px-(--c17-pad-x) pb-(--c17-gap-2) text-(length:--c17-text-label) text-inverse/80"
    >
      {{ $t('site.pwa.text.installUnsupported') }}
    </p>
  </div>
</template>
