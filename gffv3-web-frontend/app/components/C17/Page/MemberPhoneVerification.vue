<script setup lang="ts">
/**
 * 綁定手機，照舊版 C13 `Page/PhoneVerification.vue` 的 /phone-verification：成功後不另外提示，直接回首頁。
 * 登入時被 9007/324 擋下的未登入版本屬 auth 領域。
 */

const member = useMember()

onMounted(() => {
  void member.ensureLoaded()
})

async function onDone() {
  await navigateTo('/', { replace: true })
}
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('auth.otp.label.phoneVerification')"
      fallback="/member/setting"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <div class="rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]">
        <MemberBindChannelForm
          channel="mobile"
          @done="onDone"
        />
      </div>
    </div>
  </div>
</template>
