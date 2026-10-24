<script lang="ts" setup>
import { computed } from 'vue';

import { Copy } from '@vben/icons';

import { ElMessage } from 'element-plus';

import { tl } from '#/locales';
import { copyText, isCopyable } from '#/utils/clipboard';

/**
 * 帳號複製 icon（工單 4342）：常駐顯示、點擊不冒泡（避免觸發會員詳情抽屜）、
 * 複製的是帳號本身而非顯示文字；空值／`-` 不顯示；成功／失敗皆提示。
 */
const props = defineProps<{
  text?: null | string;
}>();

/** 去掉前後空白後的帳號，空值或 `-` 視為沒有可複製的內容 */
const value = computed(() =>
  typeof props.text === 'string' ? props.text.trim() : '',
);

const visible = computed(() => isCopyable(props.text));

async function onCopy() {
  const ok = await copyText(value.value);
  if (ok) {
    ElMessage({
      duration: 1500,
      grouping: true,
      message: tl('copied'),
      type: 'success',
    });
    return;
  }
  ElMessage({ grouping: true, message: tl('copy_failed'), type: 'error' });
}
</script>

<template>
  <Copy
    v-if="visible"
    :title="tl('copy')"
    class="text-muted-foreground hover:text-primary ml-1 inline-block size-3.5 cursor-pointer align-middle"
    @click.stop.prevent="onCopy"
  />
</template>
