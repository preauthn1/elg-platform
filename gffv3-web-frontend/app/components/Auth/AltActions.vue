<script setup lang="ts">
/**
 * 登入／註冊卡片下半部：「OR」分隔、Telegram 登入、客服、貼在卡片底的切換連結。
 *
 * 設計稿 login 與 register 這一段一模一樣（座標只差整體位移）：
 *   OR        兩條 120×1 的線 **#d8ff1a 螢光**，中間 "OR" PJS SemiBold 10 #1a1f36
 *   Telegram  318×32 r=8 fill #d8ff1a，字 PJS ExtraBold 14 #1a1f36
 *   客服      318×32 r=8 fill #f1f4f8 stroke #1565ff 1px，字 PJS ExtraBold 14 #1565ff
 *   頁尾      "Don't have an account? Register Now" PJS SemiBold 12 **#1565ff** 整句，
 *             貼在卡片底部（[195,766]，卡片 45..799）——不是緊接著客服鈕
 *
 * login 與 signup 共用這一份，不要各抄一份。最容易抄錯的是三個顏色：OR 線是螢光 #d8ff1a
 * （不是主色藍）、客服底是 #f1f4f8（不是純白）、頁尾整句是藍字（不是灰字）。
 */
defineProps<{
  /** Telegram 登入／註冊的目的地；沒給就不顯示那顆鈕（站台沒開 tgbot_login_visible） */
  telegramTo?: string | Record<string, unknown>
  /** 頁尾那句的前半，例如「還沒有帳號？」 */
  footerHint: string
  /** 頁尾連結文字，例如「立即註冊」 */
  footerAction: string
  footerTo: string
}>()
</script>

<template>
  <!-- OR：螢光線 + 深色字。bg-accent 是藍色主色，用錯就變藍線 -->
  <div class="my-(--c17-gap-4) flex items-center gap-3 text-(length:--c17-text-label) text-ink">
    <span class="h-px flex-1 bg-highlight" />
    {{ $t('common.ui.label.or') }}
    <span class="h-px flex-1 bg-highlight" />
  </div>

  <div class="space-y-(--c17-gap-2)">
    <NuxtLink
      v-if="telegramTo"
      :to="telegramTo"
      class="flex h-(--c17-btn-h) w-full items-center justify-center gap-2 rounded-(--c17-field-radius) bg-highlight font-extrabold text-ink"
      :style="{ fontFamily: 'var(--c17-font-label)', fontSize: 'var(--c17-text-body)' }"
    >
      <svg
        viewBox="0 0 24 24"
        class="size-4"
        fill="currentColor"
        aria-hidden="true"
      >
        <path d="M21.9 4.3 18.6 20c-.2 1-.9 1.3-1.7.8l-4.7-3.5-2.3 2.2c-.3.3-.5.5-1 .5l.4-4.9 8.9-8c.4-.3-.1-.5-.6-.2L6.5 13.5l-4.7-1.5c-1-.3-1-1 .2-1.5l18.4-7.1c.9-.3 1.6.2 1.5 1.9z" />
      </svg>
      {{ $t('auth.telegram.action.continue') }}
    </NuxtLink>

    <NuxtLink
      to="/cs"
      class="flex h-(--c17-btn-h) w-full items-center justify-center rounded-(--c17-field-radius) border border-accent bg-surface-2 font-extrabold text-accent"
      :style="{ fontFamily: 'var(--c17-font-label)', fontSize: 'var(--c17-text-body)' }"
    >
      {{ $t('site.cs.label.customerService') }}
    </NuxtLink>
  </div>

  <!-- 貼卡片底（UiModal 的內容區是 flex-col，mt-auto 才推得下去）；整句 #1565ff -->
  <p
    v-if="footerHint || footerAction"
    class="mt-auto pt-(--c17-gap-6) text-center font-semibold text-accent"
    :style="{ fontSize: 'var(--c17-text-link)' }"
  >
    {{ footerHint }}
    <NuxtLink
      :to="footerTo"
      class="underline"
    >
      {{ footerAction }}
    </NuxtLink>
  </p>
</template>
