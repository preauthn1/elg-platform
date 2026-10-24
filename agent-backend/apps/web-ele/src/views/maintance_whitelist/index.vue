<script lang="ts" setup>
import type { MaintenanceWhitelistRow } from '#/api';

import { computed, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
  ElSwitch,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  addMaintenanceWhitelistApi,
  deleteMaintenanceWhitelistApi,
  getHasMaintenanceApi,
  getMaintenanceWhitelistApi,
  toggleBackendMaintenanceApi,
} from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

defineOptions({ name: 'MaintenanceWhitelist' });

const PER_PAGE = 20;

const filters = reactive({ type: 0 as number | string, value: '' });
const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<MaintenanceWhitelistRow>(
    ({ page, per_page }) =>
      getMaintenanceWhitelistApi({
        page,
        per_page,
        type: filters.type,
        value: filters.value,
      }),
    { perPage: PER_PAGE, showError: true },
  );

const maintenanceOn = ref(false);

/**
 * 類型「全部」時後端會出錯（SystemMaintenanceController::list 在類型全部
 * 又搜到代理商帳號時 array_push 一個 Collection 進陣列導致 500，這是後端
 * bug 無法從前端修），改成類型全部時停用搜尋字串輸入，要先選類型才能搜
 */
function onTypeChange() {
  filters.value = '';
}

const typeOptions = [
  { label: tl('all'), value: 0 },
  { label: 'IP', value: 1 },
  { label: tl('agent_account'), value: 2 },
];

const title = computed(
  () => `${tl('menu_system_maintenance')} > ${tl('menu_switch_and_whitelist')}`,
);

async function loadMaintenanceStatus() {
  try {
    const data = await getHasMaintenanceApi();
    maintenanceOn.value = data === true;
  } catch {
    maintenanceOn.value = false;
  }
}

/** 切換維護開關（破壞性） */
async function handleToggle(next: boolean | number | string) {
  const status = next ? 1 : 0;
  try {
    const res = await toggleBackendMaintenanceApi(status);
    ElMessage({
      message: res.message ?? '',
      type: Number(res.code) === 1000 ? 'success' : 'error',
    });
    if (Number(res.code) !== 1000) {
      // 失敗復原開關
      maintenanceOn.value = !next;
    }
  } catch {
    maintenanceOn.value = !next;
  }
}

// 新增彈窗
const addVisible = ref(false);
const addForm = reactive({ type: 1 as number | string, value: '' });

function openAdd() {
  addForm.type = 1;
  addForm.value = '';
  addVisible.value = true;
}

async function submitAdd() {
  const res = await addMaintenanceWhitelistApi(addForm.type, addForm.value);
  if (Number(res.code) === 1000) {
    ElMessage.success(res.message ?? '');
    addVisible.value = false;
    search();
  } else {
    ElMessage.error(
      Number(res.code) === 2002 ? String(res.data ?? '') : (res.message ?? ''),
    );
  }
}

/** 刪除白名單 */
async function handleDelete(row: MaintenanceWhitelistRow) {
  try {
    await ElMessageBox.confirm(tl('delete'), { type: 'warning' });
  } catch {
    return;
  }
  const res = await deleteMaintenanceWhitelistApi(row.id);
  if (Number(res.code) === 1000) {
    ElMessage.success(res.message ?? '');
    loadData();
  } else {
    ElMessage.error(
      Number(res.code) === 2002 ? String(res.data ?? '') : (res.message ?? ''),
    );
  }
}

loadData();
loadMaintenanceStatus();
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <div class="flex items-center gap-3">
        <h3 class="text-lg font-semibold">{{ tl('maintenance_switch') }}:</h3>
        <ElSwitch v-model="maintenanceOn" @change="handleToggle" />
      </div>
    </ElCard>

    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="flex flex-wrap items-end gap-4">
          <ElFormItem :label="tl('type')" class="!mb-0">
            <ElSelect
              v-model="filters.type"
              class="!w-40"
              @change="onTypeChange"
            >
              <ElOption
                v-for="opt in typeOptions"
                :key="opt.value"
                :label="opt.label"
                :value="opt.value"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('whitelist')" class="!mb-0">
            <ElInput
              v-model="filters.value"
              :disabled="Number(filters.type) === 0"
              :placeholder="
                Number(filters.type) === 0 ? tl('please_select_type_first') : ''
              "
              clearable
            />
          </ElFormItem>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-3">
        <ElButton type="success" @click="openAdd">{{ tl('add') }}</ElButton>
      </div>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('num')" prop="id" width="100" />
        <ElTableColumn :label="tl('type')" min-width="120">
          <template #default="{ row }">
            <span :style="{ color: Number(row.type) === 1 ? 'blue' : 'green' }">
              {{ Number(row.type) === 1 ? 'IP' : tl('agent_account') }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('whitelist')" prop="value" min-width="200" />
        <ElTableColumn label="" width="120" fixed="right">
          <template #default="{ row }">
            <ElButton
              size="small"
              type="danger"
              @click="handleDelete(row as MaintenanceWhitelistRow)"
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
    </ElCard>

    <ElDialog
      v-model="addVisible"
      :title="`${tl('add')}${tl('whitelist')}`"
      width="500px"
    >
      <ElForm label-width="auto">
        <ElFormItem :label="tl('type')">
          <ElSelect v-model="addForm.type" class="w-full">
            <ElOption label="IP" :value="1" />
            <ElOption :label="tl('agent_account')" :value="2" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('whitelist')">
          <ElInput v-model="addForm.value" />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="addVisible = false">{{ tl('cancel') }}</ElButton>
        <ElButton type="success" @click="submitAdd">{{ tl('add') }}</ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
