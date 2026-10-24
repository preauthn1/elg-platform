<script lang="ts" setup>
import type { Sr19Row } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElInputNumber,
  ElMessage,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import {
  getCustomizedSettingApi,
  getReportSr19Api,
  getTranslateApi,
  getVendorReportDropdownApi,
  updateAgentProportionApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import CopyIcon from '#/components/copy-icon.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import {
  amountPlain,
  amountThousands,
  buildPlainAmounts,
  toHalfWidth,
} from '#/utils/format';
import { nowInUserTz, toApiTime } from '#/utils/timezone-shift';

import Sr19DetailDialog from './components/sr19-detail-dialog.vue';

defineOptions({ name: 'ReportSr19' });

const PER_PAGE = 20;

const detailRef = ref<InstanceType<typeof Sr19DetailDialog>>();

const vendors = ref<Array<{ id: number; name: string }>>([]);
const translates = ref<Record<string, string>>({});
const payFeeVisible = ref(false);
const smsFeeVisible = ref(false);
const costVisible = ref(false);

function lab(id: string, fallback: string) {
  return translates.value[id] ?? fallback;
}

/** 特殊平台選項（負值 id -1~-7） */
const specialVendorOptions = computed(() => {
  const opts = [
    { id: -1, label: lab('3002', 'V-World') },
    { id: -2, label: lab('3003', 'V-Reward') },
  ];
  if (payFeeVisible.value)
    opts.push({ id: -3, label: lab('3006', 'Pay fees') });
  if (smsFeeVisible.value) opts.push({ id: -4, label: lab('3007', 'SMS fee') });
  opts.push(
    { id: -5, label: lab('3014', '3 layers of agents mode') },
    { id: -6, label: lab('3013', 'Rolling chip') },
    { id: -7, label: lab('3017', 'Event cost') },
  );
  return opts;
});
const costLabel = computed(() => lab('3008', 'Cost'));

const filters = reactive({
  hidden_zero_data: 1,
  parent_username: '',
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
  username: '',
  vendor_id: [] as number[],
});

function selectAllVendors() {
  filters.vendor_id = [
    ...vendors.value.map((v) => v.id),
    ...specialVendorOptions.value.map((o) => o.id),
  ];
}
function clearVendors() {
  filters.vendor_id = [];
}

const searched = ref(false);
const curParent = ref('');

const { handlePageChange, list, loadData, loading, page, total } =
  useListQuery<Sr19Row>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      searched.value = true;
      curParent.value = filters.parent_username;
      return getReportSr19Api({
        cost_visible: costVisible.value ? 1 : 0,
        end_time: toApiTime(end ?? ''),
        hidden_zero_data: filters.hidden_zero_data,
        page,
        parent_username: filters.parent_username,
        per_page,
        start_time: toApiTime(start ?? ''),
        username: filters.parent_username ? '' : toHalfWidth(filters.username),
        vendor_id: filters.vendor_id.length > 0 ? filters.vendor_id : '',
      });
    },
    {
      guard: () => {
        if (!filters.username && !filters.parent_username) {
          ElMessage.warning(tl('required_column'));
          return false;
        }
        const [start, end] = filters.time_range ?? [];
        return Boolean(start && end);
      },
      perPage: PER_PAGE,
      showError: true,
    },
  );

const history = ref<Array<{ type: number; username: string }>>([]);
function pushHistory(type: number, username: string) {
  if (!username) return;
  if (!history.value.some((h) => h.type === type && h.username === username)) {
    history.value.push({ type, username });
  }
  history.value = history.value.slice(-5);
}

const fmt = (v?: null | number | string) => amountThousands(v, 2);
/** achievements = 4 位 floor */
const fmt4 = (v?: null | number | string) => amountThousands(v, 4);
/** cost = number_format 4 位（四捨五入） */
const fmtCost = (v?: null | number | string) => amountThousands(v, 4);

function handleSearch() {
  filters.parent_username = '';
  page.value = 1;
  pushHistory(1, filters.username);
  loadData();
}

function drilldown(username: string) {
  if (!username) return;
  filters.parent_username = username;
  page.value = 1;
  pushHistory(2, username);
  loadData();
}

function applyHistory(item: { type: number; username: string }) {
  if (item.type === 1) {
    filters.username = item.username;
    handleSearch();
  } else {
    drilldown(item.username);
  }
}

const exporting = ref(false);
/** 匯出 Excel：欄位（10/11 欄；含 cost 時 11 欄；vendor 36 顯示 --） */
async function handleExport() {
  if (!filters.username && !filters.parent_username) {
    ElMessage.warning(tl('required_column'));
    return;
  }
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) return;
  exporting.value = true;
  try {
    const params = {
      cost_visible: costVisible.value ? 1 : 0,
      end_time: toApiTime(end),
      hidden_zero_data: filters.hidden_zero_data,
      page: 1,
      parent_username: filters.parent_username,
      per_page: 100_000,
      start_time: toApiTime(start),
      username: filters.parent_username ? '' : toHalfWidth(filters.username),
      vendor_id: filters.vendor_id.length > 0 ? filters.vendor_id : '',
    };
    const res = await getReportSr19Api(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    const headers = [
      tl('agent_account'),
      tl('unlimit_parent_member'),
      tl('total_bet'),
      tl('total_winlose'),
      tl('commission'),
      tl('jackpot_event'),
    ];
    if (costVisible.value) headers.push(costLabel.value);
    headers.push(
      tl('v_jackpot'),
      tl('total_score'),
      tl('summary'),
      tl('proportion'),
    );
    await buildPlainAmounts(() =>
      exportExcel(
        `sr19_${Date.now()}`,
        headers,
        rows.map((r) => {
          const is36 = Number((r as Record<string, unknown>).vendor_id) === 36;
          const cells: Array<null | number | string | undefined> = [
            r.username,
            r.parent_username,
            is36 ? '--' : fmt(r.total_bet),
            is36 ? '--' : fmt(r.win_lose),
            fmt(r.commission),
            fmt(r.jackpot_event),
          ];
          if (costVisible.value) cells.push(amountPlain(r.cost, 4));
          cells.push(
            fmt(r.v_jackpot),
            fmt(r.total_score),
            fmt4(r.achievements),
            `${Number(r.proportion ?? 0) * 100}%`,
          );
          return cells;
        }),
        {
          audit: { conditions: params },
          dateRange: filters.time_range as [string, string],
          title: tl('menu_report_sr19'),
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}

function openDetail(row: Sr19Row) {
  const [start, end] = filters.time_range ?? [];
  detailRef.value?.open(row.agent_id ?? 0, start ?? '', end ?? '');
}

/* ---- 佔成設定彈窗 ---- */
const propVisible = ref(false);
const propSaving = ref(false);
const propRow = ref<null | Sr19Row>(null);
const propValue = ref(0);

function openProportion(row: Sr19Row) {
  propRow.value = row;
  propValue.value = Number(row.proportion ?? 0) * 100;
  propVisible.value = true;
}

async function saveProportion() {
  if (!propRow.value) return;
  propSaving.value = true;
  try {
    const res = await updateAgentProportionApi(
      propRow.value.agent_id ?? 0,
      propValue.value,
    );
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message);
      propRow.value.proportion = propValue.value / 100;
      propVisible.value = false;
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    propSaving.value = false;
  }
}

onMounted(() => {
  getVendorReportDropdownApi()
    .then((d) => {
      vendors.value = d ?? [];
    })
    .catch(() => {});
  getTranslateApi()
    .then((d) => {
      translates.value = d ?? {};
    })
    .catch(() => {});
  getCustomizedSettingApi()
    .then((settings) => {
      const map = new Map(
        (settings ?? []).map((s: any) => [Number(s.setting_key), s.value]),
      );
      payFeeVisible.value = Number(map.get(4053)) === 1;
      smsFeeVisible.value = Number(map.get(4054)) === 1;
      costVisible.value = Number(map.get(4055)) === 1;
    })
    .catch(() => {});
});
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('menu_report_sr19')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('agent_account')" required>
            <ElInput
              v-model="filters.username"
              :placeholder="tl('required_column')"
              clearable
            />
          </ElFormItem>
          <ElFormItem :label="tl('platform')">
            <div class="w-full">
              <ElSelect
                v-model="filters.vendor_id"
                class="w-full"
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
                  v-for="o in specialVendorOptions"
                  :key="o.id"
                  :label="o.label"
                  :value="o.id"
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
          <ElFormItem :label="tl('between')">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
          <ElFormItem :label="tl('bet_is_zero')">
            <ElRadioGroup v-model="filters.hidden_zero_data">
              <ElRadio :value="1">{{ tl('hidden') }}</ElRadio>
              <ElRadio :value="0">{{ tl('show') }}</ElRadio>
            </ElRadioGroup>
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
          </div>
        </div>
      </ElForm>
    </ElCard>

    <ElCard v-if="searched" shadow="never">
      <template #header>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span v-if="curParent" class="font-medium">
            {{ tl('unlimit_parent_member') }}: {{ curParent }}
          </span>
          <span v-else class="font-medium">{{ tl('detaillist') }}</span>
          <div
            v-if="history.length > 0"
            class="flex flex-wrap items-center gap-1"
          >
            <span class="text-muted-foreground text-xs"
              >{{ tl('search_history') }}:</span
            >
            <ElTag
              v-for="(h, i) in history"
              :key="i"
              class="cursor-pointer"
              @click="applyHistory(h)"
            >
              {{ h.username
              }}{{ h.type === 2 ? ` (${tl('check_lower')})` : '' }}
            </ElTag>
          </div>
        </div>
      </template>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('agent_account')" min-width="120">
          <template #default="{ row }">
            <span>
              <ElButton link type="primary" @click="drilldown(row.username)">
                {{ row.username }}
              </ElButton>
              <CopyIcon :text="row.username" />
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('unlimit_parent_member')"
          prop="parent_username"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.parent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('total_bet')" align="right" width="110">
          <template #default="{ row }">{{ fmt(row.total_bet) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('total_winlose')" align="right" width="110">
          <template #default="{ row }">
            <span :class="{ 'text-red-500': Number(row.win_lose) < 0 }">{{
              fmt(row.win_lose)
            }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('commission')" align="right" width="100">
          <template #default="{ row }">{{ fmt(row.commission) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('jackpot_event')" align="right" width="110">
          <template #default="{ row }">{{ fmt(row.jackpot_event) }}</template>
        </ElTableColumn>
        <ElTableColumn
          v-if="costVisible"
          :label="costLabel"
          align="right"
          width="110"
        >
          <template #default="{ row }">
            <span
              :class="Number(row.cost) < 0 ? 'text-red-500' : 'text-primary'"
            >
              {{ fmtCost(row.cost) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('v_jackpot')" align="right" width="100">
          <template #default="{ row }">{{ fmt(row.v_jackpot) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('total_score')" align="right" width="110">
          <template #default="{ row }">
            <span :class="{ 'text-red-500': Number(row.total_score) < 0 }">{{
              fmt(row.total_score)
            }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('summary')" align="right" width="110">
          <template #default="{ row }">
            <span :class="{ 'text-red-500': Number(row.achievements) < 0 }">{{
              fmt4(row.achievements)
            }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('proportion')" align="center" width="120">
          <template #default="{ row }">
            <div>{{ Number(row.proportion ?? 0) * 100 }}%</div>
            <ElButton size="small" @click="openProportion(row)">
              {{ tl('setting') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('operate')"
          align="center"
          width="90"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton size="small" type="primary" @click="openDetail(row)">
              {{ tl('detail') }}
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

    <!-- 佔成設定 -->
    <ElDialog
      v-model="propVisible"
      :title="tl('proportion')"
      width="420"
      align-center
    >
      <ElFormItem :label="tl('proportion')">
        <ElInputNumber v-model="propValue" :max="100" :min="0" :step="1" />
        <span class="ml-2">%</span>
      </ElFormItem>
      <template #footer>
        <ElButton @click="propVisible = false">{{ tl('btn_back') }}</ElButton>
        <ElButton :loading="propSaving" type="primary" @click="saveProportion">
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>

    <Sr19DetailDialog ref="detailRef" />
  </Page>
</template>
