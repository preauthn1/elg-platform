<script lang="ts" setup>
import { ElButton, ElButtonGroup } from 'element-plus';

import { tl } from '#/locales';
import {
  SIX_TYPES_KINDS,
  sixTypesQuickRange,
  sixTypesQuickRangeDate,
} from '#/utils/quick-range';

defineOptions({ name: 'QuickRangeButtons' });

const props = withDefaults(
  defineProps<{
    dateOnly?: boolean;
    /** 固定時區偏移（小時）。固定 GMT+8 的頁面傳 8；不傳＝跟使用者所選時區。 */
    fixedOffset?: number;
  }>(),
  {
    dateOnly: false,
    fixedOffset: undefined,
  },
);

const emit = defineEmits<{ select: [range: [string, string]] }>();

function pick(kind: string) {
  emit(
    'select',
    props.dateOnly
      ? sixTypesQuickRangeDate(kind, props.fixedOffset)
      : sixTypesQuickRange(kind, props.fixedOffset),
  );
}
</script>

<template>
  <ElButtonGroup>
    <ElButton
      v-for="k in SIX_TYPES_KINDS"
      :key="k"
      size="small"
      @click="pick(k)"
    >
      {{ tl(k) }}
    </ElButton>
  </ElButtonGroup>
</template>
