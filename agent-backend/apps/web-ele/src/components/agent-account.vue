<script lang="ts" setup>
import CopyIcon from '#/components/copy-icon.vue';

/**
 * 代理／後台帳號共用元件（工單 4342）：代理商帳號、上級代理、總代理、操作人員、
 * 轉出／轉入代理、股東帳號等一律用此元件；會員帳號請用 MemberAccountLink。
 *
 * 純文字顯示，本身沒有任何點擊行為、不開彈窗，只在右側掛常駐複製 icon。
 */
defineProps<{
  username?: null | string;
}>();
</script>

<template>
  <span>
    <span>{{ username || '-' }}</span>
    <CopyIcon :text="username" />
  </span>
</template>
