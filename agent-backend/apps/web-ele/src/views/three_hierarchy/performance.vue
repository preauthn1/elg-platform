<script lang="ts" setup>
import type { ThreeHierarchyPerformanceRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElOption,
  ElRadioButton,
  ElRadioGroup,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getCustomizedSettingApi,
  getThreeHierarchyPerformanceApi,
  getVendorListApi,
} from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands, buildPlainAmounts } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import PerformanceDetailDialog from './components/performance-detail-dialog.vue';

defineOptions({ name: 'ThreeHierarchyPerformance' });

const PER_PAGE = 20;

const route = useRoute();
const detailRef = ref<InstanceType<typeof PerformanceDetailDialog>>();

const filters = reactive({
  agent_line: 0 as number | string,
  agent_username: '',
  time_range: [
    dayjs().format('YYYY-MM-DD'),
    dayjs().format('YYYY-MM-DD'),
  ] as string[],
  username: '',
  vendor_id: [] as number[],
});

/** customizedSetting 7001 == 3（三層代理-計算限定會員）才顯示輸分欄 */
const showLose = ref(false);

const vendors = ref<Array<{ id: number; name: string }>>([]);
const vendorLabel = (id?: number | string, name?: string) => `(${id}) ${name}`;
function selectAllVendors() {
  filters.vendor_id = vendors.value.map((v) => v.id);
}
function clearVendors() {
  filters.vendor_id = [];
}
/** 全選時不帶條件，結果跟沒篩選一樣 */
const vendorParam = computed(() =>
  filters.vendor_id.length === vendors.value.length
    ? ''
    : filters.vendor_id.join(','),
);

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<ThreeHierarchyPerformanceRow>(
    ({ page }) => {
      const [start, end] = filters.time_range ?? [];
      return getThreeHierarchyPerformanceApi({
        agent_line: filters.agent_line,
        agent_username: filters.agent_username,
        end_time: end ?? '',
        page,
        start_time: start ?? '',
        username: filters.username,
        vendor_id: vendorParam.value,
      });
    },
    {
      guard: () => {
        const [start, end] = filters.time_range ?? [];
        return Boolean(start && end);
      },
      perPage: PER_PAGE,
    },
  );

const fmt = (v?: number | string) => amountThousands(v, 2);

const isSummary = (row: ThreeHierarchyPerformanceRow) => row.username === '-';

const title = computed(
  () =>
    `${tl('menu_reward_mode_management')} > ${tl('menu_three_layers_agents_mode_including_agent')} > ${tl('menu_three_layers_performance')}`,
);

const exporting = ref(false);
/** 匯出 Excel：抓全部符合的列（showLose 時多一欄輸分） */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) return;
  exporting.value = true;
  try {
    const params = {
      agent_line: filters.agent_line,
      agent_username: filters.agent_username,
      end_time: end,
      page: 1,
      per_page: 100_000,
      start_time: start,
      username: filters.username,
      vendor_id: vendorParam.value,
    };
    const res = await getThreeHierarchyPerformanceApi(params);
    const data = Number(res.code) === 1000 ? (res.data ?? []) : [];
    const headers = showLose.value
      ? [
          tl('time'),
          tl('username'),
          tl('vendor_announcement'),
          tl('game_type'),
          tl('event_action.bet'),
          `${tl('lose_point')}${tl('amount')}`,
          tl('point'),
          tl('rolling_chip'),
        ]
      : [
          tl('time'),
          tl('username'),
          tl('vendor_announcement'),
          tl('game_type'),
          tl('event_action.bet'),
          tl('point'),
          tl('rolling_chip'),
        ];
    const rows = buildPlainAmounts(() =>
      data.map((r) =>
        showLose.value
          ? [
              r.summary_time,
              r.username,
              vendorLabel(r.vendor_id, r.vendor),
              r.game_type,
              fmt(r.bet),
              fmt(r.lose),
              fmt(r.point_reward),
              fmt(r.rolling_chip_reward),
            ]
          : [
              r.summary_time,
              r.username,
              vendorLabel(r.vendor_id, r.vendor),
              r.game_type,
              fmt(r.bet),
              fmt(r.point_reward),
              fmt(r.rolling_chip_reward),
            ],
      ),
    );
    await exportExcel(`performance_${Date.now()}`, headers, rows, {
      audit: { conditions: params },
      dateRange: filters.time_range as [string, string],
      title: tl('menu_three_layers_performance'),
      tzOffset: 8,
    });
  } finally {
    exporting.value = false;
  }
}

function openDetail(row: ThreeHierarchyPerformanceRow) {
  detailRef.value?.open({
    agent_username: filters.agent_username,
    game_type: row.game_type_id ?? '',
    summary_time: row.summary_time ?? '',
    vendor_id: row.vendor_id ?? '',
  });
}

onMounted(async () => {
  // 7001 == 3 顯示輸分欄
  try {
    const settings = await getCustomizedSettingApi();
    const s = (settings ?? []).find((x) => Number(x.setting_key) === 7001);
    showLose.value = Number(s?.value) === 3;
  } catch {
    showLose.value = false;
  }
  try {
    vendors.value = (await getVendorListApi()) ?? [];
  } catch {
    vendors.value = [];
  }
  selectAllVendors();
  // 從 search_hierarchy 點節點帶入查詢
  const q = route.query;
  if (q.agent_username || q.username) {
    filters.agent_username = String(q.agent_username ?? '');
    filters.username = String(q.username ?? '');
    if (q.start_time && q.end_time) {
      filters.time_range = [String(q.start_time), String(q.end_time)];
    }
    loadData();
  }
});
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.agent_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields v-model="filters.time_range" :fixed-offset="8" />
            <FixedTimezoneHint />
          </ElFormItem>
          <ElFormItem :label="`${tl('search')}${tl('agent_line')}`">
            <ElRadioGroup v-model="filters.agent_line">
              <ElRadioButton :value="1">{{ tl('option_yes') }}</ElRadioButton>
              <ElRadioButton :value="0">{{ tl('option_no') }}</ElRadioButton>
            </ElRadioGroup>
          </ElFormItem>
          <ElFormItem :label="tl('vendor_announcement')">
            <div class="w-full">
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
              </ElSelect>
              <div class="mt-1 flex gap-3">
                <ElButton
                  link
                  size="small"
                  type="primary"
                  @click="selectAllVendors"
                >
                  {{ tl('select_all') }}
                </ElButton>
                <ElButton link size="small" @click="clearVendors">
                  {{ tl('clear') }}
                </ElButton>
              </div>
            </div>
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('excel') }}
          </ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('time')"
          prop="summary_time"
          min-width="150"
        />
        <ElTableColumn :label="tl('username')" min-width="110">
          <template #default="{ row }">
            <ElButton
              v-if="isSummary(row as ThreeHierarchyPerformanceRow)"
              link
              type="primary"
              @click="openDetail(row as ThreeHierarchyPerformanceRow)"
            >
              {{ tl('detail') }}
            </ElButton>
            <MemberAccountLink v-else :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('vendor_announcement')" min-width="120">
          <template #default="{ row }">
            {{ vendorLabel(row.vendor_id, row.vendor) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('game_type')"
          prop="game_type"
          min-width="110"
        />
        <ElTableColumn
          :label="tl('event_action.bet')"
          align="right"
          min-width="110"
        >
          <template #default="{ row }">{{ fmt(row.bet) }}</template>
        </ElTableColumn>
        <ElTableColumn
          v-if="showLose"
          :label="`${tl('lose_point')}${tl('amount')}`"
          align="right"
          min-width="110"
        >
          <template #default="{ row }">{{ fmt(row.lose) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('point')" align="right" min-width="110">
          <template #default="{ row }">{{ fmt(row.point_reward) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('rolling_chip')"
          align="right"
          min-width="110"
        >
          <template #default="{ row }">
            {{ fmt(row.rolling_chip_reward) }}
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

    <PerformanceDetailDialog ref="detailRef" :show-lose="showLose" />
  </Page>
</template>
