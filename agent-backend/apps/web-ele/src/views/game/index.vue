<script lang="ts" setup>
import type { GameManageRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElImage,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElSwitch,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  bulkEditGamesApi,
  gameToggleStatusApi,
  getGameTypeManageListApi,
  getVendorDropdownByTypeApi,
  getVendorGameListApi,
  vendorNameWithId,
} from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

import GameEditDialog from './components/game-edit-dialog.vue';
import GameSortDialog from './components/game-sort-dialog.vue';

defineOptions({ name: 'GameIndex' });

const PER_PAGE = 50;

const userStore = useUserStore();
const editRef = ref<InstanceType<typeof GameEditDialog>>();
const sortRef = ref<InstanceType<typeof GameSortDialog>>();

/** bulk_edit 僅 host_id===1 超管顯示 */
const isHost = computed(
  () => Number((userStore.userInfo as any)?.host_id) === 1,
);

const filters = reactive({
  game_code: '',
  game_type: '' as number | string,
  name: '',
  status: '' as number | string,
  vendor_id: '' as number | string,
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<GameManageRow>(
    ({ page, per_page }) =>
      getVendorGameListApi({
        game_code: filters.game_code || undefined,
        game_type: filters.game_type || undefined,
        name: filters.name || undefined,
        page,
        per_page,
        status: filters.status === '' ? undefined : filters.status,
        vendor_id: filters.vendor_id || undefined,
      }),
    { perPage: PER_PAGE, showError: true },
  );

const vendors = ref<Array<{ id: number; name: string }>>([]);
const gameTypes = ref<Array<{ id: number | string; name?: string }>>([]);
const fileInput = ref<HTMLInputElement>();

function handleReset() {
  filters.vendor_id = '';
  filters.name = '';
  filters.game_code = '';
  filters.game_type = '';
  filters.status = '';
  search();
}

async function toggleStatus(row: GameManageRow) {
  const next = Number(row.status) === 1 ? 0 : 1;
  const res = await gameToggleStatusApi({ id: row.id, status: next });
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    row.status = next;
  } else {
    ElMessage.error(res.message || tl('failure'));
  }
}

function goEdit(id?: number | string) {
  editRef.value?.open(id ?? '');
}

function goSort() {
  sortRef.value?.open();
}

function onBulkFile() {
  fileInput.value?.click();
}

async function onBulkChange(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0];
  if (fileInput.value) fileInput.value.value = '';
  if (!file) return;
  const res = await bulkEditGamesApi(filters.vendor_id || 0, await file.text());
  if (Number(res.code) === 1000) {
    ElMessage.success(res.message || tl('success'));
    loadData();
  } else {
    ElMessage.error(res.message || tl('failure'));
  }
}

onMounted(async () => {
  [vendors.value, gameTypes.value] = await Promise.all([
    getVendorDropdownByTypeApi(1).then((v) => v ?? []),
    getGameTypeManageListApi().then((v) => v ?? []),
  ]);
  loadData();
});
</script>

<template>
  <Page :title="`${tl('vendor_management')} > ${tl('menu_game_management')}`">
    <ElCard class="mb-3" shadow="never">
      <div class="mb-3 flex justify-end gap-2">
        <ElButton type="primary" @click="goSort">{{ tl('sorts') }}</ElButton>
        <ElButton type="primary" @click="goEdit()">
          {{ tl('add_game') }}
        </ElButton>
      </div>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('label_vendor')">
            <ElSelect
              v-model="filters.vendor_id"
              class="w-full"
              clearable
              filterable
            >
              <ElOption
                v-for="v in vendors"
                :key="v.id"
                :label="vendorNameWithId({ id: v.id, name: v.name })"
                :value="v.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('game_name')">
            <ElInput v-model="filters.name" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('game_code')">
            <ElInput v-model="filters.game_code" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('game_type')">
            <ElSelect
              v-model="filters.game_type"
              class="w-full"
              clearable
              filterable
            >
              <ElOption
                v-for="t in gameTypes"
                :key="t.id"
                :label="t.name"
                :value="t.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="w-full" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('disable')" :value="0" />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
          <ElButton @click="handleReset">{{ tl('clear') }}</ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <div v-if="isHost" class="mb-3 flex justify-end">
        <ElButton type="success" @click="onBulkFile">
          {{ `${tl('bulk')}${tl('edit')}` }}
        </ElButton>
        <input
          ref="fileInput"
          accept=".json"
          class="hidden"
          type="file"
          @change="onBulkChange"
        />
      </div>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('vendor_name')"
          align="center"
          min-width="120"
          prop="vendor_name"
        />
        <ElTableColumn
          :label="tl('game_code')"
          align="center"
          min-width="120"
          prop="game_code"
        />
        <ElTableColumn
          :label="tl('game_type')"
          align="center"
          min-width="110"
          prop="game_type"
        />
        <ElTableColumn :label="tl('game_name')" align="center" min-width="180">
          <template #default="{ row }">
            <div class="font-bold">{{ row.game_name }}</div>
            <div class="text-xs text-gray-400">{{ tl('id') }} {{ row.id }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('memo')"
          align="center"
          min-width="120"
          prop="remark"
        />
        <ElTableColumn :label="tl('picture')" align="center" width="160">
          <template #default="{ row }">
            <ElImage
              v-if="row.image"
              :preview-src-list="[row.image]"
              :src="row.image"
              fit="contain"
              preview-teleported
              style="max-width: 160px; max-height: 80px"
            />
            <span v-else class="text-gray-400">{{ tl('not_uploaded') }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" align="center" width="90">
          <template #default="{ row }">
            <ElSwitch
              :model-value="Number(row.status) === 1"
              @change="toggleStatus(row as GameManageRow)"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('features')"
          align="center"
          width="90"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton size="small" @click="goEdit(row.id)">
              {{ tl('edit') }}
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

    <GameEditDialog ref="editRef" @success="loadData" />
    <GameSortDialog ref="sortRef" />
  </Page>
</template>
