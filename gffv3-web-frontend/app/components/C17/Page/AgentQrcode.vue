<script setup lang="ts">
/**
 * 我的推廣二維碼。舊皮 C13 的 AgentQrcode：推廣碼 ＋ QR ＋ 保存到相簿。
 *
 * 連結分兩種：TG 使用者要的是 example.invalid startapp，其餘是一般推薦連結，
 * 這條規則收在 useAgentTgBot.resolveShareLinks，不要在這頁自己拼。
 * 找不到 bot 時沿用後端 link 並提示（舊皮同樣的處理）。
 */
const {
  summary,
  botMissing,
  links,
  qrSrc,
  copied,
  copyLink,
} = useAgentQrcodePage()
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('agent.promote.label.qrCode')"
      fallback="/agent"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <section
        v-if="summary"
        class="flex flex-col items-center gap-(--c17-gap-3) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-4)"
        :style="{ boxShadow: 'var(--c17-shadow-panel)' }"
      >
        <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }">
          {{ $t('agent.promote.label.promoteCode') }}
        </p>
        <p
          class="font-extrabold tracking-wide"
          :style="{ fontFamily: 'var(--c17-font-display)', fontSize: 'var(--c17-text-title)', color: 'var(--c17-ink)' }"
        >
          {{ summary.code }}
        </p>

        <UiQrCode
          v-model:src="qrSrc"
          :value="links?.qrLink ?? ''"
          :size="200"
        />

        <p
          v-if="botMissing"
          :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-error)' }"
        >
          {{ $t('agent.promote.msg.botNotFound') }}
        </p>

        <!-- 保存到相簿：QR 本身就是 data URL，直接讓瀏覽器下載，不用再繞一次 canvas -->
        <a
          v-if="qrSrc"
          :href="qrSrc"
          :download="`${summary.code}.png`"
          class="flex h-(--c17-btn-h) w-full items-center justify-center rounded-(--c17-field-radius) bg-accent font-extrabold uppercase text-inverse"
          :style="{ fontFamily: 'var(--c17-font-label)', fontSize: 'var(--c17-text-body)' }"
        >
          {{ $t('agent.promote.action.saveImage') }}
        </a>
      </section>

      <section
        v-if="links"
        class="flex flex-col gap-(--c17-gap-2) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3)"
        :style="{ boxShadow: 'var(--c17-shadow-panel)' }"
      >
        <p :style="{ fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }">
          {{ $t('agent.promote.label.promoteLink') }}
        </p>
        <p
          class="break-all"
          :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
        >
          {{ links.copyLink }}
        </p>
        <UiButton
          variant="secondary"
          @click="copyLink"
        >
          {{ copied ? $t('common.ui.msg.copied') : $t('common.ui.action.copy') }}
        </UiButton>
      </section>
    </div>
  </div>
</template>
