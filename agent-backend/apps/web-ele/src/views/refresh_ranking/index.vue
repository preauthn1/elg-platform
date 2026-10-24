<script lang="ts" setup>
import type { RefreshRankingEvent, RefreshRankingPeriod } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getRefreshRankingEventListApi,
  getRefreshRankingListApi,
  updateRefreshRankingPointApi,
} from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

import RefreshRankingAddPeriodDialog from './components/refresh-ranking-add-period-dialog.vue';
import RefreshRankingEditDialog from './components/refresh-ranking-edit-dialog.vue';

defineOptions({ name: 'RefreshRankingIndex' });

const editRef = ref<InstanceType<typeof RefreshRankingEditDialog>>();
const addPeriodRef = ref<InstanceType<typeof RefreshRankingAddPeriodDialog>>();
const PER_PAGE = 50;

const events = ref<RefreshRankingEvent[]>([]);

const filters = reactive({
  event_id: '' as number | string,
});

/** 已查詢的活動 id（控制編輯/新增期數按鈕顯示與表格內容） */
const activeEventId = ref<number | string>('');

const searched = ref(false);

const { handlePageChange, list, loadData, loading, page, total } =
  useListQuery<RefreshRankingPeriod>(
    ({ page, per_page }) => {
      searched.value = true;
      return getRefreshRankingListApi({
        event_id: activeEventId.value,
        page,
        per_page,
      });
    },
    {
      guard: () => Boolean(activeEventId.value),
      perPage: PER_PAGE,
      showError: true,
    },
  );

function eventLabel(e: RefreshRankingEvent) {
  return `${e.agent_username} - ${e.title} - ${e.start_time} ~ ${e.end_time}`;
}

/** 當前時間（API 時區，now - 8h）；期數尚未開始才可編輯點數 */
function canEdit(period: RefreshRankingPeriod) {
  const nowMinus8 = dayjs().subtract(8, 'hour').format('YYYY-MM-DD HH:mm:ss');
  return Boolean(period.start_time) && nowMinus8 < String(period.start_time);
}

function handleSearch() {
  if (!filters.event_id) {
    ElMessage.warning(tl('event_name'));
    return;
  }
  activeEventId.value = filters.event_id;
  page.value = 1;
  loadData();
}

function goEdit() {
  editRef.value?.open(activeEventId.value);
}

function goAddPeriod() {
  addPeriodRef.value?.open(activeEventId.value);
}

/** 編輯期數獎勵點數 */
async function editPoint(row: RefreshRankingPeriod) {
  let value: string;
  try {
    const res = await ElMessageBox.prompt('', tl('before_point'), {
      cancelButtonText: tl('cancel'),
      confirmButtonText: tl('confirm'),
      inputType: 'number',
      inputValue: String(row.point ?? ''),
    });
    value = res.value;
  } catch {
    return;
  }
  const point = Math.abs(Number(value));
  const detailId = row.detail_id;
  if (detailId === null || detailId === undefined) return;
  const result = await updateRefreshRankingPointApi({
    id: detailId,
    point,
  });
  if (Number(result.code) === 1000) {
    ElMessage.success(tl('success'));
    loadData();
  } else {
    ElMessage.error(result.message);
  }
}

onMounted(() => {
  getRefreshRankingEventListApi()
    .then((d) => {
      events.value = d ?? [];
    })
    .catch(() => {});
});
</script>

<template>
  <Page
    :title="`${tl('menu_refresh_ranking')} > ${tl('menu_refresh_ranking_list')}`"
  >
    <div v-if="activeEventId" class="mb-3 flex gap-2">
      <ElButton type="success" @click="goEdit">
        {{ tl('edit') }}{{ tl('event') }}
      </ElButton>
      <ElButton type="primary" @click="goAddPeriod">
        {{ tl('add') }}{{ tl('period') }}
      </ElButton>
    </div>

    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <ElFormItem :label="tl('event_name')">
          <div class="flex w-full gap-2">
            <ElSelect v-model="filters.event_id" class="w-full" filterable>
              <ElOption
                v-for="e in events"
                :key="e.id"
                :label="eventLabel(e)"
                :value="e.id"
              />
            </ElSelect>
            <ElButton :loading="loading" native-type="submit" type="primary">
              {{ tl('search') }}
            </ElButton>
          </div>
        </ElFormItem>
      </ElForm>
    </ElCard>

    <ElCard v-if="searched" shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('period')" width="120">
          <template #default="{ row }">
            <span class="font-bold text-green-600">{{ row.no }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('label_start_time')"
          prop="start_time"
          width="180"
        />
        <ElTableColumn
          :label="tl('label_end_time')"
          prop="end_time"
          width="180"
        />
        <ElTableColumn :label="tl('before_point')" min-width="120">
          <template #default="{ row }">
            <span class="text-info font-bold">{{ row.point }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('features')"
          align="center"
          width="120"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton v-if="canEdit(row)" size="small" @click="editPoint(row)">
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

    <RefreshRankingEditDialog ref="editRef" @success="loadData" />
    <RefreshRankingAddPeriodDialog ref="addPeriodRef" @success="loadData" />
  </Page>
</template>
