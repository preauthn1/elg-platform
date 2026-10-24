<script lang="ts" setup>
import type { GgrVendorSettingRow } from '#/api';

import { reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import { getGgrVendorSettingListApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';

import VendorSettingEditDialog from './components/vendor-setting-edit-dialog.vue';

defineOptions({ name: 'GgrVendorSetting' });

const PER_PAGE = 20;

const editRef = ref<InstanceType<typeof VendorSettingEditDialog>>();

const loading = ref(false);
const list = ref<GgrVendorSettingRow[]>([]);
const page = ref(1);
const total = ref(0);

const filters = reactive({
  name: '',
  status: '' as number | string,
});

async function loadData() {
  loading.value = true;
  try {
    const res = await getGgrVendorSettingListApi({
      page: page.value,
      per_page: PER_PAGE,
      ...(filters.name.trim() ? { name: filters.name.trim() } : {}),
      ...(filters.status === '' ? {} : { status: filters.status }),
    });
    const ok = Number(res.code) === 200;
    list.value = ok ? (res.data?.items ?? []) : [];
    total.value = ok ? (res.data?.pagination?.total ?? 0) : 0;
  } finally {
    loading.value = false;
  }
}

function search() {
  page.value = 1;
  loadData();
}

function clear() {
  filters.name = '';
  filters.status = '';
  search();
}

function handlePageChange(next: number) {
  page.value = next;
  loadData();
}

loadData();
</script>

<template>
  <Page
    :title="`${tl('menu_ggr_agents_mode')} > ${tl('menu_ggr_vendor_setting')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-4">
          <ElFormItem :label="tl('ggr_setting_name')">
            <ElInput v-model="filters.name" clearable @keyup.enter="search" />
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="!w-full">
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('disable')" :value="0" />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="clear">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" type="primary" @click="search">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <template #header>
        <div class="flex items-center justify-end">
          <ElButton type="success" @click="editRef?.open()">
            {{ tl('add') }}
          </ElButton>
        </div>
      </template>

      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('number')"
          align="center"
          prop="id"
          width="80"
        />
        <ElTableColumn
          :label="tl('ggr_setting_name')"
          align="center"
          min-width="160"
          prop="name"
          show-overflow-tooltip
        />
        <ElTableColumn :label="tl('status')" align="center" width="90">
          <template #default="{ row }">
            <ElTag :type="Number(row.status) === 1 ? 'success' : 'danger'">
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('updated_at')"
          align="center"
          prop="updated_at"
          width="160"
        />
        <ElTableColumn
          :label="tl('created_time')"
          align="center"
          prop="created_at"
          width="160"
        />
        <ElTableColumn
          :label="tl('ggr_created_by')"
          align="center"
          min-width="120"
        >
          <template #default="{ row }">
            {{ row.created_by || '-' }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('action')"
          align="center"
          width="150"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              size="small"
              @click="editRef?.open((row as GgrVendorSettingRow).id, 'copy')"
            >
              {{ tl('copy') }}
            </ElButton>
            <ElButton
              size="small"
              type="primary"
              @click="editRef?.open((row as GgrVendorSettingRow).id)"
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

    <VendorSettingEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
