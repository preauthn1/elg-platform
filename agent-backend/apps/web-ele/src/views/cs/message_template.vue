<script lang="ts" setup>
import type { CsMessageTemplate } from '#/api/cs';

import { onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElMessage,
  ElMessageBox,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  deleteCsMessageTemplateApi,
  getCsMessageTemplatesApi,
  runCsBootstrap,
} from '#/api/cs';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';
import { fromApiTime } from '#/utils/timezone-shift';

import MessageTemplateDialog from './components/message-template-dialog.vue';

/** 快速回復訊息管理（target/tickets#4485，補移植舊客服系統的管理頁） */
defineOptions({ name: 'CsMessageTemplate' });

const PER_PAGE = 25;

const loading = ref(false);
const bootstrapError = ref(false);
const allRows = ref<CsMessageTemplate[]>([]);
const dialogRef = ref<InstanceType<typeof MessageTemplateDialog>>();

// 排序沿用後端 created_at DESC，不另外提供搜尋框
const { handlePageChange, page, pagedRows, total } = useClientTable(allRows, {
  perPage: PER_PAGE,
});

/**
 * 客服後端 middleware 寫入前會對所有參數做 htmlentities，list 只把 content
 * decode 回來，title／memo 仍是 `&amp;` 這類實體。不還原的話畫面會顯示實體，
 * 編輯後儲存還會被再編碼一次。用 textarea（RCDATA）解碼：只還原實體、不解析標籤。
 */
function decodeHtmlEntities(value: string | undefined): string {
  if (!value) return '';
  const textarea = document.createElement('textarea');
  textarea.innerHTML = value;
  return textarea.value;
}

/** 不吞錯誤的原始版本，bootstrap() 才需要知道有沒有真的失敗（供重試判斷） */
async function fetchList() {
  const rows = (await getCsMessageTemplatesApi()) ?? [];
  allRows.value = rows.map((row) => ({
    ...row,
    memo: decodeHtmlEntities(row.memo),
    title: decodeHtmlEntities(row.title),
  }));
}

async function loadList() {
  loading.value = true;
  try {
    await fetchList();
  } catch {
    // 重載失敗時保留舊資料，避免清空成「暫無記錄」被誤判為資料消失（target/tickets#4485）
    ElMessage.error(tl('cs.load_failed'));
  } finally {
    loading.value = false;
  }
}

async function bootstrap() {
  loading.value = true;
  bootstrapError.value = false;
  try {
    await runCsBootstrap(fetchList);
  } catch {
    bootstrapError.value = true;
  } finally {
    loading.value = false;
  }
}
onMounted(bootstrap);

/** 刪除流程與 chat.vue delTemplate 一致 */
async function handleDelete(row: CsMessageTemplate) {
  if (row.id === null || row.id === undefined) return;
  try {
    await ElMessageBox.confirm(
      tl('cs.confirm_delete_template').replaceAll('{title}', row.title ?? ''),
      tl('cs.delete_template'),
      { type: 'warning' },
    );
  } catch {
    return;
  }
  try {
    await deleteCsMessageTemplateApi(row.id);
    ElMessage.success(tl('cs.deleted'));
    await loadList();
  } catch (error: any) {
    ElMessage.error(error?.message || tl('delete_failed'));
  }
}
</script>

<template>
  <Page :title="tl('menu.message_template')">
    <ElCard shadow="never">
      <div class="mb-3 flex items-center">
        <div class="flex-1"></div>
        <ElButton type="primary" @click="dialogRef?.open()">
          ＋ {{ tl('add') }}
        </ElButton>
      </div>

      <div v-if="bootstrapError" class="flex flex-col items-center gap-3 py-16">
        <span class="text-muted-foreground">{{ tl('cs.load_failed') }}</span>
        <ElButton type="primary" @click="bootstrap">
          {{ tl('retry') }}
        </ElButton>
      </div>
      <template v-else>
        <ElTable v-loading="loading" :data="pagedRows" border size="small">
          <ElTableColumn
            :label="tl('list.label_name')"
            min-width="160"
            prop="title"
          />
          <ElTableColumn :label="tl('list.message_content')" min-width="280">
            <template #default="{ row }">
              <div
                :title="row.content"
                class="line-clamp-2 whitespace-pre-line break-all"
              >
                {{ row.content }}
              </div>
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('memo')" min-width="140" prop="memo" />
          <ElTableColumn
            :label="tl('list.operator')"
            min-width="120"
            prop="username"
          />
          <!-- basis 0：客服後端 Eloquent timezone 為 UTC，時間字串是 UTC 牆鐘 -->
          <ElTableColumn :label="tl('list.create_time')" min-width="160">
            <template #default="{ row }">
              {{ fromApiTime(row.created_at, 0) }}
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('list.update_time')" min-width="160">
            <template #default="{ row }">
              {{ fromApiTime(row.updated_at, 0) }}
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('action')"
            align="center"
            fixed="right"
            width="120"
          >
            <template #default="{ row }">
              <ElButton
                link
                size="small"
                type="primary"
                @click="dialogRef?.open(row as CsMessageTemplate)"
              >
                {{ tl('edit') }}
              </ElButton>
              <ElButton
                link
                size="small"
                type="danger"
                @click="handleDelete(row as CsMessageTemplate)"
              >
                {{ tl('delete') }}
              </ElButton>
            </template>
          </ElTableColumn>
          <template #empty>{{ tl('no_record') }}</template>
        </ElTable>
        <TablePagination
          :current-page="page"
          :page-size="PER_PAGE"
          :total="total"
          @current-change="handlePageChange"
        />
      </template>
    </ElCard>

    <MessageTemplateDialog ref="dialogRef" @saved="loadList" />
  </Page>
</template>
