<script lang="ts">
/**
 * 全頁同時只開一個彈窗（需求文件「同時僅開一個彈窗」）：所有實例共用「目前開著的那個
 * 的關閉函式」，打開一個就先關掉前一個。
 *
 * 🔴 放在一般 `<script>` 而不是 `<script setup>`：後者的頂層變數是每個實例各一份，
 * 無法共用。不能只靠 ElPopover 的「點外面關閉」——那取決於點擊事件有沒有被攔下，
 * 不可靠。包成物件是因為 lint 分開分析兩個 script 區塊，看不到 setup 裡的重新賦值。
 */
const openedPopover: { hide: (() => void) | null } = { hide: null };
</script>

<script lang="ts" setup>
import { computed, ref } from 'vue';

import { CircleHelp } from '@vben/icons';

import { useEventListener } from '@vueuse/core';
import { ElPopover } from 'element-plus';

import { tl } from '#/locales';

defineOptions({ name: 'CategoryVendorHint' });

/**
 * 分桶輸贏報表「遊戲平台組」旁的問號（target/tickets#4427）：點開列出該分類底下的遊戲商。
 *
 * - `vendors` 由父層整理好：只含「類別管理啟用且本站啟用」的遊戲商，依類別管理的設定
 *   順序、同組重複只留一次。本元件只負責呈現。
 * - 尺寸固定約 340 × 150px（需求文件）：遊戲商固定三列、依欄往右排，超出寬度就在框內
 *   左右滑動，不擴大視窗。一格一家，名稱之間靠欄位分隔，名稱本身不斷行。
 * - 沒有任何可顯示的遊戲商（未設定或全停用）時照樣顯示問號，點開顯示「尚未設定遊戲商」。
 *   例外是 Settlement 列，由父層直接不掛本元件，見 performance-query-page 的 showVendorHint()。
 * - 點窗外或按 Esc 關閉。Element Plus 2.14 的 ElPopover 沒有內建 Esc 關閉，這裡自己補。
 */
const props = defineProps<{
  categoryName?: string;
  vendors: string[];
}>();

/**
 * 開關交給 ElPopover 自己管（trigger="click"），「只開一個」與 Esc 只是在旁邊補上：
 * 問號被點時先關掉上一個登記的彈窗、再把自己登記上去；Esc 只關目前登記的那一個。
 * 對已經關閉的彈窗呼叫 hide() 不會有作用，所以使用者點窗外關掉後，登記沒清也無妨。
 *
 * 驗證時注意：hide() 之後要約 1 秒（hideAfter 加淡出動畫）元素才會變回 display:none，
 * 太早檢查會誤以為兩個同時開著。
 */
const popoverRef = ref<{ hide: () => void }>();

function hide() {
  popoverRef.value?.hide();
}

function onTriggerClick() {
  if (openedPopover.hide && openedPopover.hide !== hide) openedPopover.hide();
  openedPopover.hide = hide;
}

useEventListener(document, 'keydown', (e: KeyboardEvent) => {
  if (e.key === 'Escape' && openedPopover.hide === hide) hide();
});

const title = computed(() =>
  tl('category_vendors_title').replace('{value}', props.categoryName ?? ''),
);
const count = computed(() =>
  tl('category_vendors_count').replace('{value}', String(props.vendors.length)),
);
</script>

<template>
  <ElPopover ref="popoverRef" placement="right" trigger="click" :width="340">
    <template #reference>
      <CircleHelp
        class="ml-1 inline-block size-4 cursor-pointer align-text-bottom text-gray-400 hover:text-indigo-500"
        @click="onTriggerClick"
      />
    </template>
    <div class="mb-2 flex items-center justify-between gap-2 text-sm">
      <span class="truncate font-medium">{{ title }}</span>
      <span class="shrink-0 text-gray-400">{{ count }}</span>
    </div>
    <div
      v-if="vendors.length > 0"
      class="grid h-24 auto-cols-max grid-flow-col grid-rows-3 gap-x-5 overflow-x-auto overflow-y-hidden pb-1 text-sm"
    >
      <span v-for="v in vendors" :key="v" class="whitespace-nowrap">{{
        v
      }}</span>
    </div>
    <div
      v-else
      class="flex h-24 items-center justify-center text-sm text-gray-400"
    >
      {{ tl('category_vendors_empty') }}
    </div>
  </ElPopover>
</template>
