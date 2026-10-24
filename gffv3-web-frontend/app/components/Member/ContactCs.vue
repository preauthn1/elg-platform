<script setup lang="ts">
/** 「沒有收到驗證碼？聯繫」，照舊版 C13 `Base/ContactCS.vue`：放在表單送出鈕下方，點了開客服。 */
</script>

<template>
  <p class="text-center text-(length:--c17-text-link) text-ink">
    {{ $t('auth.otp.hint.codeNotReceived') }}
    <NuxtLink
      to="/chat"
      class="text-link"
    >
      {{ $t('site.cs.nav.contact') }}
    </NuxtLink>
  </p>
</template>
