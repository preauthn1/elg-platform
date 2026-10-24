<script lang="ts" setup>
import { computed, ref, watch } from 'vue';

import { UserRound } from '@vben/icons';

/**
 * 通用頭像：有圖顯示圖（object-cover），沒圖 / 'null' / 載入失敗顯示預設 user icon。
 */
defineOptions({ name: 'UserAvatar' });

const props = withDefaults(
  defineProps<{
    /** 形狀，預設圓形 */
    shape?: 'circle' | 'square';
    /** 尺寸：數字(px) 或 CSS 長度字串 */
    size?: number | string;
    /** 頭像網址（空字串 / 'null' 視為無頭像） */
    src?: null | string;
  }>(),
  { shape: 'circle', size: 36, src: '' },
);

const failed = ref(false);
watch(
  () => props.src,
  () => {
    failed.value = false;
  },
);

// 後端常回 'null' 字串或空字串，載入失敗也回退預設圖
const realSrc = computed(() =>
  !failed.value && props.src && props.src !== 'null' ? props.src : '',
);
const sizePx = computed(() =>
  typeof props.size === 'number' ? `${props.size}px` : props.size,
);
// lucide 用 size prop(px) 控制大小，取頭像的 ~62%，留白不頂邊
const iconSize = computed(() =>
  typeof props.size === 'number' ? Math.round(props.size * 0.62) : 22,
);
</script>

<template>
  <div
    class="bg-muted-foreground/20 text-muted-foreground inline-flex shrink-0 select-none items-center justify-center overflow-hidden"
    :class="shape === 'circle' ? 'rounded-full' : 'rounded'"
    :style="{ height: sizePx, width: sizePx }"
  >
    <img
      v-if="realSrc"
      :src="realSrc"
      class="h-full w-full object-cover"
      @error="failed = true"
    />
    <UserRound v-else :size="iconSize" :stroke-width="1.75" />
  </div>
</template>
