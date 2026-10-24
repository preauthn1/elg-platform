<script setup lang="ts">
/**
 * /menu 只是給書籤與舊連結用的殼。選單本體在 C17/MenuDrawer.vue，由 C17Shell 掛載——
 * 設計稿的選單是蓋在當前頁面上的抽屜，不是一個頁面。
 *
 * 用 query 把「要開抽屜」帶回首頁，而不是在這裡直接 show()：這個殼在伺服器端就會 302，
 * useState 的開關不會跟著到客戶端；Shell 看到 ?menu=open 才在客戶端打開並清掉 query。
 */
await navigateTo({ path: '/', query: { menu: 'open' } }, { replace: true })
</script>

<template>
  <div />
</template>
