<script lang="ts" setup>
import type { AdjustmentReportRow, AdjustmentType } from '#/api';

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

import { getAdjustmentReportApi, getAdjustmentTypeApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'ReportAdjustmentReport' });

const PER_PAGE = 20;

const adjustmentTypes = ref<AdjustmentType[]>([]);

const filters = reactive({
  adjustment_type: [] as number[],
  time_range: [
    // 昨日 00:00 ~ 今日 23:59
    nowInUserTz().subtract(24, 'hour').format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
  type: 1,
  username: '',
});

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<AdjustmentReportRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      // type/username 原樣送出，另附 generateSearchTextByType 的參數
      const params: Record<string, unknown> = {
        adjustment_type: filters.adjustment_type,
        end_time: toApiTime(end),
        page,
        per_page,
        start_time: toApiTime(start),
        type: filters.type,
        username: filters.username,
        [filters.type === 1 ? 'member_username' : 'agent_username']:
          filters.username,
      };
      return getAdjustmentReportApi(params);
    },
    {
      guard: () => {
        const [start, end] = filters.time_range ?? [];
        if (!start || !end) {
          ElMessage.warning(tl('between'));
          return false;
        }
        return true;
      },
      perPage: PER_PAGE,
      showError: true,
    },
  );

function handleClear() {
  filters.type = 1;
  filters.username = '';
  filters.adjustment_type = adjustmentTypes.value.map((t) => t.id);
  filters.time_range = [
    nowInUserTz().subtract(24, 'hour').format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ];
  page.value = 1;
  list.value = [];
  total.value = 0;
}

const exporting = ref(false);
/** 匯出 Excel：欄位（8 欄） */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return;
  }
  exporting.value = true;
  try {
    const params: Record<string, unknown> = {
      adjustment_type: filters.adjustment_type,
      end_time: toApiTime(end),
      page: 1,
      per_page: 100_000,
      start_time: toApiTime(start),
      type: filters.type,
      username: filters.username,
      [filters.type === 1 ? 'member_username' : 'agent_username']:
        filters.username,
    };
    const res = await getAdjustmentReportApi(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await exportExcel({
      audit: { conditions: params },
      columns: [
        { title: tl('member'), width: 20 },
        { title: tl('nickname'), width: 20 },
        { title: tl('agent'), width: 20 },
        { title: tl('point'), width: 20 },
        { title: tl('category'), width: 20 },
        { title: tl('memo'), width: 30 },
        { title: tl('operator'), width: 20 },
        { title: tl('createdate'), width: 30 },
      ],
      rows: rows.map((r) => [
        r.member_username,
        r.member_nickname,
        r.agent_username,
        r.point,
        r.type,
        r.remark,
        r.operator,
        fromApiTime(r.created_at),
      ]),
      title: tl('menu_adjustment_report'),
    });
  } finally {
    exporting.value = false;
  }
}

// 不自動查詢，僅先載入類別下拉（預設全選）
onMounted(() => {
  getAdjustmentTypeApi()
    .then((d) => {
      adjustmentTypes.value = d ?? [];
      filters.adjustment_type = adjustmentTypes.value.map((t) => t.id);
    })
    .catch(() => {});
});
</script>

<template>
  <Page
    :title="`${tl('member_lower_management')} > ${tl('menu_adjustment_report')}`"
  >
    <!-- 查詢 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem>
            <template #label>
              <ElSelect v-model="filters.type" class="!w-36">
                <ElOption :label="tl('member_name')" :value="1" />
                <ElOption :label="tl('agent_name')" :value="2" />
              </ElSelect>
            </template>
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('category')">
            <ElSelect v-model="filters.adjustment_type" class="w-full" multiple>
              <ElOption
                v-for="t in adjustmentTypes"
                :key="t.id"
                :label="t.name"
                :value="t.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <div class="flex gap-2">
            <ElButton :loading="exporting" type="success" @click="handleExport">
              {{ tl('excel') }}
            </ElButton>
            <ElButton :loading="loading" native-type="submit" type="primary">
              {{ tl('search') }}
            </ElButton>
            <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
          </div>
        </div>
      </ElForm>
    </ElCard>

    <!-- 列表 -->
    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('member')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('nickname')"
          prop="member_nickname"
          min-width="110"
        />
        <ElTableColumn
          :label="tl('agent')"
          prop="agent_username"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('point')" prop="point" min-width="100" />
        <ElTableColumn :label="tl('category')" prop="type" min-width="110" />
        <ElTableColumn :label="tl('memo')" prop="remark" min-width="150" />
        <ElTableColumn :label="tl('operator')" prop="operator" min-width="110">
          <template #default="{ row }">
            <AgentAccount :username="row.operator" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :formatter="(row: AdjustmentReportRow) => fromApiTime(row.created_at)"
          :label="tl('createdate')"
          width="160"
        />
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>
  </Page>
</template>
