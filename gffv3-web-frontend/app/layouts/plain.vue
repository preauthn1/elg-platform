<script setup lang="ts">
const { site, error } = useSite()
const { loggedIn, user, clear } = useUserSession()
const { locale, locales, setLocale } = useI18n()

async function logout() {
  await clear()
  await navigateTo('/login')
}
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <header class="border-b border-line bg-surface">
      <div class="mx-auto flex max-w-5xl items-center gap-4 px-4 py-3">
        <NuxtLink
          to="/"
          class="font-semibold"
        >
          {{ site?.raw.title || site?.domain || 'web-frontend' }}
        </NuxtLink>
        <span
          v-if="site"
          class="text-xs text-muted"
        >
          {{ site.brand }} · {{ site.template.skin }}{{ site.template.variant }} · {{ site.template.process }} · {{ site.device }}
        </span>
        <div class="flex-1" />
        <select
          class="rounded border border-line px-2 py-1 text-sm"
          :value="locale"
          @change="setLocale(($event.target as HTMLSelectElement).value as typeof locale)"
        >
          <option
            v-for="l in locales"
            :key="l.code"
            :value="l.code"
          >
            {{ l.name }}
          </option>
        </select>
        <template v-if="loggedIn">
          <span class="text-sm">{{ user?.username }}</span>
          <button
            class="text-sm text-accent"
            type="button"
            @click="logout"
          >
            {{ $t('auth.logout.action.signOut') }}
          </button>
        </template>
        <NuxtLink
          v-else
          to="/login"
          class="text-sm text-accent"
        >
          {{ $t('auth.login.action.login') }}
        </NuxtLink>
      </div>
    </header>

    <div
      v-if="error"
      class="bg-warning-soft px-4 py-2 text-center text-sm text-warning"
    >
      站台解析失敗（{{ error.host }}）：{{ error.message }}
    </div>

    <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-6">
      <slot />
    </main>
  </div>
</template>
