<script setup lang="ts">
/**
 * 設定選單的圖示。設計稿沒有給這一組素材（Profile-page 只畫到功能格那八顆），
 * 所以照 C17 既有的圖示語彙自己畫：26×26 的方框、2pt 線條、主色 #1565FF，
 * 與個人資料頁那八顆 PNG 的視覺重量一致。用 SVG 不用 PNG，換色與縮放才不會糊掉。
 *
 * 名字對應 shared/utils/member.ts 的 buildSettingMenu key；查不到就退回齒輪。
 */
defineProps<{ name: string }>()
</script>

<template>
  <svg
    viewBox="0 0 24 24"
    class="size-[26px] shrink-0"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    :style="{ color: 'var(--c17-primary)' }"
    aria-hidden="true"
  >
    <template v-if="name === 'changePhone' || name === 'bindPhone'">
      <rect
        x="6"
        y="2"
        width="12"
        height="20"
        rx="2"
      />
      <path d="M11 18h2" />
    </template>

    <template v-else-if="name === 'changeEmail' || name === 'bindEmail'">
      <rect
        x="2"
        y="5"
        width="20"
        height="14"
        rx="2"
      />
      <path d="m3 7 9 6 9-6" />
    </template>

    <template v-else-if="name === 'changePassword'">
      <rect
        x="4"
        y="10"
        width="16"
        height="11"
        rx="2"
      />
      <path d="M8 10V7a4 4 0 0 1 8 0v3" />
    </template>

    <template v-else-if="name === 'paymentPassword' || name === 'changePaymentPassword'">
      <rect
        x="2"
        y="5"
        width="20"
        height="14"
        rx="2"
      />
      <path d="M2 10h20M6 15h4" />
    </template>

    <template v-else-if="name === 'bindBankCard'">
      <path d="M3 9 12 4l9 5" />
      <path d="M5 9v9M12 9v9M19 9v9M3 21h18" />
    </template>

    <template v-else-if="name === 'googleAuthenticator'">
      <path d="M12 2 4 6v6c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V6z" />
      <path d="m9 12 2 2 4-4" />
    </template>

    <template v-else-if="name === 'ipChangeVerification'">
      <circle
        cx="12"
        cy="12"
        r="9"
      />
      <path d="M3 12h18M12 3c2.5 2.7 2.5 15 0 18-2.5-3-2.5-15.3 0-18Z" />
    </template>

    <template v-else-if="name === 'appearance'">
      <circle
        cx="12"
        cy="12"
        r="9"
      />
      <path d="M12 3v18" />
    </template>

    <template v-else-if="name === 'setBirthday'">
      <rect
        x="3"
        y="5"
        width="18"
        height="16"
        rx="2"
      />
      <path d="M8 3v4M16 3v4M3 11h18" />
    </template>

    <template v-else>
      <circle
        cx="12"
        cy="12"
        r="3"
      />
      <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2v.2a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-3-1.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0-1.2-2.9H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.3-3l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 2.9-1.2V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 3 1.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0 1.2 2.9h.2a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.6 1Z" />
    </template>
  </svg>
</template>
