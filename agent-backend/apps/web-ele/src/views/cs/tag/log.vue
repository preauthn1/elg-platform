<script lang="ts" setup>
import type { CsTagLogRow } from '#/api/cs-tag';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { usePreferences } from '@vben/preferences';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElDatePicker,
  ElEmpty,
  ElForm,
  ElFormItem,
  ElInput,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { runCsBootstrap } from '#/api/cs';
import { CS_TAG_LOG_LIMIT, getCsTagLogListApi } from '#/api/cs-tag';
import AgentAccount from '#/components/agent-account.vue';
import { tl } from '#/locales';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'CsTagLog' });

const { isMobile } = usePreferences();

const form = reactive({
  action: '' as '' | 'pin' | 'unpin',
  // 結束時間預設多推一分鐘：時間只到「分」，查詢送出時會補 :00，
  // 不推的話「剛 pin 完馬上開紀錄頁」會查不到當下這一分鐘內的異動
  //
  // 型別是 string | null：ElDatePicker 的 clearable 預設是開的，使用者按 ×
  // 清空時 v-model 會寫進 null，直接字串內插會送出 "null:00"（後端 strtotime
  // 解不出來 → 1970-01-01 → 靜默回 0 筆），所以下面送出前要判空。
  end: dayjs().add(1, 'minute').format('YYYY-MM-DD HH:mm') as null | string,
  start: dayjs().subtract(1, 'day').format('YYYY-MM-DD HH:mm') as null | string,
  username: '',
});

const loading = ref(false);
const bootstrapError = ref(false);
const rows = ref<CsTagLogRow[]>([]);
const keyword = ref('');

// label 在渲染時以 tl() 解析（避免模組載入時語系字典尚未就緒被凍結）
const ACTION_KEY: Record<string, string> = {
  pin: 'cs.tag_pin',
  unpin: 'cs.tag_unpin',
};
function actionText(a?: string) {
  const k = ACTION_KEY[a ?? ''];
  return k ? tl(k) : (a ?? '');
}

function matchKeyword(row: Record<string, any>): boolean {
  const kw = keyword.value.trim().toLowerCase();
  if (!kw) return true;
  return [
    row.username,
    row.tag_name,
    actionText(row.action),
    row.operator,
    row.created_at,
  ]
    .map((v) => String(v ?? '').toLowerCase())
    .some((v) => v.includes(kw));
}
const filtered = computed(() => rows.value.filter((r) => matchKeyword(r)));
/** 回傳筆數頂到後端上限＝可能還有更舊的沒撈到，明講而不是默默截斷 */
const truncated = computed(() => rows.value.length >= CS_TAG_LOG_LIMIT);

/** 時間欄位空著就不帶該邊界（後端 when() 會跳過），不要送出 "null:00" */
function boundary(value: null | string) {
  return value ? `${value}:00` : undefined;
}

/** 不吞錯誤的原始版本，bootstrap() 才需要知道有沒有真的失敗（供重試判斷） */
async function fetchSearch() {
  rows.value = await getCsTagLogListApi({
    action: form.action || undefined,
    end_time: boundary(form.end),
    start_time: boundary(form.start),
    username: form.username,
  });
}
async function doSearch() {
  loading.value = true;
  try {
    await fetchSearch();
  } catch {
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

async function bootstrap() {
  loading.value = true;
  bootstrapError.value = false;
  try {
    await runCsBootstrap(fetchSearch);
  } catch {
    bootstrapError.value = true;
  } finally {
    loading.value = false;
  }
}
onMounted(bootstrap);
</script>

<template>
  <Page :title="tl('menu.cs_tag_log')">
    <ElCard shadow="never" class="mb-4">
      <ElForm :inline="!isMobile" @submit.prevent="doSearch">
        <ElFormItem :label="tl('form.username')">
          <ElInput
            v-model="form.username"
            clearable
            :placeholder="tl('member_account')"
          />
        </ElFormItem>
        <ElFormItem :label="tl('status')">
          <ElSelect v-model="form.action" style="width: 140px">
            <ElOption value="" :label="tl('all')" />
            <ElOption value="pin" :label="tl('cs.tag_pin')" />
            <ElOption value="unpin" :label="tl('cs.tag_unpin')" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('cs.start')">
          <ElDatePicker
            v-model="form.start"
            type="datetime"
            format="YYYY-MM-DD HH:mm"
            value-format="YYYY-MM-DD HH:mm"
          />
        </ElFormItem>
        <ElFormItem :label="tl('cs.end')">
          <ElDatePicker
            v-model="form.end"
            type="datetime"
            format="YYYY-MM-DD HH:mm"
            value-format="YYYY-MM-DD HH:mm"
          />
        </ElFormItem>
        <ElFormItem>
          <ElButton type="primary" :loading="loading" native-type="submit">
            {{ tl('search') }}
          </ElButton>
        </ElFormItem>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-3 flex items-center">
        <h3 class="m-0 text-base font-medium">{{ tl('menu.cs_tag_log') }}</h3>
        <div class="flex-1"></div>
        <ElInput
          v-model="keyword"
          :placeholder="tl('cs.search_in_table')"
          clearable
          style="max-width: 240px"
        />
      </div>

      <div
        v-if="truncated"
        class="mb-2 rounded border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs text-amber-700"
      >
        {{ tl('cs.tag_log_truncated') }}
      </div>

      <div v-if="bootstrapError" class="flex flex-col items-center gap-3 py-16">
        <span class="text-muted-foreground">{{ tl('cs.load_failed') }}</span>
        <ElButton type="primary" @click="bootstrap">
          {{ tl('retry') }}
        </ElButton>
      </div>
      <ElTable
        v-else
        v-loading="loading"
        :data="filtered"
        border
        max-height="560"
        size="small"
      >
        <ElTableColumn :label="tl('form.username')">
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('tag')" min-width="140">
          <template #default="{ row }">
            <span
              v-if="row.background_color"
              :style="{
                backgroundColor: row.background_color,
                color: row.text_color,
              }"
              class="rounded px-1.5 py-0.5"
            >
              {{ row.tag_name }}
            </span>
            <span v-else>{{ row.tag_name }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('cs.tag_action')" width="140">
          <template #default="{ row }">{{ actionText(row.action) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('cs.tag_action_time')"
          prop="created_at"
          sortable
          width="180"
        />
        <ElTableColumn
          :label="tl('cs.tag_operator')"
          min-width="140"
          prop="operator"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.operator" />
          </template>
        </ElTableColumn>
        <template #empty>
          <ElEmpty :description="tl('no_data')" />
        </template>
      </ElTable>
    </ElCard>
  </Page>
</template>
