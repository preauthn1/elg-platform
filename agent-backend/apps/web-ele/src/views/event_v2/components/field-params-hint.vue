<script lang="ts" setup>
import type { EventV2FieldParamEntry, EventV2FieldParamSet } from '#/api';

import { computed, ref } from 'vue';

import { ElButton, ElDialog, ElMessage, ElTag } from 'element-plus';

import { tl } from '#/locales';
import { copyText } from '#/utils/clipboard';

/**
 * 「設定條件」十格的標籤觸發器＋參數說明彈窗（target/tickets#4231 需求 7）。
 *
 * 清單來自後端 event-list 的 field_params（各 handler 自己宣告會讀哪些變數／key），
 * 這裡只依「目前選的活動類型」與「領取去向」挑該顯示的那一組，不寫死任何 key——
 * 後端新增參數只要補宣告與語系，這裡不用改。
 */
defineOptions({ name: 'EventV2FieldParamsHint' });

const props = defineProps<{
  /** event_v2_details 欄位名，對應 field_params 的 key */
  field: string;
  /** 已選活動類型才 true；否則彈窗提示先選類型 */
  hasCategory: boolean;
  /** 目前選的活動類型的 field_params；handler 對不到時後端給 null */
  params?: null | Record<string, EventV2FieldParamEntry>;
  /** 領取去向＝積分 */
  score: boolean;
}>();

const visible = ref(false);

/** 積分型在畫面上已鎖定清空的六格：彈窗照列主錢包型參數，另提醒留空 */
const SCORE_UNUSED_FIELDS = new Set([
  'balance_config',
  'balance_formula',
  'flow_config',
  'flow_formula',
  'reward_config',
  'reward_formula',
]);

const entry = computed(() => props.params?.[props.field]);
const usingScoreSet = computed(
  () => props.score && Boolean(entry.value?.score),
);
const set = computed<EventV2FieldParamSet | undefined>(() =>
  usingScoreSet.value ? entry.value?.score : entry.value?.main,
);
const isEmpty = computed(
  () => !set.value || set.value.kind === 'none' || set.value.items.length === 0,
);
const unusedForScore = computed(
  () => props.score && SCORE_UNUSED_FIELDS.has(props.field),
);

const KIND_LABEL: Record<string, string> = {
  arrays: 'event_v2_params_array',
  keys: 'event_v2_params_keys',
  variables: 'event_v2_params_variables',
};
const kindLabel = computed(() => KIND_LABEL[set.value?.kind ?? ''] ?? '');

async function copy(text: string) {
  if (await copyText(text)) {
    ElMessage.success(tl('copied'));
  } else {
    ElMessage.error(tl('copy_failed'));
  }
}
</script>

<template>
  <span
    class="hover:text-primary inline-flex cursor-pointer items-center gap-1"
    role="button"
    tabindex="0"
    @click="visible = true"
    @keydown.enter.prevent="visible = true"
  >
    <slot></slot>
    <span class="text-muted-foreground select-none">ⓘ</span>
  </span>

  <ElDialog
    v-model="visible"
    :title="tl('event_v2_params_title')"
    append-to-body
    destroy-on-close
    width="min(600px, 92vw)"
  >
    <p v-if="!hasCategory" class="text-muted-foreground">
      {{ tl('event_v2_params_select_type_first') }}
    </p>
    <p v-else-if="isEmpty" class="text-muted-foreground">
      {{ tl('event_v2_params_none') }}
    </p>
    <div v-else-if="set">
      <div class="text-muted-foreground mb-2 flex items-center gap-2 text-xs">
        <span>{{ tl(kindLabel) }}</span>
        <ElTag v-if="usingScoreSet" size="small">
          {{ tl('event_v2_params_score_mode') }}
        </ElTag>
      </div>
      <div
        v-for="item in set.items"
        :key="item.name"
        class="border-border mb-2 rounded border p-3"
      >
        <div class="flex flex-wrap items-center gap-2">
          <code class="font-mono text-sm font-medium">{{ item.name }}</code>
          <ElTag v-if="item.type" size="small" type="info">
            {{ item.type }}
          </ElTag>
        </div>
        <p v-if="item.description" class="mt-1 text-sm">
          {{ item.description }}
        </p>
        <div v-if="item.example" class="mt-2 flex items-start gap-2">
          <pre
            class="bg-muted flex-1 overflow-x-auto whitespace-pre-wrap break-all rounded px-2 py-1 font-mono text-xs"
            >{{ item.example }}</pre
          >
          <ElButton
            size="small"
            text
            type="primary"
            @click="copy(item.example)"
          >
            {{ tl('event_v2_params_copy') }}
          </ElButton>
        </div>
      </div>
    </div>
    <p
      v-if="hasCategory && unusedForScore"
      class="text-muted-foreground mt-3 text-xs"
    >
      {{ tl('event_v2_params_unused_for_score') }}
    </p>
  </ElDialog>
</template>
