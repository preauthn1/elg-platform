<script setup lang="ts">
import type { NuxtError } from '#app'

defineProps<{ error: NuxtError }>()
</script>

<template>
  <div class="mx-auto max-w-md py-16 text-center">
    <h1 class="text-3xl font-semibold">
      {{ error.statusCode }}
    </h1>
    <p class="mt-2 text-muted">
      {{ error.statusMessage || error.message }}
    </p>
    <button
      class="mt-6 text-accent"
      type="button"
      @click="clearError({ redirect: '/' })"
    >
      回首頁
    </button>
  </div>
</template>
