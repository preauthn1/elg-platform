<script lang="ts" setup>
import type { CheckinRecordRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getCheckinRecordApi } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'CheckinRecord' });

const PER_PAGE = 50;

const filters = reactive({
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
  username: '',
});

const loading = ref(false);
const list = ref<CheckinRecordRow[]>([]);
const total = ref(0);
const page = ref(1);

/** prize_type 1=平台點數 / 2=洗碼量 */
function prizeTypeText(t?: number | string) {
  if (Number(t) === 1) return tl('platform_point');
  if (Number(t) === 2) return tl('rolling_chip');
  return String(t ?? '');
}
function prizeText(row: CheckinRecordRow) {
  return (row.prize ?? [])
    .map((p) => `${prizeTypeText(p.prize_type)} : ${p.prize_amount}`)
    .join('\n');
}

function buildParams(p: number, perPage: number) {
  const [start, end] = filters.time_range ?? [];
  return {
    end_date: toApiTime(end ?? ''),
    page: p,
    per_page: perPage,
    start_date: toApiTime(start ?? ''),
    username: filters.username.trim(),
  };
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getCheckinRecordApi(buildParams(page.value, PER_PAGE));
    if (Number(res.code) === 200) {
      list.value = res.data?.items ?? [];
      total.value = res.data?.pagination?.total ?? 0;
    } else {
      list.value = [];
      total.value = 0;
      ElMessage.warning(res.message);
    }
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadData();
}
function handlePage(p: number) {
  page.value = p;
  loadData();
}

const exporting = ref(false);
async function handleExport() {
  exporting.value = true;
  try {
    const params = buildParams(1, 5000);
    const res = await getCheckinRecordApi(params);
    const rows = Number(res.code) === 200 ? (res.data?.items ?? []) : [];
    await exportExcel(
      `checkin_record_${Date.now()}`,
      [
        tl('checkin_date'),
        tl('member_name'),
        `${tl('reward_type')} : ${tl('event_reward_count')}`,
      ],
      rows.map((r) => [r.checkin_date, r.username, prizeText(r)]),
      { audit: { conditions: params }, title: tl('menu_checkin_record') },
    );
  } finally {
    exporting.value = false;
  }
}

onMounted(loadData);
</script>

<template>
  <Page :title="`${tl('menu_checkin')} > ${tl('menu_checkin_record')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('member_name')">
            <ElInput v-model="filters.username" clearable />
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
        <div class="flex justify-end">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-2 flex justify-end">
        <ElButton :loading="exporting" type="success" @click="handleExport">
          {{ tl('excel') }}
        </ElButton>
      </div>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('checkin_date')"
          align="center"
          min-width="150"
          prop="checkin_date"
        />
        <ElTableColumn
          :label="tl('member_name')"
          align="center"
          min-width="120"
        >
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('reward_type')} : ${tl('event_reward_count')}`"
          min-width="180"
        >
          <template #default="{ row }">
            <div v-for="(p, i) in row.prize ?? []" :key="i">
              {{ prizeTypeText(p.prize_type) }} : {{ p.prize_amount }}
            </div>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_data') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePage"
      />
    </ElCard>
  </Page>
</template>
