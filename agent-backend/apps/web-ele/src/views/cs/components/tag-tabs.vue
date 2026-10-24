<script lang="ts" setup>
import type { CsRoom, CsSummaryTag } from '#/api/cs';
import type { CsTag } from '#/api/cs-tag';

import { computed } from 'vue';

import { sameCsTag } from '#/api/cs-tag';
import { tl } from '#/locales';

/**
 * 標籤頻道列（target/tickets#4169）：全部 + 每個啟用中標籤。#4204 起上移到頂端
 * 通欄（原本關在 300px 的列表面板裡，4 個標籤就橫向溢出），每顆標籤帶兩個
 * 數字：組別人數（深色徽章）＋未讀訊息數（白色徽章），數字由後端彙總端點出。
 */
const props = defineProps<{
  modelValue: '' | number | string;
  rooms: CsRoom[];
  /** 後端彙總的各標籤數字（target/tickets#4204）；未就緒前 undefined */
  summaryTags?: CsSummaryTag[];
  /** 啟用中的標籤 */
  tags: CsTag[];
}>();

const emit = defineEmits<{ 'update:modelValue': ['' | number | string] }>();

function roomHasTag(room: CsRoom, tag: CsTag) {
  return (room.cs_tags ?? []).some((t) => sameCsTag(t, tag));
}

function summaryOf(tag: CsTag): CsSummaryTag | undefined {
  return props.summaryTags?.find(
    (t) => String(t.id ?? '') === String(tag.id ?? tag.name ?? ''),
  );
}

/**
 * 各標籤未讀數。以後端彙總為準（固定統計進行中 status=1 的未讀「訊息」數，
 * 不隨使用者切到哪個分頁變動）；後端未就緒前退回舊算法——當前分頁已載入
 * 房間中 message_read !== 1 的「房間」數，至少不比改版前差。
 */
const unreadCount = computed(() => {
  const map = new Map<CsTag, number>();
  for (const tag of props.tags) {
    const backend = summaryOf(tag)?.unread_count;
    map.set(
      tag,
      backend === undefined
        ? props.rooms.filter(
            (r) => Number(r.message_read) !== 1 && roomHasTag(r, tag),
          ).length
        : Number(backend),
    );
  }
  return map;
});

/** 未讀類數字上限 99+，避免三位數撐破版面（與導覽、房間列同規則） */
function cap99(n: number | string | undefined): string {
  const v = Number(n ?? 0);
  return v > 99 ? '99+' : String(v);
}

function memberCount(tag: CsTag): number | undefined {
  const v = summaryOf(tag)?.member_count;
  return v === undefined ? undefined : Number(v);
}

function isActive(tag?: CsTag) {
  if (!tag) return props.modelValue === '';
  return String(props.modelValue) === String(tag.id ?? tag.name ?? '');
}

function select(tag?: CsTag) {
  emit('update:modelValue', tag ? (tag.id ?? tag.name ?? '') : '');
}
</script>

<template>
  <div
    v-if="tags.length > 0"
    class="flex flex-none gap-1.5 overflow-x-auto px-0.5 py-1"
  >
    <button
      :class="
        isActive()
          ? 'border-primary text-primary border-b-2 font-medium'
          : 'text-muted-foreground'
      "
      class="flex-none cursor-pointer px-1.5 py-0.5 text-sm"
      type="button"
      @click="select()"
    >
      {{ tl('all') }}
    </button>
    <button
      v-for="(tag, i) in tags"
      :key="tag.id ?? tag.name ?? i"
      :style="{ backgroundColor: tag.background_color, color: tag.text_color }"
      :class="{ 'ring-primary ring-2': isActive(tag) }"
      class="flex flex-none cursor-pointer items-center gap-1 rounded-full px-2 py-0.5 text-sm"
      type="button"
      @click="select(tag)"
    >
      {{ tag.name }}
      <!-- 組別人數一律顯示（含 0）並帶單位「人」（target/tickets#4204 驗收定案）；
           彙總端點未就緒（undefined）才整顆隱藏 -->
      <span
        v-if="memberCount(tag) !== undefined"
        :title="tl('cs.tag_member_count')"
        class="rounded-full bg-black/60 px-1.5 text-xs leading-4 text-white"
      >
        {{ memberCount(tag) }} {{ tl('cs.member_unit') }}
      </span>
      <span
        :title="tl('cs.unread_count')"
        class="rounded-full bg-white/80 px-1 text-xs leading-4 text-gray-700"
      >
        {{ cap99(unreadCount.get(tag)) }}
      </span>
    </button>
  </div>
</template>
