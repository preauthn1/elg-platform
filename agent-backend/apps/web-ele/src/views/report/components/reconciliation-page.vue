<script lang="ts" setup>
import type { ReconciliationParams, ReconciliationRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
} from 'element-plus';

import {
  getCustomizedSettingApi,
  getGameTypeListApi,
  getPlatformReconciliationDetailApi,
  getPlatformReconciliationMemberApi,
  getPlatformReconciliationTotalApi,
  getTranslateApi,
  getVendorListApi,
  getVendorReportDropdownApi,
} from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { toHalfWidth } from '#/utils/format';
import { nowInUserTz, toApiTime } from '#/utils/timezone-shift';

import ReconciliationTable from './reconciliation-table.vue';

defineOptions({ name: 'ReconciliationPage' });

const props = defineProps<{
  /**
   * sr11=遊戲平臺對帳表、sr11y=昨日版（vendor/list、固定顯示 pay/cost、多 -8、member_type、無回收）、
   * sr11n=vw 版（vendor/list、pay/sms/cost/回收全隱藏、特殊項僅 -1/-2/-5） */
  variant: 'sr11' | 'sr11n' | 'sr11y';
}>();

const isY = props.variant === 'sr11y';
const isN = props.variant === 'sr11n';
const DETAIL_PER_PAGE = 50;
/** 預設不勾選的平台 id（SR11_NO_PRESELECTION） */
const NO_PRESELECTION = new Set([4, 104, 181, 214, 223, 232]);

/* ---- 下拉資料與設定 ---- */
const translates = ref<Record<string, string>>({});
const vendors = ref<Array<{ id: number; name: string }>>([]);
const gameTypes = ref<Array<{ id: number; name: string }>>([]);
const settings = reactive({ cost: 0, pay: 0, recycle: 0, sms: 0 });

const lab = (id: string, fallback: string) => translates.value[id] ?? fallback;
const costLabel = computed(() => lab('3008', 'Cost'));
const jackpotLabel = computed(() => lab('3004', 'Jackpot'));
/**
 * 支付（代收）與提款（代付）手續費一律用前端字典，不讀 DB 的 3006 / 3018：
 * 3018 從未建過主檔（SR11Y 一直顯示英文 fallback），兩邊各讀一處會長出兩種說法。
 */
const payFeeLabel = computed(() => tl('pay_fees'));
const withdrawalFeeLabel = computed(() => tl('withdrawal_fees'));
const smsFeeLabel = computed(() => lab('3007', 'SMS fee'));

const payVisible = computed(() => isY || (!isN && settings.pay === 1));
const smsVisible = computed(() => !isN && settings.sms === 1);
const costVisible = computed(() => isY || (!isN && settings.cost === 1));
const recycleVisible = computed(() => !isY && !isN && settings.recycle === 1);

/** 特殊平台選項（負值 id；sr11y 無 -7/-9） */
const specialOptions = computed(() => {
  const opts: Array<{ id: number; label: string }> = [
    { id: -1, label: lab('3009', 'V-World') },
    { id: -2, label: lab('3003', 'V-Reward') },
  ];
  if (payVisible.value) {
    opts.push(
      { id: -3, label: payFeeLabel.value },
      { id: -8, label: withdrawalFeeLabel.value },
    );
  }
  if (smsVisible.value) opts.push({ id: -4, label: smsFeeLabel.value });
  opts.push({ id: -5, label: lab('3014', '3 layers of agents mode') });
  if (!isN) opts.push({ id: -6, label: lab('3013', 'Rolling chip') });
  if (!isY && !isN) {
    opts.push(
      { id: -7, label: lab('3017', 'Event cost') },
      { id: -9, label: 'Vip' },
    );
  }
  return opts;
});

/* ---- 搜尋表單 ---- */
/**
 * sr11y 無日期欄位，固定查「昨天一整天」（op_tickets#3447）。
 *
 * 對齊舊版 newsr11y_total.php（同樣打 platformReconciliationTotal）：start＝昨天 00:00:00、
 * end＝今天 00:00:00，API 端 end 再扣 1 秒＝昨天 23:59:59。原本送「今天 23:59」，等於把
 * 今天到目前為止也算進去，新舊後台的昨日數據因此對不上。
 *
 * 每次查詢才算（buildParams 呼叫）：頁面被 KeepAlive 快取、跨過午夜沒重新整理，也不會一直查前一天。
 */
function sr11yDayRange() {
  const today = nowInUserTz().startOf('day');
  const yesterday = today.subtract(1, 'day');
  return {
    /** 送 API 的起訖；end 為今天 00:00:00，由 API 扣 1 秒 */
    api: [
      yesterday.format('YYYY-MM-DD HH:mm:ss'),
      today.format('YYYY-MM-DD HH:mm:ss'),
    ] as const,
    /** 匯出標示用的區間 */
    display: [
      yesterday.format('YYYY-MM-DD 00:00'),
      yesterday.format('YYYY-MM-DD 23:59'),
    ],
  };
}

const filters = reactive({
  game_type: 0 as number | string,
  member_type: 1,
  searchType: 2,
  show_type: 1,
  time_range: (isY
    ? sr11yDayRange().display
    : [
        nowInUserTz().format('YYYY-MM-DD 00:00'),
        nowInUserTz().format('YYYY-MM-DD 23:59'),
      ]) as string[],
  username: '',
  vendor_id: [] as number[],
});

function selectAllVendors() {
  filters.vendor_id = [
    ...vendors.value
      .filter((v) => !NO_PRESELECTION.has(Number(v.id)))
      .map((v) => v.id),
    ...specialOptions.value.map((o) => o.id),
  ];
}
function clearVendors() {
  filters.vendor_id = [];
}

/* ---- 三個結果區 ---- */
const totalLoading = ref(false);
const totalRows = ref<ReconciliationRow[]>([]);
const totalShowV = ref(false);
const totalVisible = ref(false);
/** 下階明細過濾（show_type=1 才有：0 全部/1 僅代理/2 僅會員） */
const lowerFilter = ref(0);
/** 查詢當下的顯示模式（避免重新選 radio 影響已查結果） */
const queriedShowType = ref(1);

const filteredTotalRows = computed(() => {
  if (lowerFilter.value === 1) {
    return totalRows.value.filter((r) => r.lower_type !== 'member');
  }
  if (lowerFilter.value === 2) {
    return totalRows.value.filter((r) => r.lower_type === 'member');
  }
  return totalRows.value;
});

const bottomLoading = ref(false);
/** 下半區：detail（代理明細，分頁）或 member（會員明細） */
const bottomMode = ref<'detail' | 'member' | 'none'>('none');
const bottomRows = ref<ReconciliationRow[]>([]);
const bottomShowV = ref(false);
const detailPage = ref(1);
const detailTotal = ref(0);
/** 下半區目前查詢的帳號（分頁/下鑽用） */
const bottomUsername = ref('');

function buildParams(extra: {
  agent_username?: string;
  member_username?: string;
}): null | ReconciliationParams {
  let startTime: string;
  let endTime: string;
  if (isY) {
    const range = sr11yDayRange();
    filters.time_range = range.display; // 匯出標示跟著這次查詢的日期
    [startTime, endTime] = range.api;
  } else {
    const [start, end] = filters.time_range ?? [];
    if (!start || !end) {
      ElMessage.warning(tl('between'));
      return null;
    }
    startTime = `${start}:00`;
    endTime = `${end}:00`;
  }
  const p: ReconciliationParams = {
    end_time: toApiTime(endTime),
    game_type: filters.game_type,
    show_type: filters.show_type,
    start_time: toApiTime(startTime),
    vendor_id: filters.vendor_id.join(','),
    ...extra,
  };
  if (isY) p.member_type = filters.member_type;
  return p;
}

async function loadTotal(agentUsername: string) {
  const params = buildParams(
    agentUsername ? { agent_username: agentUsername } : {},
  );
  if (!params) return;
  totalLoading.value = true;
  totalVisible.value = true;
  queriedShowType.value = filters.show_type;
  lowerFilter.value = 0;
  try {
    const res = await getPlatformReconciliationTotalApi(params);
    if (Number(res.code) === 1000) {
      totalRows.value = res.data ?? [];
      totalShowV.value = res.show_v_jackpot === true;
    } else {
      totalRows.value = [];
      ElMessage.warning(res.message);
    }
  } finally {
    totalLoading.value = false;
  }
}

async function loadBottom(
  mode: 'detail' | 'member',
  extra: { agent_username?: string; member_username?: string },
) {
  const params = buildParams(extra);
  if (!params) return;
  bottomLoading.value = true;
  bottomMode.value = mode;
  try {
    const res =
      mode === 'detail'
        ? await getPlatformReconciliationDetailApi({
            ...params,
            page: detailPage.value,
            per_page: DETAIL_PER_PAGE,
          })
        : await getPlatformReconciliationMemberApi(params);
    if (Number(res.code) === 1000) {
      bottomRows.value = res.data ?? [];
      bottomShowV.value = res.show_v_jackpot === true;
      detailTotal.value = res.pagination?.total ?? 0;
    } else {
      bottomRows.value = [];
      detailTotal.value = 0;
      ElMessage.warning(res.message);
    }
  } finally {
    bottomLoading.value = false;
  }
}

function handleSearch() {
  bottomMode.value = 'none';
  bottomRows.value = [];
  detailPage.value = 1;
  const username = toHalfWidth(filters.username);
  if (filters.searchType === 3) {
    // 會員帳號查詢：只查會員明細
    totalVisible.value = false;
    bottomUsername.value = '';
    loadBottom('member', username ? { member_username: username } : {});
  } else {
    loadTotal(username);
  }
}

/** 上方統計下鑽：代理→下方明細、會員→下方會員明細（皆以 agent_username 帶入） */
function drillFromTotal(kind: 'agent' | 'member', username: string) {
  detailPage.value = 1;
  bottomUsername.value = username;
  loadBottom(kind === 'agent' ? 'detail' : 'member', {
    agent_username: username,
  });
}

/** 下方表格下鑽：同步刷新上方統計 */
function drillFromBottom(kind: 'agent' | 'member', username: string) {
  detailPage.value = 1;
  bottomUsername.value = username;
  loadTotal(username);
  loadBottom(kind === 'agent' ? 'detail' : 'member', {
    agent_username: username,
  });
}

function handleDetailPage(p: number) {
  detailPage.value = p;
  loadBottom('detail', { agent_username: bottomUsername.value });
}

/* ---- Excel 匯出（委派給 ReconciliationTable，欄位對齊 newsr11） ---- */
const totalTableRef = ref<InstanceType<typeof ReconciliationTable>>();
const bottomTableRef = ref<InstanceType<typeof ReconciliationTable>>();
function exportTotal() {
  totalTableRef.value?.downloadExcel(`${props.variant}_total`, {
    // 同一頁上下兩個表各有匯出鈕，action 要分得開
    audit: { action: `${props.variant}_total`, conditions: { ...filters } },
    dateRange: filters.time_range as [string, string],
    title: isY ? tl('menu_report_sr11y') : tl('sr11'),
  });
}
function exportBottom() {
  bottomTableRef.value?.downloadExcel(`${props.variant}_${bottomMode.value}`, {
    audit: {
      action: `${props.variant}_${bottomMode.value}`,
      conditions: { ...filters, agent_username: bottomUsername.value },
    },
    dateRange: filters.time_range as [string, string],
    title: isY ? tl('menu_report_sr11y') : tl('sr11'),
  });
}

onMounted(async () => {
  getTranslateApi()
    .then((d) => {
      translates.value = d ?? {};
    })
    .catch(() => {});
  getGameTypeListApi()
    .then((d) => {
      gameTypes.value = d ?? [];
    })
    .catch(() => {});
  try {
    const [vendorList, settingList] = await Promise.all([
      isY || isN ? getVendorListApi() : getVendorReportDropdownApi(),
      getCustomizedSettingApi(),
    ]);
    vendors.value = vendorList ?? [];
    const map = new Map(
      (settingList ?? []).map((s: any) => [Number(s.setting_key), s.value]),
    );
    settings.pay = Number(map.get(4053) ?? 0);
    settings.sms = Number(map.get(4054) ?? 0);
    settings.cost = Number(map.get(4055) ?? 0);
    settings.recycle = Number(map.get(5010) ?? 0);
    // 預設全選（排除 NO_PRESELECTION 平台）+ 全部特殊項
    filters.vendor_id = [
      ...vendors.value
        .filter((v) => !NO_PRESELECTION.has(Number(v.id)))
        .map((v) => v.id),
      ...specialOptions.value.map((o) => o.id),
    ];
  } catch {
    // 下拉載入失敗不阻擋頁面
  }
  // 進頁即查（預設代理模式 → 上方統計）
  handleSearch();
});
</script>

<template>
  <Page
    :title="`${tl('statistical_report')} > ${isY ? tl('menu_report_sr11y') : tl('sr11')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem>
            <template #label>
              <ElSelect v-model="filters.searchType" class="!w-36">
                <ElOption :label="tl('agent_account')" :value="2" />
                <ElOption :label="tl('member_account')" :value="3" />
              </ElSelect>
            </template>
            <ElInput v-model="filters.username" clearable />
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
                  v-for="o in specialOptions"
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
          <ElFormItem :label="tl('game_type')">
            <ElSelect v-model="filters.game_type" class="w-full">
              <ElOption :label="tl('all')" :value="0" />
              <ElOption
                v-for="g in gameTypes"
                :key="g.id"
                :label="g.name"
                :value="g.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem v-if="!isY" :label="tl('between')">
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
          <ElFormItem v-if="isY" :label="tl('type')">
            <ElSelect v-model="filters.member_type" class="w-full">
              <ElOption :label="tl('shareholder_member')" :value="1" />
              <ElOption :label="tl('profit_share_member')" :value="2" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('show')">
            <ElRadioGroup v-model="filters.show_type">
              <ElRadio :value="1">{{ tl('game_type') }}</ElRadio>
              <ElRadio :value="0">{{ tl('summary') }}</ElRadio>
            </ElRadioGroup>
          </ElFormItem>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <ElButton
            :loading="totalLoading || bottomLoading"
            native-type="submit"
            type="primary"
          >
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <!-- 上方統計 -->
    <ElCard v-if="totalVisible" class="mb-3" shadow="never">
      <template #header>
        <div class="flex items-center justify-between">
          <span class="font-medium">{{ tl('total_count') }}</span>
          <div class="flex items-center gap-2">
            <ElSelect
              v-if="queriedShowType === 1"
              v-model="lowerFilter"
              class="!w-36"
              size="small"
            >
              <ElOption :label="tl('all')" :value="0" />
              <ElOption :label="tl('only_agent')" :value="1" />
              <ElOption :label="tl('only_member')" :value="2" />
            </ElSelect>
            <ElButton size="small" type="success" @click="exportTotal">
              {{ tl('excel') }}
            </ElButton>
          </div>
        </div>
      </template>
      <div v-loading="totalLoading">
        <ReconciliationTable
          ref="totalTableRef"
          :cost-label="costLabel"
          :game-type-all="queriedShowType !== 1"
          :jackpot-label="jackpotLabel"
          :pay-fee-label="payFeeLabel"
          :rows="filteredTotalRows"
          :show-cost="costVisible"
          :show-recycle="recycleVisible"
          :show-v-jackpot="totalShowV"
          :sms-fee-label="smsFeeLabel"
          :withdrawal-fee-label="withdrawalFeeLabel"
          mode="total"
          @drill="drillFromTotal"
        />
      </div>
    </ElCard>

    <!-- 下方明細 / 會員明細 -->
    <ElCard v-if="bottomMode !== 'none'" shadow="never">
      <template #header>
        <div class="flex items-center justify-between">
          <span class="font-medium">{{ tl('detaillist') }}</span>
          <ElButton size="small" type="success" @click="exportBottom">
            {{ tl('excel') }}
          </ElButton>
        </div>
      </template>
      <div v-loading="bottomLoading">
        <ReconciliationTable
          ref="bottomTableRef"
          :cost-label="costLabel"
          :jackpot-label="jackpotLabel"
          :mode="bottomMode"
          :pay-fee-label="payFeeLabel"
          :rows="bottomRows"
          :show-cost="costVisible"
          :show-recycle="recycleVisible"
          :show-v-jackpot="bottomShowV"
          :sms-fee-label="smsFeeLabel"
          :withdrawal-fee-label="withdrawalFeeLabel"
          @drill="drillFromBottom"
        />
        <TablePagination
          v-if="bottomMode === 'detail'"
          :current-page="detailPage"
          :page-size="DETAIL_PER_PAGE"
          :total="detailTotal"
          @current-change="handleDetailPage"
        />
      </div>
    </ElCard>
  </Page>
</template>
