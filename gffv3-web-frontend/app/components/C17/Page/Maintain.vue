<script setup lang="ts">
// 維護頁不要外殼：頂部列會去打餘額 API，站台正在維護時不該再發請求。

const message = useState<string>('maintain-message', () => '')
</script>

<template>
  <div class="mx-auto max-w-md py-16 text-center">
    <h1 class="text-2xl font-semibold">
      {{ $t('common.ui.label.maintenance') }}
    </h1>
    <p
      v-if="message"
      class="mt-4 text-muted"
    >
      {{ message }}
    </p>
  </div>
</template>
