<script setup lang="ts">
/**
 * C17 頂部列（設計稿第 4 頁）：左邊站台 logo，右邊餘額與存款入口。
 * 未登入時右側改成登入，餘額不顯示。
 */
const { site } = useSite()
const { loggedIn } = useUserSession()
const wallet = useWalletStore()
const { refreshBalance } = useWallet()

const logo = computed(() => site.value?.raw.logo_mobile || site.value?.raw.logo_pc || '')
const title = computed(() => site.value?.raw.title || site.value?.domain || '')

onMounted(() => {
  if (loggedIn.value && wallet.balance === null) void refreshBalance()
})
</script>

<template>
  <header
    class="sticky top-0 z-20"
    :style="{ background: 'var(--c17-header-bg)' }"
  >
    <div
      class="mx-auto flex max-w-shell items-center gap-[var(--c17-gap-3)]"
      :style="{ padding: `var(--c17-gap-2) var(--c17-pad-x)` }"
    >
      <NuxtLink
        to="/"
        class="flex min-w-0 shrink-0 items-center"
      >
        <img
          v-if="logo"
          :src="logo"
          :alt="title"
          class="h-[34px] w-auto max-w-[5.625rem] object-contain"
        >
        <span
          v-else
          class="truncate font-bold text-inverse"
          :style="{ fontSize: 'var(--c17-text-body)' }"
        >{{ title }}</span>
      </NuxtLink>

      <div class="flex-1" />

      <!-- 餘額膠囊與存款鈕與遊戲進行頁共用同一份，見 C17BalancePill -->
      <C17BalancePill v-if="loggedIn" />

      <NuxtLink
        v-else
        to="/login"
        class="rounded-[var(--c17-radius-pill)] bg-surface px-4 py-1.5 font-bold"
        :style="{ color: 'var(--c17-primary)', fontSize: 'var(--c17-text-link)' }"
      >
        {{ $t('auth.login.action.login') }}
      </NuxtLink>
    </div>
  </header>
</template>
