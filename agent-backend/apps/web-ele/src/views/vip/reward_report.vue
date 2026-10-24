<script lang="ts" setup>
import type { VipRewardReportRow } from '#/api';

import { computed, reactive } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElForm,
  ElFormItem,
  ElInput,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getVipRewardReportApi } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import {
  fromApiTime,
  nowInUserTz,
  toApiTime as tzToApiTime,
} from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'VipRewardReport' });

const PER_PAGE = 20;

/** 獎項下拉：全部 + vip_reward_id_1~8 */
const REWARD_IDS = [1, 2, 3, 4, 5, 6, 7, 8];

const filters = reactive({
  end_time: nowInUserTz().add(16, 'hour').format('YYYY-MM-DD 23:59'),
  fuzzy_query: false,
  reward_id: '0' as number | string,
  start_time: nowInUserTz().subtract(8, 'hour').format('YYYY-MM-DD 00:00'),
  status: '-1' as number | string,
  time_type: '1' as number | string,
  username: '',
});

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<VipRewardReportRow>(
    ({ page, per_page }) =>
      getVipRewardReportApi({
        end_time: toApiTime(filters.end_time, true),
        fuzzy_query: filters.fuzzy_query ? 1 : 0,
        page,
        per_page,
        reward_id: filters.reward_id,
        start_time: toApiTime(filters.start_time, false),
        status: filters.status,
        time_type: filters.time_type,
        username: filters.username,
      }),
    { perPage: PER_PAGE, showError: true },
  );

/** 送 API 前換算成 UTC（basis 0）並補秒。端點（VipController@reward_report）
 * 輸入端直接比對 UTC 欄位（缺陷 A6），前端在此補償輸入側（原寫死 -8h）。 */
function toApiTime(v: string, end: boolean) {
  return tzToApiTime(v, 0).slice(0, 16) + (end ? ':59' : ':00');
}

function handleClear() {
  filters.username = '';
  filters.fuzzy_query = false;
  filters.status = '-1';
  filters.reward_id = '0';
  filters.time_type = '1';
  filters.start_time = nowInUserTz()
    .subtract(8, 'hour')
    .format('YYYY-MM-DD 00:00');
  filters.end_time = nowInUserTz().add(16, 'hour').format('YYYY-MM-DD 23:59');
}

const timeRange = computed<string[]>({
  get: () => [filters.start_time, filters.end_time],
  set: (v) => {
    filters.start_time = v?.[0] ?? '';
    filters.end_time = v?.[1] ?? '';
  },
});

function rewardTypeText(pointType?: number) {
  return Number(pointType) === 1 ? tl('platform_point') : tl('rolling_chip');
}
</script>

<template>
  <Page :title="`${tl('menu_vip')} > ${tl('menu_vip_reward_record')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('username')">
            <div>
              <ElInput v-model="filters.username" clearable />
              <ElCheckbox v-model="filters.fuzzy_query" class="mt-1">
                {{ tl('fuzzy_query') }}
              </ElCheckbox>
            </div>
          </ElFormItem>
          <ElFormItem :label="tl('awards')">
            <ElSelect v-model="filters.reward_id" class="w-full">
              <ElOption :label="tl('all')" value="0" />
              <ElOption
                v-for="rid in REWARD_IDS"
                :key="rid"
                :label="tl(`vip_reward_id_${rid}`)"
                :value="String(rid)"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="w-full">
              <ElOption :label="tl('all')" value="-1" />
              <ElOption :label="tl('success_received')" value="1" />
              <ElOption :label="tl('unaccalimed')" value="0" />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem class="md:col-span-2">
            <template #label>
              <ElSelect v-model="filters.time_type" style="width: 110px">
                <ElOption :label="tl('exchange_time')" value="1" />
                <ElOption :label="tl('created_time')" value="2" />
              </ElSelect>
            </template>
            <DateRangeFields
              v-model="timeRange"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
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
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :formatter="(row: VipRewardReportRow) => fromApiTime(row.created_at)"
          :label="tl('created_time')"
          align="center"
          width="170"
        />
        <ElTableColumn :label="tl('exchange_time')" align="center" width="170">
          <template #default="{ row }">
            {{ Number(row.status) === 1 ? fromApiTime(row.updated_at) : '---' }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('username')" align="center" min-width="120">
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`VIP${tl('name')}`"
          align="center"
          prop="vip_name"
          min-width="100"
        />
        <ElTableColumn
          :label="tl('reward_name')"
          align="center"
          min-width="120"
        >
          <template #default="{ row }">
            {{ tl(`vip_reward_id_${row.type}`) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('reward_type')"
          align="center"
          min-width="100"
        >
          <template #default="{ row }">
            {{ rewardTypeText(row.point_type) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('event_jackpot_column_10')"
          align="center"
          prop="point"
          min-width="100"
        />
        <ElTableColumn :label="tl('status')" align="center" width="120">
          <template #default="{ row }">
            {{
              Number(row.status) === 1
                ? tl('success_received')
                : tl('unaccalimed')
            }}
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
  </Page>
</template>
