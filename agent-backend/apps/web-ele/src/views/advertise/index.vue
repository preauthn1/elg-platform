<script lang="ts" setup>
import type { AdvertisementPathRow, AdvertiseRow } from '#/api';

import { computed, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElImage,
  ElInput,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getAdvertiseListApi, getAdvertisementPathListApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';

import AdvertiseEditDialog from './components/advertise-edit-dialog.vue';

defineOptions({ name: 'AdvertiseIndex' });

const editRef = ref<InstanceType<typeof AdvertiseEditDialog>>();

const PER_PAGE = 20;

const filters = reactive({
  path_id: [] as Array<number | string>,
  status: 1 as number | string,
  username: '',
});

const statusOptions = [
  { label: tl('enable'), value: 1 },
  { label: tl('disable'), value: 0 },
];

const loading = ref(false);
const allRows = ref<AdvertiseRow[]>([]);
const pathOptions = ref<AdvertisementPathRow[]>([]);

const {
  handlePageChange,
  handleSearch: handleQuickSearch,
  handleSortChange,
  keyword,
  page,
  pagedRows,
  total,
} = useClientTable(allRows, {
  perPage: PER_PAGE,
  searchFields: (r) => [
    r.id,
    r.advertisement_path_name,
    r.content,
    r.username,
    r.start_time,
    r.end_time,
  ],
});

const title = computed(
  () => `${tl('system_management')} > ${tl('menu_advertisement')}`,
);

/** 解析 type==4 的多語連結 JSON */
function parseLinks(content?: string): string[] {
  if (!content) return [];
  try {
    const obj = JSON.parse(content);
    return Object.values(obj)
      .map((v) => String(v).trim())
      .filter(Boolean);
  } catch {
    return [];
  }
}

/** 只取日期部分（yyyy-mm-dd） */
function dateOnly(v?: string) {
  return v ? v.slice(0, 10) : '';
}

async function loadPaths() {
  try {
    const res = await getAdvertisementPathListApi();
    // 只取啟用中(status=1)的版位當篩選選項
    pathOptions.value = (
      Number(res.code) === 1000 ? (res.data ?? []) : []
    ).filter((p) => Number(p.status) === 1);
  } catch {
    pathOptions.value = [];
  }
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getAdvertiseListApi({
      path_id: filters.path_id,
      status: filters.status,
      username: filters.username.trim(),
    });
    allRows.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadData();
}

loadPaths();
loadData();
</script>

<template>
  <Page :title="title">
    <div class="mb-3">
      <ElButton type="success" @click="editRef?.open()">
        {{ tl('add') }}
      </ElButton>
    </div>

    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('type')">
            <ElSelect
              v-model="filters.path_id"
              class="!w-full"
              clearable
              collapse-tags
              multiple
            >
              <ElOption
                v-for="p in pathOptions"
                :key="p.id"
                :label="p.name"
                :value="p.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="!w-40">
              <ElOption
                v-for="opt in statusOptions"
                :key="opt.value"
                :label="opt.label"
                :value="opt.value"
              />
            </ElSelect>
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
          :label="tl('id')"
          prop="id"
          sortable="custom"
          width="90"
        />
        <ElTableColumn
          :label="tl('layout_location')"
          prop="advertisement_path_name"
          min-width="120"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('hyperlink')"
          min-width="120"
          prop="link_type"
          sortable="custom"
        >
          <template #default="{ row }">
            {{ row.link_type ? tl(`advertisement_type_${row.link_type}`) : '' }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('content')"
          min-width="180"
          prop="content"
          sortable="custom"
        >
          <template #default="{ row }">
            <template v-if="Number(row.type) === 4">
              <div v-for="(lk, i) in parseLinks(row.content)" :key="i">
                <a :href="lk" class="text-sky-600" target="_blank">
                  {{ tl('hyperlink') }}
                </a>
              </div>
            </template>
            <span v-else>{{ row.content }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('label_start_time')"
          min-width="110"
          prop="start_time"
          sortable="custom"
        >
          <template #default="{ row }">
            <span class="text-sky-600 font-semibold">{{
              dateOnly(row.start_time)
            }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('label_end_time')"
          min-width="110"
          prop="end_time"
          sortable="custom"
        >
          <template #default="{ row }">
            <span class="text-sky-600 font-semibold">{{
              dateOnly(row.end_time)
            }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent')"
          prop="username"
          min-width="110"
          sortable="custom"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('picture')" min-width="120">
          <template #default="{ row }">
            <div class="flex flex-wrap gap-1">
              <ElImage
                v-for="(d, i) in row.detail ?? []"
                :key="i"
                :preview-src-list="[d.image_url]"
                :src="d.image_url"
                :title="d.name"
                fit="cover"
                preview-teleported
                style="width: 36px; height: 36px"
              />
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('status')"
          min-width="90"
          prop="status"
          sortable="custom"
        >
          <template #default="{ row }">
            <span :class="Number(row.status) === 1 ? '' : 'text-red-500'">
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('operate')" fixed="right" width="90">
          <template #default="{ row }">
            <ElButton
              size="small"
              @click="editRef?.open((row as AdvertiseRow).id)"
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

    <AdvertiseEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
