<script setup lang="ts">
/**
 * 驗證碼欄位旁的 SEND 鈕（只管外觀，發碼邏輯在 AuthSendCodeButton／MemberSendCodeButton）。
 *
 * 設計稿（login-reset-pw-mobile）：SEND 是 140×32、主色底、字 Plus Jakarta Sans
 * ExtraBold 14 大寫 #f1f4f8，不可按時底色 #7a8599。
 * 文案用 `common.ui.action.send`（"Send"）不是 `auth.otp.action.sendCode`
 * （"Send Verification Code"）——設計稿上這顆就寫 SEND，而且 140 寬放不下長字串。
 * 倒數只接數字，同樣是為了塞得進去。
 */
defineProps<{
  /** 倒數秒數；> 0 時接在 SEND 後面 */
  remaining: number
  disabled?: boolean
}>()

defineEmits<{ click: [] }>()
</script>

<template>
  <button
    type="button"
    class="h-(--c17-field-h) w-full rounded-(--c17-field-radius) px-1 font-extrabold uppercase"
    :style="{
      fontSize: 'var(--c17-text-body)',
      background: disabled ? 'var(--c17-muted)' : 'var(--c17-primary)',
      color: 'var(--c17-primary-ink)',
    }"
    :disabled="disabled"
    @click="$emit('click')"
  >
    <span class="line-clamp-1">
      {{ $t('common.ui.action.send') }}<template v-if="remaining > 0"> ({{ remaining }})</template>
    </span>
  </button>
</template>
