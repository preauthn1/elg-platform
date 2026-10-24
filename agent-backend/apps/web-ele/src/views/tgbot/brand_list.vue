<script lang="ts" setup>
import type { TgbotBrandRow } from '#/api';

import { computed, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import {
  deleteTgbotBrandApi,
  getTgbotBrandListApi,
  updateTgbotBrandStatusApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';

import TgbotBrandEditDialog from './components/tgbot-brand-edit-dialog.vue';

defineOptions({ name: 'TgbotBrandList' });

const editRef = ref<InstanceType<typeof TgbotBrandEditDialog>>();

const filters = reactive({ agent_username: '', bot_id: '', code: '' });
const loading = ref(false);
const rows = ref<TgbotBrandRow[]>([]);
const pageSize = ref(25);

const title = computed(
  () => `${tl('menu_tgbot_management')} > ${tl('menu_tgbot_brand_list')}`,
);

const {
  handlePageChange,
  handleSearch: handleQuickSearch,
  handleSortChange,
  keyword,
  page,
  pagedRows,
  total,
} = useClientTable(rows, {
  perPage: pageSize,
  searchFields: (r) => [
    r.name,
    r.code,
    r.bot_id,
    r.agent_username,
    r.updated_at,
  ],
});

function handleSizeChange(size: number) {
  pageSize.value = size;
  page.value = 1;
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getTgbotBrandListApi({ ...filters });
    rows.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
    page.value = 1;
  } finally {
    loading.value = false;
  }
}

async function toggleStatus(row: TgbotBrandRow) {
  const next = Number(row.status) ? 0 : 1;
  try {
    await ElMessageBox.confirm(
      next ? tl('confirm_enable') : tl('confirm_disable'),
    );
    const res = await updateTgbotBrandStatusApi({ id: row.id, status: next });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('operation_success'));
      loadData();
    } else {
      ElMessage.error(tl('operation_failed'));
    }
  } catch {
    /* cancelled */
  }
}

async function removeBrand(row: TgbotBrandRow) {
  try {
    await ElMessageBox.confirm(tl('confirm_delete'), { type: 'warning' });
    const res = await deleteTgbotBrandApi(row.id);
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('delete_success'));
      loadData();
    } else {
      ElMessage.error(res.message || tl('delete_failed'));
    }
  } catch {
    /* cancelled */
  }
}

function goEditor(query?: { copy_id?: number | string; id?: number | string }) {
  editRef.value?.open({ copyId: query?.copy_id, id: query?.id });
}

loadData();
</script>

<template>
  <Page :title="title">
    <div class="mb-3">
      <ElButton type="success" @click="goEditor()">{{ tl('add') }}</ElButton>
    </div>

    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="loadData">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.agent_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('telegram_bot_id')">
            <ElInput v-model="filters.bot_id" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('bot_code')">
            <ElInput v-model="filters.code" clearable />
          </ElFormItem>
        </div>
        <div class="flex justify-end">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-3">
        <ElInput
          v-model="keyword"
          :placeholder="tl('search')"
          class="!w-56"
          clearable
          @input="handleQuickSearch"
        />
      </div>
      <ElTable
        v-loading="loading"
        :data="pagedRows"
        border
        size="small"
        stripe
        @sort-change="handleSortChange"
      >
        <ElTableColumn
          :label="tl('bot_name')"
          prop="name"
          min-width="120"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('bot_code')"
          prop="code"
          min-width="180"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('telegram_bot_id')"
          prop="bot_id"
          min-width="140"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('agent_account')"
          prop="agent_username"
          min-width="120"
          sortable="custom"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('last_update_time')"
          prop="updated_at"
          min-width="160"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('total_users')"
          prop="count"
          align="right"
          min-width="100"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('status')"
          min-width="90"
          prop="status"
          sortable="custom"
        >
          <template #default="{ row }">
            <ElTag
              :type="Number(row.status) ? 'success' : 'warning'"
              size="small"
            >
              {{ Number(row.status) ? tl('enabled') : tl('disabled') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('action')" min-width="260" fixed="right">
          <template #default="{ row }">
            <div class="flex flex-wrap gap-1">
              <ElButton
                size="small"
                type="primary"
                @click="goEditor({ id: (row as TgbotBrandRow).id })"
              >
                {{ tl('edit') }}
              </ElButton>
              <ElButton
                size="small"
                type="info"
                @click="goEditor({ copy_id: (row as TgbotBrandRow).id })"
              >
                {{ tl('copy') }}
              </ElButton>
              <ElButton
                size="small"
                :type="Number(row.status) ? 'warning' : 'success'"
                @click="toggleStatus(row as TgbotBrandRow)"
              >
                {{ Number(row.status) ? tl('disable') : tl('enable') }}
              </ElButton>
              <ElButton
                size="small"
                type="danger"
                @click="removeBrand(row as TgbotBrandRow)"
              >
                {{ tl('delete') }}
              </ElButton>
            </div>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="pageSize"
        :total="total"
        :page-sizes="[10, 25, 50, 100]"
        layout="total, sizes, prev, pager, next, jumper"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </ElCard>

    <TgbotBrandEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
