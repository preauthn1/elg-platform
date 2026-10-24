<script lang="ts" setup>
import type { EventImageTextRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  eventToggleStatusApi,
  getEventListApi,
  getEventV2ListApi,
  updateEventV2InfoApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';

import EventImageTextEditDialog from './components/event-image-text-edit-dialog.vue';

defineOptions({ name: 'EventImageText' });

const PER_PAGE = 10;

const editRef = ref<InstanceType<typeof EventImageTextEditDialog>>();

const filters = reactive({
  status: 'all' as number | string,
  title: '',
});

const loading = ref(false);
const allRows = ref<EventImageTextRow[]>([]);

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
    r.source,
    r.sort,
    r.agent_username,
    r.title,
    r.start_time,
    r.end_time,
  ],
});

async function loadData() {
  loading.value = true;
  try {
    const statusParam = filters.status === 'all' ? undefined : filters.status;
    const merged: EventImageTextRow[] = [];

    // V1
    const v1 = await getEventListApi({
      page: 1,
      per_page: 1000,
      status: statusParam,
      title: filters.title,
    });
    if (Number(v1.code) === 1000) {
      for (const e of v1.data ?? []) {
        merged.push({
          ...e,
          source: 'v1',
          start_time: e.detail?.[0]?.start_time,
          end_time: e.detail?.[0]?.end_time,
        });
      }
    }

    // V2
    const v2 = await getEventV2ListApi({
      name: filters.title,
      page: 1,
      per_page: 1000,
      status: statusParam,
    });
    if (Number(v2.code) === 200) {
      for (const e of v2.data?.items ?? []) {
        merged.push({
          ...e,
          source: 'v2',
          title: e.name,
          id: e.event_detail_id,
        });
      }
    }

    // 先 sort 升序(大到小)，相同再 created_at 降序(新到舊)
    merged.sort((a, b) => {
      if (Number(a.sort) !== Number(b.sort)) {
        return Number(b.sort) - Number(a.sort);
      }
      return (
        new Date(b.created_at ?? 0).getTime() -
        new Date(a.created_at ?? 0).getTime()
      );
    });

    allRows.value = merged;
    page.value = 1;
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadData();
}

function handleClear() {
  filters.title = '';
  filters.status = 'all';
}

async function toggleStatus(row: EventImageTextRow) {
  const newStatus = Number(row.status) === 1 ? 0 : 1;
  const res =
    row.source === 'v2'
      ? await updateEventV2InfoApi({
          event_detail_id: row.id,
          status: newStatus,
        })
      : await eventToggleStatusApi({ id: row.id, status: newStatus });
  if (Number(res.code) === 1000 || Number(res.code) === 200) {
    await loadData();
  } else {
    ElMessage.error(Number(res.code) === 2002 ? String(res.data) : res.message);
  }
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('event_exclusive_offers')} > ${tl('menu_event_image_text')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('event_name')">
            <ElInput v-model="filters.title" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="w-full">
              <ElOption :label="tl('all')" value="all" />
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('disable')" :value="0" />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
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
          align="center"
          prop="id"
          sortable="custom"
          width="100"
        />
        <ElTableColumn
          :label="tl('source')"
          align="center"
          prop="source"
          sortable="custom"
          width="100"
        >
          <template #default="{ row }">
            <b :class="row.source === 'v1' ? 'text-blue-500' : 'text-sky-500'">
              {{ String(row.source).toUpperCase() }}
            </b>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('sorts')"
          align="center"
          prop="sort"
          sortable="custom"
          width="100"
        />
        <ElTableColumn
          :label="tl('agent_name')"
          align="center"
          min-width="120"
          prop="agent_username"
          sortable="custom"
        >
          <template #default="{ row }">
            <b class="text-green-600">
              <AgentAccount :username="row.agent_username" />
            </b>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('event_name')"
          align="center"
          prop="title"
          min-width="180"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('status')"
          align="center"
          prop="status"
          sortable="custom"
          width="110"
        >
          <template #default="{ row }">
            <b
              :class="
                Number(row.status) === 1 ? 'text-green-600' : 'text-red-500'
              "
            >
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </b>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('label_start_time')"
          align="center"
          prop="start_time"
          sortable="custom"
          width="160"
        >
          <template #default="{ row }">{{ row.start_time ?? '-' }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('label_end_time')"
          align="center"
          prop="end_time"
          sortable="custom"
          width="160"
        >
          <template #default="{ row }">{{ row.end_time ?? '-' }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('features')"
          align="center"
          width="90"
          fixed="right"
        >
          <template #default="{ row }">
            <ElDropdown>
              <ElButton size="small">{{ tl('features') }}</ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem @click="editRef?.open(row.id, row.source)">
                    {{ tl('edit') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    @click="toggleStatus(row as EventImageTextRow)"
                  >
                    {{
                      Number(row.status) === 1 ? tl('disable') : tl('enable')
                    }}
                  </ElDropdownItem>
                </ElDropdownMenu>
              </template>
            </ElDropdown>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_data') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>

    <EventImageTextEditDialog ref="editRef" @saved="loadData" />
  </Page>
</template>
