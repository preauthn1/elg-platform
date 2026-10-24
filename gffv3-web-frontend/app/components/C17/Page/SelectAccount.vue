<script setup lang="ts">
/** C17 的畫面，邏輯在 useSelectAccountPage。 */
const {
  store,
  choose,
} = useSelectAccountPage()
</script>

<template>
  <div class="mx-auto max-w-sm space-y-4">
    <h1 class="text-xl font-semibold">
      {{ $t('auth.login.title.selectAccount') }}
    </h1>
    <ul class="divide-y divide-line rounded border border-line bg-surface">
      <li
        v-for="username in store.accountList"
        :key="username"
      >
        <button
          type="button"
          class="w-full px-4 py-2 text-left hover:bg-page"
          @click="choose(username)"
        >
          {{ username }}
        </button>
      </li>
    </ul>
    <NuxtLink
      to="/forget-password"
      class="text-sm text-accent underline"
    >
      {{ $t('auth.password.action.forget') }}
    </NuxtLink>
  </div>
</template>
