<script lang="ts" setup>
import type { GameTypeManageRow } from '#/api';

import { ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getGameTypeManageListApi, toggleGameTypeStatusApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';

import GameTypeEditDialog from './components/game-type-edit-dialog.vue';

defineOptions({ name: 'GameTypeIndex' });

const PER_PAGE = 20;

const editRef = ref<InstanceType<typeof GameTypeEditDialog>>();
const loading = ref(false);
const allRows = ref<GameTypeManageRow[]>([]);

const {
  handlePageChange,
  handleSearch,
  handleSortChange,
  keyword,
  page,
  pagedRows,
  total,
} = useClientTable(allRows, {
  perPage: PER_PAGE,
  searchFields: (r) => [r.id, r.name],
});

async function loadData() {
  loading.value = true;
  try {
    allRows.value = (await getGameTypeManageListApi()) ?? [];
  } finally {
    loading.value = false;
  }
}

async function toggleStatus(row: GameTypeManageRow) {
  const next = Number(row.status) === 1 ? 0 : 1;
  try {
    await ElMessageBox.confirm(
      next === 1 ? tl('enable') : tl('disable'),
      tl('status'),
      { type: 'warning' },
    );
  } catch {
    return;
  }
  const res = await toggleGameTypeStatusApi(row.id, next);
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    loadData();
  } else {
    ElMessage.error(res.message);
  }
}

loadData();
</script>

<template>
  <Page
    :title="`${tl('menu_new_vendor')} > ${tl('menu_game_type_management')}`"
  >
    <ElCard shadow="never">
      <div class="mb-3 flex items-center justify-between gap-2">
        <ElButton type="success" @click="editRef?.open(0)">
          {{ tl('add') }}
        </ElButton>
        <ElInput
          v-model="keyword"
          :placeholder="tl('search')"
          class="!w-56"
          clearable
          @input="handleSearch"
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
          :label="tl('type_name')"
          min-width="180"
          prop="name"
          sortable="custom"
        >
          <template #default="{ row }">
            <div>{{ row.name }}</div>
            <div class="text-muted-foreground text-xs">
              {{ tl('id') }}{{ row.id }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('status')"
          min-width="100"
          prop="status"
          sortable="custom"
        >
          <template #default="{ row }">
            <span
              :class="
                Number(row.status) === 1 ? 'text-green-600' : 'text-red-500'
              "
              class="font-semibold"
            >
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="160" fixed="right">
          <template #default="{ row }">
            <ElButton
              size="small"
              type="warning"
              @click="editRef?.open((row as GameTypeManageRow).id)"
            >
              {{ tl('edit') }}
            </ElButton>
            <ElButton
              size="small"
              @click="toggleStatus(row as GameTypeManageRow)"
            >
              {{ Number(row.status) === 1 ? tl('disable') : tl('enable') }}
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
    </ElCard>

    <GameTypeEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
