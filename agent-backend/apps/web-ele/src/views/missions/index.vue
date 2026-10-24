<script lang="ts" setup>
import type { MissionAvailableTypes, MissionRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
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
  getMissionAvailableTypesApi,
  getMissionsListApi,
  updateMissionStatusApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';

import MissionEditDialog from './components/mission-edit-dialog.vue';

defineOptions({ name: 'MissionsIndex' });

const editRef = ref<InstanceType<typeof MissionEditDialog>>();

const PAGE_SIZES = [10, 25, 50, 100];
const pageSize = ref(10);

const loading = ref(false);
// 後端 api/missions/list 不支援分頁/搜尋，一次撈全部，改前端分頁+搜尋
const allRows = ref<MissionRow[]>([]);
const types = reactive<MissionAvailableTypes>({});

const filters = reactive({
  action_type: -1 as number | string,
  status: -1 as number | string,
});

const {
  handlePageChange,
  handleSearch,
  keyword,
  page,
  pagedRows: list,
  total,
} = useClientTable(allRows, {
  perPage: pageSize,
  searchFields: (r) => [r.name, r.available_agents?.[0]?.username],
});

function handleSizeChange(size: number) {
  pageSize.value = size;
  page.value = 1;
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getMissionsListApi({
      action_type: filters.action_type,
      status: filters.status,
    });
    if (Number(res.code) === 1000) {
      allRows.value = res.data ?? [];
    } else {
      allRows.value = [];
      ElMessage.warning(res.message);
    }
  } finally {
    loading.value = false;
  }
}

/** key → 顯示名稱（找不到回退顯示 key） */
function typeName(map: Record<string, string> | undefined, key?: string) {
  return (key && map?.[key]) || key || '';
}

function goEdit(id?: number | string) {
  editRef.value?.open(id ?? '');
}

async function toggle(row: MissionRow) {
  const next = Number(row.status) === 1 ? 0 : 1;
  const res = await updateMissionStatusApi({
    mission_id: row.id,
    status: next,
  });
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    loadData();
  } else if (Number(res.code) === 2002) {
    ElMessage.error(String(res.data ?? res.message));
  } else {
    ElMessage.error(res.message);
  }
}

onMounted(async () => {
  try {
    const t = await getMissionAvailableTypesApi();
    if (Number(t.code) === 1000 && t.data) Object.assign(types, t.data);
  } catch {}
  loadData();
});
</script>

<template>
  <Page :title="tl('missions')">
    <ElCard class="mb-3" shadow="never">
      <div class="mb-3">
        <ElButton type="success" @click="goEdit()">
          {{ tl('mission_create') }}
        </ElButton>
      </div>
      <ElForm label-width="auto" @submit.prevent="loadData">
        <div class="flex flex-wrap items-center gap-4">
          <ElFormItem :label="tl('mission.action_type')" class="!mb-0">
            <ElSelect v-model="filters.action_type" class="!w-48">
              <ElOption :label="tl('all')" :value="-1" />
              <ElOption
                v-for="(name, key) in types.action_types"
                :key="key"
                :label="name"
                :value="key"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('status')" class="!mb-0">
            <ElSelect v-model="filters.status" class="!w-40">
              <ElOption :label="tl('all')" :value="-1" />
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('disable')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
          <div class="ml-auto">
            <ElInput
              v-model="keyword"
              :placeholder="tl('search')"
              class="!w-60"
              clearable
              @input="handleSearch"
            />
          </div>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('agent_name')" min-width="120">
          <template #default="{ row }">
            <b class="text-green-600">
              <AgentAccount :username="row.available_agents?.[0]?.username" />
            </b>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('mission.name')"
          min-width="140"
          prop="name"
        />
        <ElTableColumn :label="tl('mission.reset_type')" min-width="120">
          <template #default="{ row }">
            {{ typeName(types.reset_types, row.reset_type) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('mission.action_type')" min-width="120">
          <template #default="{ row }">
            {{ typeName(types.action_types, row.action_type) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('mission.start_time')" min-width="150">
          <template #default="{ row }">
            <span class="text-info">{{ row.start_time }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('mission.end_time')" min-width="150">
          <template #default="{ row }">
            <span class="text-info">{{ row.end_time }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('mission.status')" min-width="90">
          <template #default="{ row }">
            <span
              :class="
                Number(row.status) === 0 ? 'text-red-500' : 'text-green-600'
              "
            >
              {{ tl(`mission.status.${row.status}`) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('features')"
          align="center"
          width="150"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton size="small" type="primary" @click="goEdit(row.id)">
              {{ tl('edit') }}
            </ElButton>
            <ElButton size="small" @click="toggle(row as MissionRow)">
              {{ Number(row.status) === 1 ? tl('disable') : tl('enable') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="pageSize"
        :page-sizes="PAGE_SIZES"
        :total="total"
        layout="total, sizes, prev, pager, next, jumper"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </ElCard>

    <MissionEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
