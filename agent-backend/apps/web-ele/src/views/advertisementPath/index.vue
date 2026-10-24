<script lang="ts" setup>
import type { AdvertisementPathRow } from '#/api';

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
  ElRadio,
  ElRadioGroup,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getAdvertisementPathListApi,
  getAdvertisementPathViewApi,
  saveAdvertisementPathApi,
} from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';

defineOptions({ name: 'AdvertisementPathIndex' });

const PER_PAGE = 20;

const loading = ref(false);
const allRows = ref<AdvertisementPathRow[]>([]);

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
  searchFields: (r) => [r.name, r.column, r.width, r.height],
});

const title = computed(
  () => `${tl('system_management')} > ${tl('menu_advertisement_path')}`,
);

async function loadData() {
  loading.value = true;
  try {
    const res = await getAdvertisementPathListApi();
    allRows.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
  } finally {
    loading.value = false;
  }
}

// 新增/編輯彈窗
const dialogVisible = ref(false);
const saving = ref(false);
const form = reactive({
  column: '' as number | string,
  height: '' as number | string,
  id: '' as number | string,
  name: '',
  status: 1 as number | string,
  width: '' as number | string,
});

const dialogTitle = computed(
  () =>
    `${tl('menu_advertisement_path')} > ${form.id ? tl('edit') : tl('add')}`,
);

function openCreate() {
  form.id = '';
  form.name = '';
  form.column = '';
  form.width = '';
  form.height = '';
  form.status = 1;
  dialogVisible.value = true;
}

async function openEdit(row: AdvertisementPathRow) {
  form.id = row.id;
  form.name = row.name;
  form.column = row.column;
  form.width = row.width;
  form.height = row.height;
  form.status = row.status;
  dialogVisible.value = true;
  // 取最新詳細
  try {
    const data = await getAdvertisementPathViewApi(row.id);
    if (data && typeof data === 'object') {
      form.name = data.name ?? form.name;
      form.column = data.column ?? form.column;
      form.width = data.width ?? form.width;
      form.height = data.height ?? form.height;
      form.status = data.status ?? form.status;
    }
  } catch {
    // 用列資料即可
  }
}

async function submitForm() {
  saving.value = true;
  try {
    const res = await saveAdvertisementPathApi({
      column: form.column,
      height: form.height,
      id: form.id === '' ? undefined : form.id,
      name: form.name,
      status: form.status,
      width: form.width,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message ?? '');
      dialogVisible.value = false;
      loadData();
    } else {
      ElMessage.error(
        Number(res.code) === 2002
          ? String(res.data ?? '')
          : (res.message ?? ''),
      );
    }
  } finally {
    saving.value = false;
  }
}

loadData();
</script>

<template>
  <Page :title="title">
    <ElCard shadow="never">
      <div class="mb-3 flex items-center justify-between gap-2">
        <ElButton type="success" @click="openCreate">
          {{ tl('create_a_slot') }}
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
          :label="tl('slot_name')"
          prop="name"
          min-width="160"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('number_of_column')"
          prop="column"
          min-width="120"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('picture_width')"
          min-width="120"
          prop="width"
          sortable="custom"
        >
          <template #default="{ row }">{{ row.width }}px</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('picture_height')"
          min-width="120"
          prop="height"
          sortable="custom"
        >
          <template #default="{ row }">{{ row.height }}px</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('status')"
          min-width="100"
          prop="status"
          sortable="custom"
        >
          <template #default="{ row }">
            <span :class="Number(row.status) === 1 ? '' : 'text-red-500'">
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="" width="100" fixed="right">
          <template #default="{ row }">
            <ElButton
              size="small"
              @click="openEdit(row as AdvertisementPathRow)"
            >
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

    <ElDialog v-model="dialogVisible" :title="dialogTitle" width="640px">
      <ElForm label-position="top">
        <div class="grid grid-cols-2 gap-x-4">
          <ElFormItem :label="tl('slot_name')">
            <ElInput v-model="form.name" />
          </ElFormItem>
          <ElFormItem :label="tl('number_of_column')">
            <ElInput v-model="form.column" />
          </ElFormItem>
          <ElFormItem :label="tl('picture_width')">
            <ElInput v-model="form.width" placeholder="100px" />
          </ElFormItem>
          <ElFormItem :label="tl('picture_height')">
            <ElInput v-model="form.height" placeholder="100px" />
          </ElFormItem>
        </div>
        <ElFormItem :label="tl('status')">
          <ElRadioGroup v-model="form.status">
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="dialogVisible = false">{{ tl('btn_back') }}</ElButton>
        <ElButton :loading="saving" type="primary" @click="submitForm">
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
