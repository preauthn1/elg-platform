<script setup lang="ts">
import type { StyleValue } from 'vue';

import type { PageProps } from './types';

import {
  computed,
  inject,
  nextTick,
  onMounted,
  provide,
  ref,
  useTemplateRef,
} from 'vue';

import { CSS_VARIABLE_LAYOUT_CONTENT_HEIGHT } from '@vben-core/shared/constants';
import { cn } from '@vben-core/shared/utils';

import { PAGE_NESTED_KEY } from './context';

defineOptions({
  name: 'Page',
});

const {
  autoContentHeight = false,
  heightOffset = 0,
  footerFixed = false,
} = defineProps<PageProps>();

// 巢狀在別的 Page 裡（彈窗載入的設定頁）時不保留底部空間，避免彈窗捲到底多一截空白
const isNested = inject(PAGE_NESTED_KEY, false);
provide(PAGE_NESTED_KEY, true);

/**
 * 底部保留給全域回頂部按鈕（VbenBackTop：直徑 40 + bottom 20 + 呼吸 20 = 80px，pb-20）。
 * autoContentHeight 頁面自帶內部捲動、文件層不會捲，按鈕不會出現，不保留。
 * target/tickets#4399
 */
const reserveBackTopSpace = computed(() => !autoContentHeight && !isNested);

const headerHeight = ref(0);
const footerHeight = ref(0);
const shouldAutoHeight = ref(false);

const headerRef = useTemplateRef<HTMLDivElement>('headerRef');
const footerRef = useTemplateRef<HTMLDivElement>('footerRef');

const contentStyle = computed<StyleValue>(() => {
  if (autoContentHeight) {
    return {
      height: `calc(var(${CSS_VARIABLE_LAYOUT_CONTENT_HEIGHT}) - ${headerHeight.value}px - ${footerHeight.value}px - ${typeof heightOffset === 'number' ? `${heightOffset}px` : heightOffset})`,
      overflowY: shouldAutoHeight.value ? 'auto' : 'unset',
    };
  }
  return {};
});

async function calcContentHeight() {
  if (!autoContentHeight) {
    return;
  }
  shouldAutoHeight.value = false;
  await nextTick();
  headerHeight.value = headerRef.value?.offsetHeight || 0;

  footerHeight.value = footerFixed ? 0 : footerRef.value?.offsetHeight || 0;

  setTimeout(() => {
    shouldAutoHeight.value = true;
  }, 30);
}

onMounted(() => {
  calcContentHeight();
});
</script>

<template>
  <div
    :class="
      cn('relative flex min-h-full flex-col', reserveBackTopSpace && 'pb-20')
    "
  >
    <div
      v-if="
        description ||
        $slots.description ||
        title ||
        $slots.title ||
        $slots.extra
      "
      ref="headerRef"
      :class="
        cn(
          'relative flex items-end border-b border-border bg-card px-6 py-4',
          headerClass,
        )
      "
    >
      <div class="flex-auto">
        <slot name="title">
          <div v-if="title" class="mb-2 flex text-lg font-semibold">
            {{ title }}
          </div>
        </slot>

        <slot name="description">
          <p v-if="description" class="text-muted-foreground">
            {{ description }}
          </p>
        </slot>
      </div>

      <div v-if="$slots.extra">
        <slot name="extra"></slot>
      </div>
    </div>

    <div :class="cn('h-full p-4', contentClass)" :style="contentStyle">
      <slot></slot>
    </div>
    <div
      v-if="$slots.footer"
      ref="footerRef"
      :class="cn('align-center flex bg-card px-6 py-4', footerClass)"
    >
      <slot name="footer"></slot>
    </div>
  </div>
</template>
