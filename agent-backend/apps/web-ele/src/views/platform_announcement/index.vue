<script lang="ts" setup>
import type { PlatformAnnouncementRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

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
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getPlatformAnnouncementListApi,
  togglePlatformAnnouncementApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';

import PlatformAnnouncementEditDialog from './components/platform-announcement-edit-dialog.vue';

defineOptions({ name: 'PlatformAnnouncementIndex' });

const editRef = ref<InstanceType<typeof PlatformAnnouncementEditDialog>>();
const PER_PAGE = 20;

const filters = reactive({
  username: '',
  receiver_type: 0 as number | string,
  status: 1 as number | string,
});
const loading = ref(false);
const allRows = ref<PlatformAnnouncementRow[]>([]);

const {
  handlePageChange,
  handleSearch: handleQuickSearch,
  handleSortChange,
  keyword,
  page,
  pagedRows: pagedList,
  total,
} = useClientTable(allRows, {
  perPage: PER_PAGE,
  searchFields: (r) => [
    r.id,
    r.sort,
    r.username,
    r.title,
    r.start_time,
    r.end_time,
  ],
});

function tagLabel(tag: number | string): string {
  return tl(`platform_announcement_tag_${tag}`);
}

async function loadData() {
  loading.value = true;
  try {
    const data = await getPlatformAnnouncementListApi({
      receiver_type: filters.receiver_type,
      status: filters.status,
      username: filters.username.trim(),
    });
    allRows.value = data ?? [];
  } catch {
    allRows.value = [];
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadData();
}

function goEdit(id?: number | string) {
  editRef.value?.open(id ?? '');
}

async function onToggle(row: PlatformAnnouncementRow) {
  const next = Number(row.status) === 1 ? 0 : 1;
  const res = await togglePlatformAnnouncementApi(row.id, next).catch(
    () => null,
  );
  if (res && Number(res.code) === 1000) {
    ElMessage.success(res.message);
    loadData();
  } else {
    ElMessage.error(res?.message);
  }
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('system_management')} > ${tl('menu_platform_announcement')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="flex flex-wrap items-center gap-4">
          <ElFormItem :label="tl('agent_account')" class="!mb-0">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('sendMessage_target')" class="!mb-0">
            <ElSelect v-model="filters.receiver_type" class="!w-40">
              <ElOption :label="tl('all')" :value="0" />
              <ElOption :label="tl('agent')" :value="2" />
              <ElOption :label="tl('member')" :value="1" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('status')" class="!mb-0">
            <ElSelect v-model="filters.status" class="!w-40">
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('disable')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-3 flex items-center justify-between gap-2">
        <ElInput
          v-model="keyword"
          :placeholder="tl('search')"
          class="!w-56"
          clearable
          @input="handleQuickSearch"
        />
        <ElButton type="success" @click="goEdit()">
          {{ `${tl('add')}${tl('menu_platform_announcement')}` }}
        </ElButton>
      </div>
      <ElTable
        v-loading="loading"
        :data="pagedList"
        border
        size="small"
        stripe
        @sort-change="handleSortChange"
      >
        <ElTableColumn
          :label="tl('id')"
          min-width="90"
          prop="id"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('sorts')"
          min-width="90"
          prop="sort"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('agent')"
          min-width="120"
          prop="username"
          sortable="custom"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('default')}${tl('picture')}`"
          min-width="110"
        >
          <template #default="{ row }">
            <ElImage
              v-if="row.image_url"
              :preview-src-list="[row.image_url]"
              :src="row.image_url"
              class="h-12 w-16"
              fit="contain"
              preview-teleported
            />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('type')"
          min-width="110"
          prop="tag"
          sortable="custom"
        >
          <template #default="{ row }">{{ tagLabel(row.tag) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('sendMessage_target')"
          min-width="100"
          prop="receiver_type"
          sortable="custom"
        >
          <template #default="{ row }">
            {{ Number(row.receiver_type) === 2 ? tl('agent') : tl('member') }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('default')}${tl('platform_announcement_title')}`"
          min-width="200"
          prop="title"
          sortable="custom"
        >
          <template #default="{ row }">
            <div class="truncate">{{ row.title }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('label_start_time')"
          min-width="120"
          prop="start_time"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('label_end_time')"
          min-width="120"
          prop="end_time"
          sortable="custom"
        />
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
        <ElTableColumn
          :label="tl('operate')"
          align="center"
          width="170"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton size="small" type="primary" @click="goEdit(row.id)">
              {{ tl('edit') }}
            </ElButton>
            <ElButton
              size="small"
              @click="onToggle(row as PlatformAnnouncementRow)"
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

    <PlatformAnnouncementEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
