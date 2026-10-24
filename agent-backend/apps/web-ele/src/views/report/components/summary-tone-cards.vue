<script lang="ts" setup>
export type SummaryTone =
  | 'blue'
  | 'cyan'
  | 'gray'
  | 'green'
  | 'pink'
  | 'slate'
  | 'yellow';

export interface SummaryToneCard {
  /** v-for key */
  key: string;
  /** 已翻譯的標題（父層組好） */
  label: string;
  /** 給了就依正負上色（0/非數值為中性）；未給則完全不上色，繼承預設文字色 */
  signed?: number | string;
  tone: SummaryTone;
  /** 已格式化的顯示字串（父層決定小數位，元件不碰數字格式） */
  value: string;
}

defineOptions({ name: 'SummaryToneCards' });

defineProps<{ items: SummaryToneCard[] }>();

/** 卡片底色（完整字面 class 字串，Tailwind 才掃得到） */
const TONE_CARD: Record<SummaryTone, string> = {
  blue: 'bg-[#DBEAFE] dark:bg-[#3B82F6]/[0.12]',
  cyan: 'bg-[#CFFAFE] dark:bg-[#06B6D4]/[0.12]',
  gray: 'bg-[#E5E7EB] dark:bg-[#94A3B8]/[0.12]',
  green: 'bg-[#D8F0E0] dark:bg-[#22C55E]/[0.12]',
  pink: 'bg-[#FBDDE2] dark:bg-[#EF4444]/[0.12]',
  slate: 'bg-[#CBD5E1] dark:bg-[#64748B]/[0.16]',
  yellow: 'bg-[#FBF2D3] dark:bg-[#EAB308]/[0.12]',
};

/** 標題文字色 */
const TONE_LABEL: Record<SummaryTone, string> = {
  blue: 'text-[#1D4ED8] dark:text-[#93C5FD]',
  cyan: 'text-[#0E7490] dark:text-[#67E8F9]',
  gray: 'text-[#475569] dark:text-[#CBD5E1]',
  green: 'text-[#15803D] dark:text-[#86EFAC]',
  pink: 'text-[#BE123C] dark:text-[#FDA4AF]',
  slate: 'text-[#1E293B] dark:text-[#E2E8F0]',
  yellow: 'text-[#A16207] dark:text-[#FDE68A]',
};

const VALUE_NEUTRAL = 'text-[#1F2937] dark:text-[#E5E7EB]';
const VALUE_POSITIVE = 'text-[#166534] dark:text-[#4ADE80]';
const VALUE_NEGATIVE = 'text-[#B91C1C] dark:text-[#F87171]';

/**
 * 數值色：只有「總開分-總洗分」這類差額卡（有傳 signed）才上色，
 * 0／非數值為中性；其餘四項指標完全不傳 signed，回傳空字串不上色、繼承預設文字色。
 */
function valueClass(item: SummaryToneCard) {
  if (item.signed === undefined) return '';
  const n = Number(item.signed);
  if (!Number.isFinite(n) || n === 0) return VALUE_NEUTRAL;
  if (n > 0) return VALUE_POSITIVE;
  return VALUE_NEGATIVE;
}
</script>

<template>
  <div class="grid grid-cols-2 gap-3">
    <div
      v-for="item in items"
      :key="item.key"
      :class="TONE_CARD[item.tone]"
      class="rounded-lg px-4 py-3 text-center"
    >
      <div :class="TONE_LABEL[item.tone]" class="text-xs font-medium">
        {{ item.label }}
      </div>
      <div
        :class="valueClass(item)"
        class="mt-1 break-all text-lg font-semibold"
      >
        {{ item.value }}
      </div>
    </div>
  </div>
</template>
