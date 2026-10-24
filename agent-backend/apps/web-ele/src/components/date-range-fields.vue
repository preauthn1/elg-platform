<script lang="ts" setup>
import { computed } from 'vue';

import dayjs from 'dayjs';
import { ElDatePicker } from 'element-plus';

import QuickRangeButtons from '#/components/quick-range-buttons.vue';
import { tl } from '#/locales';

/**
 * 日期區間欄位：拆成「起日 - 迄日」兩個單日期選擇器（各只展開一個月曆，
 * 手機也塞得下），下方接快速日期鈕（今日/昨日…）。
 * v-model 綁定 [start, end] 字串陣列。
 */
defineOptions({ name: 'DateRangeFields' });

const props = withDefaults(
  defineProps<{
    /** 快速鈕固定時區偏移（小時）。固定 GMT+8 的頁面傳 8；不傳＝跟使用者所選時區。 */
    fixedOffset?: number;
    /** 選擇器格式（顯示） */
    format?: string;
    /**
     * 期間只到「小時」（datetime 才有效）：起始時間的分鐘固定 00、結束固定 59，
     * 分鐘欄只能選這一格，手動輸入其他分鐘也會被校正。給以整點彙整表為資料源的報表用（SR28）。
     */
    hourOnly?: boolean;
    /** 是否顯示下方快速日期鈕（頁面已自備快速選擇時可關掉） */
    quick?: boolean;
    /** date=純日期；datetime=日期+時間 */
    type?: 'date' | 'datetime';
    /** 回傳值格式 */
    valueFormat?: string;
  }>(),
  {
    fixedOffset: undefined,
    format: 'YYYY-MM-DD',
    hourOnly: false,
    quick: true,
    type: 'date',
    valueFormat: 'YYYY-MM-DD',
  },
);

const model = defineModel<string[]>({ default: () => ['', ''] });

/** hourOnly 時把起始校正到整點、結束校正到該小時的 59 分；其餘情況原值不動 */
function snap(v: string, edge: 'end' | 'start'): string {
  if (!v || !props.hourOnly || props.type !== 'datetime') return v;
  const d = dayjs(v);
  if (!d.isValid()) return v;
  return (edge === 'start' ? d.startOf('hour') : d.endOf('hour')).format(
    props.valueFormat,
  );
}

const start = computed({
  get: () => model.value?.[0] ?? '',
  set: (v: string) => {
    model.value = [snap(v ?? '', 'start'), model.value?.[1] ?? ''];
  },
});

const end = computed({
  get: () => model.value?.[1] ?? '',
  set: (v: string) => {
    model.value = [model.value?.[0] ?? '', snap(v ?? '', 'end')];
  },
});

const ALL_MINUTES = Array.from({ length: 60 }, (_, i) => i);

/** hourOnly：分鐘欄只留 00（起）／59（迄）可選；不是 hourOnly 回 undefined＝不限制 */
const disabledStartMinutes = computed(() =>
  props.hourOnly ? () => ALL_MINUTES.filter((m) => m !== 0) : undefined,
);
const disabledEndMinutes = computed(() =>
  props.hourOnly ? () => ALL_MINUTES.filter((m) => m !== 59) : undefined,
);

/** datetime 用含時間的快速範圍，date 用純日期 */
const quickDateOnly = computed(() => props.type !== 'datetime');

const popperOptions = {
  modifiers: [{ enabled: false, name: 'flip' }],
};

function applyQuick(range: [string, string]) {
  model.value = [
    snap(dayjs(range[0]).startOf('minute').format(props.valueFormat), 'start'),
    snap(dayjs(range[1]).endOf('minute').format(props.valueFormat), 'end'),
  ];
}
</script>

<template>
  <div class="w-full">
    <div class="flex w-full items-center gap-2">
      <ElDatePicker
        v-model="start"
        :disabled-minutes="disabledStartMinutes"
        :format="format"
        :placeholder="tl('label_start_time')"
        :popper-options="popperOptions"
        :type="type"
        :value-format="valueFormat"
        class="!w-full min-w-0 flex-1"
        placement="bottom-start"
      />
      <span class="text-gray-400">-</span>
      <ElDatePicker
        v-model="end"
        :disabled-minutes="disabledEndMinutes"
        :format="format"
        :placeholder="tl('label_end_time')"
        :popper-options="popperOptions"
        :type="type"
        :value-format="valueFormat"
        class="!w-full min-w-0 flex-1"
        placement="bottom-start"
      />
    </div>
    <div v-if="quick" class="mt-2 flex flex-wrap gap-1">
      <QuickRangeButtons
        :date-only="quickDateOnly"
        :fixed-offset="fixedOffset"
        @select="applyQuick"
      />
    </div>
  </div>
</template>
