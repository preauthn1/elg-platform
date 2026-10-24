<script setup lang="ts">
/**
 * C17 底部錢包列的「聯繫客服」指到 /cs，舊前台與提款頁用的是 /chat。
 * 兩個都留著，內容只有一份——這頁轉過去。
 */
definePageMeta({ requiresAuth: true })

await navigateTo('/chat', { replace: true })
</script>

<template>
  <div />
</template>
