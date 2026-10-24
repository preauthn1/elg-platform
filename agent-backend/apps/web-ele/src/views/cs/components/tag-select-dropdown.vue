<script lang="ts" setup>
import type { CsTag } from '#/api/cs-tag';

import { computed, ref } from 'vue';

import { ElButton, ElMessage, ElPopover } from 'element-plus';

import { sameCsTag, saveCsMemberTagsApi } from '#/api/cs-tag';
import { tl } from '#/locales';

/**
 * 聊天室標頭的標籤多選（target/tickets#4169）：列出啟用中標籤，
 * 點一下貼上（pin）、再點同一顆取消（unpin）。
 */
const props = defineProps<{
  /** 啟用中的標籤（下拉候選） */
  allTags: CsTag[];
  memberId: number | string;
  /** 會員目前的客服標籤（roomInfo.cs_tags，與玩家標籤 member_tags 是兩套） */
  tags: Array<Record<string, any>>;
}>();

// 第二個參數是這次操作針對的 memberId：父層拿它比對當前房間，
// 避免請求在飛的期間切房、把結果套到別位會員身上
const emit = defineEmits<{
  'update:tags': [Array<Record<string, any>>, number | string];
}>();

const saving = ref(false);

/**
 * 候選＝啟用中標籤 ∪ 會員已貼但已停用的標籤——停用不能新貼，
 * 但已貼的要繼續顯示且可取消（不強制剝除）。
 */
const options = computed(() => {
  const extra = props.tags.filter(
    (t) => !props.allTags.some((a) => sameCsTag(a, t)),
  );
  return [...props.allTags, ...extra];
});

function isSelected(tag: Record<string, any>) {
  return props.tags.some((t) => sameCsTag(t, tag));
}

async function toggle(tag: Record<string, any>) {
  if (saving.value) return;
  // memberId 與 prev 在這裡定住：切房後 props 會換成別位會員的資料，
  // 事後的回滾必須帶著「當初是對誰操作」才不會蓋錯人
  const memberId = props.memberId;
  const prev = props.tags;
  const next = isSelected(tag)
    ? prev.filter((t) => !sameCsTag(t, tag))
    : [...prev, tag];
  const tagIds = next
    .map((t) => t.id)
    .filter((id): id is number | string => id !== undefined && id !== null);
  // 樂觀更新：先反映在 UI，失敗再還原
  emit('update:tags', next, memberId);
  saving.value = true;
  try {
    const res = await saveCsMemberTagsApi({
      member_id: memberId,
      tag_ids: tagIds,
    });
    if (Number(res.code) !== 1000) {
      emit('update:tags', prev, memberId);
      ElMessage.error(
        Number(res.code) === 2002
          ? String(res.data ?? res.message)
          : res.message,
      );
    }
  } catch {
    emit('update:tags', prev, memberId);
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <ElPopover placement="bottom-end" trigger="click" width="220">
    <template #reference>
      <ElButton plain size="small">{{ tl('tag') }} ▾</ElButton>
    </template>
    <div class="flex max-h-64 flex-col gap-1.5 overflow-y-auto">
      <button
        v-for="(tag, i) in options"
        :key="tag.id ?? tag.name ?? i"
        :style="{
          backgroundColor: tag.background_color,
          color: tag.text_color,
        }"
        :class="{ 'ring-primary ring-2': isSelected(tag) }"
        class="cursor-pointer rounded px-2 py-1 text-left text-sm"
        type="button"
        @click="toggle(tag)"
      >
        {{ tag.name }}
        <span v-if="isSelected(tag)" class="float-right">✓</span>
      </button>
      <span
        v-if="options.length === 0"
        class="text-muted-foreground px-1 py-2 text-center text-xs"
      >
        {{ tl('no_data') }}
      </span>
    </div>
  </ElPopover>
</template>
