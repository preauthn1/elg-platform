<script lang="ts" setup>
import type { ProfitSharePerformanceRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getProfitSharePerformanceApi,
  getTranslateApi,
  getVendorListApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands, toHalfWidth } from '#/utils/format';
import { nowInUserTz, toApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'ProfitSharePerformanceSearch' });

const PER_PAGE = 10;

/** 預設不勾選的平台 id（SR11_NO_PRESELECTION） */
const NO_PRESELECTION = new Set([4, 104, 181, 214, 223, 232]);

const translates = ref<Record<string, string>>({});
const vendors = ref<Array<{ id: number | string; name: string }>>([]);

function lab(id: string, fallback: string) {
  return translates.value[id] ?? fallback;
}

/** 特殊平台項 */
const specialOptions = computed(() => [
  { id: -1, label: lab('3009', 'Reward') },
  { id: -2, label: lab('3003', 'V-Reward') },
  { id: -3, label: lab('3006', 'Pay fees') },
  { id: -5, label: lab('3014', '3 layers of agents mode') },
  { id: -6, label: lab('3013', 'Rolling chip') },
]);

const filters = reactive({
  member_type: 2 as number | string,
  show_type: 1 as number | string,
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
  username: '',
  vendor_id: [] as Array<number | string>,
});

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<ProfitSharePerformanceRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      return getProfitSharePerformanceApi({
        agent_username: toHalfWidth(filters.username),
        end_time: toApiTime(`${end}:00`),
        member_type: filters.member_type,
        page,
        per_page,
        show_type: filters.show_type,
        start_time: toApiTime(`${start}:00`),
        vendor_id: filters.vendor_id,
      });
    },
    { perPage: PER_PAGE, showError: true },
  );

const fmt4 = (v?: null | number | string) => amountThousands(v, 4);

/** win_lose/cost：<0 紅、否則藍 */
function wlColor(v?: null | number | string) {
  return Number(v) < 0 ? 'red' : 'blue';
}

onMounted(async () => {
  getTranslateApi()
    .then((d) => {
      translates.value = d ?? {};
    })
    .catch(() => {});
  try {
    vendors.value = (await getVendorListApi()) ?? [];
  } catch {
    vendors.value = [];
  }
  // 預設全選（排除 NO_PRESELECTION 平台）+ 全部特殊項
  filters.vendor_id = [
    ...vendors.value
      .filter((v) => !NO_PRESELECTION.has(Number(v.id)))
      .map((v) => v.id),
    ...specialOptions.value.map((o) => o.id),
  ];
  search();
});
</script>

<template>
  <Page
    :title="`${tl('menu_profit_share_management')} > ${tl('menu_profit_share_performance_search')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('shareholder_account')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('platform')">
            <ElSelect
              v-model="filters.vendor_id"
              class="!w-full"
              collapse-tags
              collapse-tags-tooltip
              filterable
              multiple
            >
              <ElOption
                v-for="v in vendors"
                :key="v.id"
                :label="v.name"
                :value="v.id"
              />
              <ElOption
                v-for="o in specialOptions"
                :key="o.id"
                :label="o.label"
                :value="o.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('category')">
            <ElSelect v-model="filters.member_type" class="!w-full">
              <ElOption :label="tl('profit_share_member')" :value="2" />
              <ElOption :label="tl('shareholder_member')" :value="1" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
            <div class="text-muted-foreground mt-1 text-xs">
              {{ tl('query_period_max_days').replaceAll('{value}', '60') }}
            </div>
          </ElFormItem>
          <ElFormItem :label="tl('show')">
            <ElRadioGroup v-model="filters.show_type">
              <ElRadio :value="1">{{ tl('game_type') }}</ElRadio>
              <ElRadio :value="0">{{ tl('summary') }}</ElRadio>
            </ElRadioGroup>
          </ElFormItem>
        </div>
        <div class="flex items-center justify-end">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small">
        <ElTableColumn
          :label="tl('type')"
          prop="shareholder_category_name"
          min-width="120"
        />
        <ElTableColumn
          :label="tl('shareholder_account')"
          prop="agent_username"
          min-width="130"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('vendor')" min-width="120">
          <template #default="{ row }">
            <span class="font-bold text-blue-500">
              {{ (row as ProfitSharePerformanceRow).vendor }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('game_type')"
          prop="game_type_name"
          min-width="120"
        />
        <ElTableColumn :label="tl('total_bet')" align="right" min-width="130">
          <template #default="{ row }">
            <span
              v-if="Number((row as ProfitSharePerformanceRow).vendor_id) === 36"
            >
              --
            </span>
            <span v-else>{{
              fmt4((row as ProfitSharePerformanceRow).total_bet)
            }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('total_winlose')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">
            <span
              :style="{
                color: wlColor((row as ProfitSharePerformanceRow).win_lose),
              }"
            >
              {{ fmt4((row as ProfitSharePerformanceRow).win_lose) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('vendor_fee')" align="right" min-width="120">
          <template #default="{ row }">
            <span
              :style="{
                color: wlColor((row as ProfitSharePerformanceRow).cost),
              }"
            >
              {{ fmt4((row as ProfitSharePerformanceRow).cost) }}
            </span>
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
