<script lang="ts" setup>
import { tl } from '#/locales';

/**
 * 固定時區提示：掛在固定 GMT+8 結算頁面的日期欄位（快捷鈕）下方紅字，
 * 告知使用者本頁不隨時區設定變動。搭配 DateRangeFields 的 :fixed-offset="8" 使用。
 * 非 GMT+8 口徑的頁面（如 event_invite）改傳 text-key="fixed_tz_hint_no_shift"。
 */
defineOptions({ name: 'FixedTimezoneHint' });

withDefaults(defineProps<{ textKey?: string }>(), {
  textKey: 'fixed_gmt8_hint',
});
</script>

<template>
  <div class="mt-1 w-full text-xs leading-4 text-red-500">
    {{ tl(textKey) }}
  </div>
</template>
