<script lang="ts" setup>
import type { EventManageRow, EventManageSortField } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

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
  getEventActionsApi,
  getEventManageListApi,
  getPrizeTypeListApi,
  toggleEventStatusApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

import EventEditDialog from './components/event-edit-dialog.vue';

defineOptions({ name: 'EventIndex' });

const editRef = ref<InstanceType<typeof EventEditDialog>>();
const userStore = useUserStore();
/** 僅 Admin(id=1) 或其子帳號(host_id=1) 可建立/編輯活動 */
const canEdit = computed(() => {
  const u = userStore.userInfo as any;
  return Number(u?.id) === 1 || Number(u?.host_id) === 1;
});

const PER_PAGE = 20;

const filters = reactive({
  username: '',
  title: '',
  status: 1 as number | string,
});

/**
 * 表頭排序狀態（target/tickets#4320）：交給後端排序，欄位 prop 即 apis 的 sort_field。
 * 舊做法一次抓 1000 筆在前端排序，衍生欄位（滿足條件、起訖時間）因此沒有排序，
 * 且 VWorld 啟用中活動已逾 1000 筆被截斷。
 */
const sort = reactive<{
  direction: 'asc' | 'desc';
  field: '' | EventManageSortField;
}>({
  direction: 'asc',
  field: '',
});

/** 獎勵類型 id→name */
const prizeTypeMap = ref<Record<string, string>>({});
/** 條件類型 value→parameter */
const actionMap = ref<Record<string, string>>({});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<EventManageRow>(
    ({ page: p, per_page }) =>
      getEventManageListApi({
        page: p,
        per_page,
        title: filters.title,
        username: filters.username,
        ...(filters.status === '' ? {} : { status: Number(filters.status) }),
        ...(sort.field
          ? { sort_direction: sort.direction, sort_field: sort.field }
          : {}),
      }),
    { perPage: PER_PAGE },
  );

/** ElTable @sort-change（欄位需 sortable="custom"）：改排序回第 1 頁重查 */
function handleSortChange({
  order,
  prop,
}: {
  order: null | string;
  prop: null | string;
}) {
  sort.field = order ? ((prop ?? '') as '' | EventManageSortField) : '';
  sort.direction = order === 'descending' ? 'desc' : 'asc';
  search();
}

/** 條件達成顯示：每個 detail → 「<行為> over <point>」 */
function conditionText(row: EventManageRow): string[] {
  return (row.detail ?? []).map((d) => {
    const param = actionMap.value[String(d.action_id)];
    const label = param ? tl(`event_action.${param}`) : String(d.action_id);
    return `${label} over ${d.point}`;
  });
}

/** 起訖時間取最後一筆 detail */
function lastDetail(row: EventManageRow) {
  const d = row.detail ?? [];
  return d.length > 0 ? d[d.length - 1] : null;
}

/** prize_is_repeatedly：1=可重複領 / 2=限時 / 其他=不可 */
function repeatSymbol(v: number | string): { cls: string; txt: string } {
  if (Number(v) === 1) return { cls: 'text-green-600', txt: '✓' };
  if (Number(v) === 2) return { cls: 'text-blue-500', txt: '⧖' };
  return { cls: 'text-red-500', txt: '✗' };
}

function handleSearch() {
  search();
}

function handleClear() {
  filters.username = '';
  filters.title = '';
  filters.status = 1;
  handleSearch();
}

function goEdit(id?: number | string) {
  editRef.value?.open(id ?? '');
}

/** 複製：開「建立活動」彈窗並直接帶入這一列的既有設定（target/tickets#2983） */
function goCopy(id: number | string) {
  editRef.value?.open('', id);
}

async function onToggle(row: EventManageRow) {
  const next = Number(row.status) === 1 ? '0' : '1';
  const res = await toggleEventStatusApi(row.id, next).catch(() => null);
  // 啟停仍走舊 API_Agent（成功碼 1000）
  if (res && Number(res.code) === 1000) {
    ElMessage.success(res.message);
    loadData();
  } else {
    ElMessage.error(res?.message);
  }
}

onMounted(async () => {
  const [prizes, actions] = await Promise.all([
    getPrizeTypeListApi().catch(() => []),
    getEventActionsApi().catch(() => []),
  ]);
  const pm: Record<string, string> = {};
  for (const p of prizes ?? []) pm[String(p.id)] = p.name;
  prizeTypeMap.value = pm;
  const am: Record<string, string> = {};
  for (const a of actions ?? []) am[String(a.value)] = a.parameter;
  actionMap.value = am;
  loadData();
});
</script>

<template>
  <Page :title="`${tl('event_exclusive_offers')} > ${tl('event_management')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('agent_name')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('event_name')">
            <ElInput v-model="filters.title" clearable />
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
          <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-3 flex items-center justify-end gap-2">
        <ElButton v-if="canEdit" type="success" @click="goEdit()">
          {{ tl('event_create') }}
        </ElButton>
      </div>
      <ElTable
        v-loading="loading"
        :data="list"
        border
        size="small"
        stripe
        @sort-change="handleSortChange"
      >
        <ElTableColumn
          :label="tl('id')"
          min-width="70"
          prop="id"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('agent_name')"
          min-width="120"
          prop="agent_username"
          sortable="custom"
        >
          <template #default="{ row }">
            <span class="text-green-600 font-bold">
              <AgentAccount :username="row.agent_username" />
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('event_name')"
          min-width="140"
          prop="title"
          sortable="custom"
        />
        <!-- 衍生欄位由後端排序：滿足條件依行為＋門檻、起訖時間依 detail 彙總（target/tickets#4320） -->
        <ElTableColumn
          :label="tl('condition_satisfy')"
          min-width="200"
          prop="condition"
          sortable="custom"
        >
          <template #default="{ row }">
            <div
              v-for="(c, i) in conditionText(row as EventManageRow)"
              :key="i"
            >
              {{ c }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('label_start_time')"
          min-width="150"
          prop="start_time"
          sortable="custom"
        >
          <template #default="{ row }">
            {{ lastDetail(row as EventManageRow)?.start_time ?? '' }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('label_end_time')"
          min-width="150"
          prop="end_time"
          sortable="custom"
        >
          <template #default="{ row }">
            {{ lastDetail(row as EventManageRow)?.end_time ?? '' }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('event_reward')"
          min-width="120"
          prop="prize_type_id"
          sortable="custom"
        >
          <template #default="{ row }">
            {{ prizeTypeMap[String(row.prize_type_id)] ?? row.prize_type_id }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('available_multiple_get')"
          align="center"
          min-width="110"
          prop="prize_is_repeatedly"
          sortable="custom"
        >
          <template #default="{ row }">
            <span :class="repeatSymbol(row.prize_is_repeatedly).cls">
              {{ repeatSymbol(row.prize_is_repeatedly).txt }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('event_deadline')"
          min-width="120"
          prop="prize_expire_date"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('status')"
          min-width="90"
          prop="status"
          sortable="custom"
        >
          <template #default="{ row }">
            <span
              :class="
                Number(row.status) === 1 ? 'text-green-600' : 'text-red-500'
              "
            >
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('features')"
          align="center"
          width="250"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              v-if="canEdit"
              size="small"
              type="primary"
              @click="goEdit(row.id)"
            >
              {{ tl('edit') }}
            </ElButton>
            <ElButton
              :type="Number(row.status) === 1 ? 'danger' : 'success'"
              size="small"
              @click="onToggle(row as EventManageRow)"
            >
              {{ Number(row.status) === 1 ? tl('disable') : tl('enable') }}
            </ElButton>
            <ElButton v-if="canEdit" size="small" @click="goCopy(row.id)">
              {{ tl('copy') }}
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

    <EventEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
