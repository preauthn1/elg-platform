<script lang="ts" setup>
import type { ShareholderPerfRow } from '#/api';

import { computed, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElOption,
  ElSelect,
} from 'element-plus';

import {
  getCustomizedSettingApi,
  getShareholderCategorySettingApi,
  getShareholderPerformanceApi,
  getShareholderSettlementCategoryApi,
  getVendorManagementListApi,
} from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import { tl } from '#/locales';
import { amountThousands, sumAmounts } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import CategoryVendorHint from './category-vendor-hint.vue';
import PerformanceDetailDialog from './performance-detail-dialog.vue';

defineOptions({ name: 'ShareholderPerformanceQueryPage' });

const props = defineProps<{
  subtitleKey: string;
  titleKey: string;
  variant2?: boolean;
}>();

const route = useRoute();

const n = (v: unknown) => Number(v) || 0;
const fmt = (v: number | string) => amountThousands(v, 2);
const yesterdayStr = dayjs().subtract(1, 'day').format('YYYY-MM-DD');

const filters = reactive({
  start_date: (route.query.start_date as string) || yesterdayStr,
  end_date: (route.query.end_date as string) || yesterdayStr,
  category_type: (route.query.category_type as string) || '0',
  agent_id: (route.query.agent_id as string) || '0',
});

const loading = ref(false);
const searched = ref(false);
const categories = ref<Array<{ id: number | string; name: string }>>([]);
const logoImage = ref('');
const shareVisible = ref(1);

const summary = ref<ShareholderPerfRow[]>([]);
const direct = ref<ShareholderPerfRow[]>([]);
const team = ref<ShareholderPerfRow[]>([]);
const generalAgent = ref<{ nickname?: string }>({});
const searchAgent = ref<Array<{ id: number | string; username: string }>>([]);
const generalSharePoint = ref<number | string>(0);

const collapsed = reactive<Record<string, boolean>>({});

const title = computed(
  () => `${tl(props.titleKey)} > ${tl(props.subtitleKey)}`,
);

/** 同一天且 share_visible==1 才顯示佔成。 */
const showSharePoint = computed(
  () =>
    !!filters.start_date &&
    filters.start_date === filters.end_date &&
    Number(shareVisible.value) === 1,
);

/* 下鑽 modal */
const detailVisible = ref(false);
const detailCtx = reactive({
  mode: 'detail' as 'detail' | 'direct',
  shareholderCategoryId: '' as number | string,
  shareholderCategoryName: '',
  agentId: '' as number | string,
  startTime: '',
  endTime: '',
});

/** 橋接 start_date/end_date ↔ DateRangeFields 的 [start, end] */
const timeRange = computed({
  get: () => [filters.start_date, filters.end_date] as string[],
  set: (v: string[]) => {
    filters.start_date = v?.[0] ?? '';
    filters.end_date = v?.[1] ?? '';
  },
});

async function loadCategories() {
  const res = await getShareholderSettlementCategoryApi();
  if (Number(res.code) === 1000) categories.value = res.data ?? [];
}

/**
 * 分類 id → 該分類底下要顯示的遊戲商名稱，給「遊戲平台組」旁的問號用（target/tickets#4427）。
 *
 * 規則照需求文件：
 * - 來源是類別管理的設定（`categorySetting`），一律取目前的設定，不看查詢區間當時的
 *   分組；每次查詢都重新取回、不快取，後台改了設定，下一次查詢就同步。
 * - 只列「類別管理啟用且本站啟用」的遊戲商：`categorySetting` 已過濾類別管理的啟用，
 *   本站啟用（遊戲商管理 > 廠商管理的開關，`vendors.status`）它沒過濾，另外用廠商管理
 *   同一支清單補上。名稱沿用 `vendors.name`，就是廠商管理顯示的名稱。
 * - 依設定順序：保留 API 回傳的順序（與類別管理頁的列表一致），不另外排序。
 * - 同組內重複商家只列一次：同一家會因不同遊戲類別有多筆設定，以 vendor_id 去重。
 * - 範圍是代理自己那一組的分類。平台共用的組 0 分類不在範圍內（已確認不需要），
 *   那些列會顯示「尚未設定遊戲商」。
 * - 兩支 API 任一失敗就維持上一次的結果，不因為這份輔助資料影響報表本身。
 */
const vendorsByCategory = ref<Record<string, string[]>>({});

async function loadCategoryVendors() {
  try {
    const [res, vendors] = await Promise.all([
      getShareholderCategorySettingApi(),
      getVendorManagementListApi(),
    ]);
    if (Number(res.code) !== 1000) return;

    const enabledVendorIds = new Set(
      (vendors ?? [])
        .filter((v) => Number(v.status) === 1)
        .map((v) => String(v.id)),
    );

    const map: Record<string, Map<string, string>> = {};
    for (const s of res.data?.setting ?? []) {
      const vendorId = String(s.vendor_id);
      if (!s.vendor_name || !enabledVendorIds.has(vendorId)) continue;
      const names = (map[String(s.category_id)] ??= new Map());
      if (!names.has(vendorId)) names.set(vendorId, s.vendor_name);
    }
    vendorsByCategory.value = Object.fromEntries(
      Object.entries(map).map(([id, names]) => [id, [...names.values()]]),
    );
  } catch {
    // 輔助資料，失敗不影響報表
  }
}

function vendorsOf(categoryId: unknown): string[] {
  return vendorsByCategory.value[String(categoryId)] ?? [];
}

/**
 * Settlement 列（shareholder_category_id = 1）不顯示問號：它是平台共用組 0 的結算
 * 分類，本來就不會設定遊戲商（已確認）。判斷方式與本頁「Settlement 列不可點明細」
 * 的 `Number(r.scid) !== 1` 同一條規則。其餘分類沒有遊戲商時照需求顯示問號，
 * 點開是「尚未設定遊戲商」。
 */
function showVendorHint(categoryId: unknown): boolean {
  return Number(categoryId) !== 1;
}

async function loadSettings() {
  const settings = await getCustomizedSettingApi();
  const find = (k: string) =>
    (settings ?? []).find((s: any) => String(s.setting_key) === k)?.value;
  logoImage.value = String(find('8008') ?? '');
  shareVisible.value = Number(find('95004') ?? 1);
}

async function search() {
  loading.value = true;
  searched.value = true;
  // 與報表並行，不 await：問號的遊戲商清單是輔助資料，不拖慢報表（見 loadCategoryVendors）
  void loadCategoryVendors();
  const startTime = `${filters.start_date} 00:00:00`;
  const endTime = `${filters.end_date} 23:59:59`;
  try {
    const res = await getShareholderPerformanceApi(
      {
        agent_id: filters.agent_id,
        category_type: filters.category_type,
        end_time: endTime,
        start_time: startTime,
      },
      props.variant2,
    );
    if (Number(res.code) === 1000) {
      const d = res.data ?? {};
      summary.value = d.summary ?? [];
      direct.value = d.direct ?? [];
      team.value = d.team ?? [];
      generalAgent.value = d.general_agent ?? {};
      searchAgent.value = d.search_agent ?? [];
      generalSharePoint.value = d.share_point?.general_agent ?? 0;
    } else {
      // 後端異常（如 role_id 錯）→ 顯示空表
      summary.value = [];
      direct.value = [];
      team.value = [];
      generalAgent.value = {};
      searchAgent.value = [];
    }
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  filters.agent_id = '0';
  search();
}

function drillAgent(agentId: number | string) {
  filters.agent_id = String(agentId);
  search();
}

function toggle(mode: string) {
  collapsed[mode] = !collapsed[mode];
}

function openDetail(mode: 'detail' | 'direct', row: ShareholderPerfRow) {
  detailCtx.mode = mode;
  detailCtx.shareholderCategoryId = row.shareholder_category_id ?? '';
  detailCtx.shareholderCategoryName = row.name ?? '';
  detailCtx.agentId = row.agent_id ?? '';
  detailCtx.startTime = `${filters.start_date} 00:00:00`;
  detailCtx.endTime = `${filters.end_date} 23:59:59`;
  detailVisible.value = true;
}

/* ── 分組轉換 ── */
type DRow = Record<string, any> & { kind: string };

const summaryRows = computed<DRow[]>(() => {
  const out: DRow[] = [];
  let tT: number[] = [];
  const tS: number[] = [];
  const tP: number[] = [];
  let rowCount = 0;
  let trSettlement = false;
  for (const raw of summary.value) {
    const cat1 = Number(raw.shareholder_category_id) === 1;
    let turnover = n(raw.turnover);
    let spending = n(raw.game_spending);
    if (cat1) {
      turnover = Number(sumAmounts(tT));
      spending = n(raw.game_profit) * -1;
      if (rowCount > 0)
        out.push({
          kind: 'subtotal',
          t: turnover,
          s: Number(sumAmounts(tS)),
          p: Number(sumAmounts(tP)),
        });
      if (!trSettlement) {
        out.push({
          kind: 'header',
          mode: 'summary_settlement',
          label: 'Settlement',
          count: 1,
        });
        trSettlement = true;
      }
      tT = [];
    }
    out.push({
      kind: 'data',
      mode: cat1 ? 'summary_settlement' : 'summary_other',
      username: generalAgent.value.nickname,
      scid: raw.shareholder_category_id,
      catName: raw.name,
      t: turnover,
      s: spending,
      p: n(raw.game_profit),
    });
    rowCount += 1;
    tT.push(turnover);
    tS.push(spending);
    tP.push(n(raw.game_profit));
  }
  if (rowCount > 0)
    out.push({
      kind: 'total',
      t: Number(sumAmounts(tT)),
      s: Number(sumAmounts(tS)),
      p: Number(sumAmounts(tP)),
    });
  return out;
});

function buildLowerRows(
  rows: ShareholderPerfRow[],
  prefix: string,
  isTeam: boolean,
) {
  const out: DRow[] = [];
  let rowCount = 0;
  let dataCount = 0;
  let settlementCount = 0;
  let totalT: number[] = [];
  const totalNp: number[] = [];
  let trSubTotal = false;
  let trSettlement = false;
  for (const raw of rows) {
    const cat1 = Number(raw.shareholder_category_id) === 1;
    if (cat1) {
      if ((isTeam ? rowCount : dataCount) > 0 && !trSubTotal) {
        out.push({
          kind: 'subtotal_lower',
          mode: `${prefix}_other`,
          t: Number(sumAmounts(totalT)),
          np: Number(sumAmounts(totalNp)),
        });
        if (!isTeam) {
          out.push({
            kind: 'header',
            mode: `${prefix}_settlement`,
            label: 'Settlement',
            count: 0,
          });
        }
        totalT = [];
        trSubTotal = true;
      }
      if (isTeam && !trSettlement) {
        out.push({
          kind: 'header',
          mode: `${prefix}_settlement`,
          label: 'Settlement',
          count: 0,
        });
        trSettlement = true;
      }
    } else if ((isTeam ? rowCount : dataCount) === 0) {
      out.push({
        kind: 'header',
        mode: `${prefix}_other`,
        label: tl('share_profit'),
        count: 0,
      });
    }
    out.push({
      kind: 'data_lower',
      mode: cat1 ? `${prefix}_settlement` : `${prefix}_other`,
      scid: raw.shareholder_category_id,
      agent_id: raw.agent_id,
      username: raw.agent_username,
      nickname: raw.agent_nickname,
      catName: raw.name,
      t: n(raw.turnover),
      np: n(raw.nett_profit),
      share_point: raw.share_point,
      upper_share_point: raw.upper_share_point,
      isTeam,
      raw,
    });
    rowCount += 1;
    totalT.push(n(raw.turnover));
    totalNp.push(n(raw.nett_profit));
    dataCount += cat1 ? 0 : 1;
    settlementCount += cat1 ? 1 : 0;
  }
  if (rowCount > 0)
    out.push({
      kind: 'total_lower',
      t: Number(sumAmounts(totalT)),
      np: Number(sumAmounts(totalNp)),
    });
  // 回填 count
  for (const r of out) {
    if (r.kind === 'header' && r.mode === `${prefix}_other`)
      r.count = dataCount;
    if (r.kind === 'header' && r.mode === `${prefix}_settlement`)
      r.count = settlementCount;
  }
  return out;
}

const directRows = computed(() =>
  buildLowerRows(direct.value, 'direct', false),
);
const teamRows = computed(() => buildLowerRows(team.value, 'team', true));

function shareText(row: DRow) {
  if (!showSharePoint.value) return '-- / --';
  const sp = row.isTeam
    ? n(row.upper_share_point) - n(row.share_point)
    : n(row.share_point);
  return `${sp} / ${generalSharePoint.value}`;
}

async function init() {
  await Promise.all([loadCategories(), loadSettings()]);
  if (route.query.act === '1') await search();
}
init();
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('between')">
            <DateRangeFields v-model="timeRange" :fixed-offset="8" />
            <FixedTimezoneHint />
          </ElFormItem>
          <ElFormItem :label="tl('type')">
            <ElSelect
              v-model="filters.category_type"
              class="!w-full"
              filterable
            >
              <ElOption :label="tl('all')" value="0" />
              <ElOption
                v-for="c in categories"
                :key="c.id"
                :label="c.name"
                :value="String(c.id)"
              />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex items-center justify-end gap-2">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <!-- search history + logo -->
    <ElCard v-if="searched" v-loading="loading" shadow="never">
      <div class="mb-2 flex items-start justify-between">
        <div v-if="searchAgent.length > 0" class="text-sm">
          <b>{{ tl('search_history') }}:</b>
          <template v-for="(a, i) in searchAgent" :key="a.id">
            <span v-if="i > 0"> &gt; </span>
            <a
              class="cursor-pointer font-bold text-indigo-500"
              @click="drillAgent(a.id)"
              >{{ a.username }}</a
            >
          </template>
        </div>
        <img v-if="logoImage" :src="logoImage" class="max-h-10 max-w-[150px]" />
      </div>

      <!-- summary 公司彙總 -->
      <div class="mb-4 overflow-auto">
        <table
          class="w-full border-collapse text-sm [&_td]:border [&_td]:px-2 [&_td]:py-1 [&_th]:border [&_th]:px-2 [&_th]:py-1"
        >
          <thead>
            <tr>
              <th>{{ tl('username') }}</th>
              <th>{{ tl('gaming_platform_group') }}</th>
              <th class="bg-[#f1dda0] text-gray-900">{{ tl('turnover') }}</th>
              <th class="bg-[#f1dda0] text-gray-900">
                {{ tl('bonus_payout') }}
              </th>
              <th class="bg-[#f1dda0] text-gray-900">{{ tl('net_profit') }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="(r, i) in summaryRows" :key="i">
              <tr
                v-if="r.kind === 'header'"
                class="cursor-pointer bg-gray-100 dark:bg-gray-800"
                @click="toggle(r.mode)"
              >
                <td colspan="5" class="font-bold">
                  <span
                    :class="
                      collapsed[r.mode] ? 'ti-angle-right' : 'ti-angle-down'
                    "
                  ></span>
                  {{ r.label }} (Count: {{ r.count }})
                </td>
              </tr>
              <tr v-else-if="r.kind === 'subtotal'">
                <td colspan="2" class="font-bold">Sub - total</td>
                <td
                  class="bg-[#f1dda0] text-right font-bold"
                  :class="r.t < 0 ? 'text-red-500' : 'text-gray-900'"
                >
                  {{ fmt(r.t) }}
                </td>
                <td
                  class="bg-[#f1dda0] text-right font-bold"
                  :class="r.s < 0 ? 'text-red-500' : 'text-gray-900'"
                >
                  {{ fmt(r.s) }}
                </td>
                <td
                  class="bg-[#f1dda0] text-right font-bold"
                  :class="r.p < 0 ? 'text-red-500' : 'text-gray-900'"
                >
                  {{ fmt(r.p) }}
                </td>
              </tr>
              <tr v-else-if="r.kind === 'total'" class="font-bold">
                <td colspan="2">Total</td>
                <td
                  class="bg-[#f1dda0] text-right"
                  :class="r.t < 0 ? 'text-red-500' : 'text-gray-900'"
                >
                  {{ fmt(r.t) }}
                </td>
                <td
                  class="bg-[#f1dda0] text-right"
                  :class="r.s < 0 ? 'text-red-500' : 'text-gray-900'"
                >
                  {{ fmt(r.s) }}
                </td>
                <td
                  class="bg-[#f1dda0] text-right"
                  :class="r.p < 0 ? 'text-red-500' : 'text-gray-900'"
                >
                  {{ fmt(r.p) }}
                </td>
              </tr>
              <tr v-else v-show="!collapsed[r.mode]">
                <td class="font-bold">
                  <MemberAccountLink :username="r.username" />
                </td>
                <td class="font-bold text-indigo-500">
                  {{ r.catName
                  }}<CategoryVendorHint
                    v-if="showVendorHint(r.scid)"
                    :category-name="r.catName"
                    :vendors="vendorsOf(r.scid)"
                  />
                </td>
                <td
                  class="bg-[#f1dda0] text-right font-bold"
                  :class="r.t < 0 ? 'text-red-500' : 'text-gray-900'"
                >
                  {{ fmt(r.t) }}
                </td>
                <td
                  class="bg-[#f1dda0] text-right font-bold"
                  :class="r.s < 0 ? 'text-red-500' : 'text-gray-900'"
                >
                  {{ fmt(r.s) }}
                </td>
                <td
                  class="bg-[#f1dda0] text-right font-bold"
                  :class="r.p < 0 ? 'text-red-500' : 'text-gray-900'"
                >
                  {{ fmt(r.p) }}
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- direct 直屬 -->
      <div v-if="direct.length > 0" class="mb-4 overflow-auto">
        <table
          class="w-full border-collapse text-sm [&_td]:border [&_td]:px-2 [&_td]:py-1 [&_th]:border [&_th]:px-2 [&_th]:py-1"
        >
          <thead>
            <tr>
              <th>{{ tl('username') }}</th>
              <th>{{ tl('nickname') }}</th>
              <th>{{ tl('gaming_platform_group') }}</th>
              <th class="bg-[#f1dda0] text-gray-900">{{ tl('turnover') }}</th>
              <th>{{ tl('shareholding') }}</th>
              <th>{{ tl('nett_profit') }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="(r, i) in directRows" :key="i">
              <tr
                v-if="r.kind === 'header'"
                class="cursor-pointer bg-gray-100 dark:bg-gray-800"
                @click="toggle(r.mode)"
              >
                <td colspan="6" class="font-bold">
                  <span
                    :class="
                      collapsed[r.mode] ? 'ti-angle-right' : 'ti-angle-down'
                    "
                  ></span>
                  {{ r.label }} (Count: {{ r.count }})
                </td>
              </tr>
              <tr
                v-else-if="r.kind === 'subtotal_lower'"
                v-show="!collapsed[r.mode]"
              >
                <td colspan="3" class="font-bold">Sub - Total</td>
                <td
                  class="bg-[#f1dda0] text-right font-bold"
                  :class="r.t < 0 ? 'text-red-500' : 'text-gray-900'"
                >
                  {{ fmt(r.t) }}
                </td>
                <td>&nbsp;</td>
                <td
                  class="text-right font-bold"
                  :class="r.np < 0 ? 'text-red-500' : ''"
                >
                  {{ fmt(r.np) }}
                </td>
              </tr>
              <tr v-else-if="r.kind === 'total_lower'" class="font-bold">
                <td colspan="3">Total</td>
                <td
                  class="bg-[#f1dda0] text-right"
                  :class="r.t < 0 ? 'text-red-500' : 'text-gray-900'"
                >
                  {{ fmt(r.t) }}
                </td>
                <td>&nbsp;</td>
                <td class="text-right" :class="r.np < 0 ? 'text-red-500' : ''">
                  {{ fmt(r.np) }}
                </td>
              </tr>
              <tr v-else v-show="!collapsed[r.mode]">
                <td class="font-bold">
                  <MemberAccountLink :username="r.username" />
                </td>
                <td class="font-bold">{{ r.nickname }}</td>
                <td>
                  <a
                    v-if="Number(r.scid) !== 1"
                    class="cursor-pointer font-bold italic text-indigo-500"
                    @click="openDetail('direct', r.raw)"
                    >{{ r.catName }}</a
                  >
                  <span v-else class="font-bold italic text-indigo-500">{{
                    r.catName
                  }}</span>
                  <CategoryVendorHint
                    v-if="showVendorHint(r.scid)"
                    :category-name="r.catName"
                    :vendors="vendorsOf(r.scid)"
                  />
                </td>
                <td
                  class="bg-[#f1dda0] text-right font-bold"
                  :class="r.t < 0 ? 'text-red-500' : 'text-gray-900'"
                >
                  {{ fmt(r.t) }}
                </td>
                <td class="text-right font-bold">{{ shareText(r) }}</td>
                <td
                  class="text-right font-bold"
                  :class="r.np < 0 ? 'text-red-500' : ''"
                >
                  {{ fmt(r.np) }}
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- team 團隊 -->
      <div v-if="team.length > 0" class="overflow-auto">
        <table
          class="w-full border-collapse text-sm [&_td]:border [&_td]:px-2 [&_td]:py-1 [&_th]:border [&_th]:px-2 [&_th]:py-1"
        >
          <thead>
            <tr>
              <th>{{ tl('username') }}</th>
              <th>{{ tl('nickname') }}</th>
              <th>{{ tl('gaming_platform_group') }}</th>
              <th class="bg-[#f1dda0] text-gray-900">{{ tl('turnover') }}</th>
              <th>{{ tl('shareholding') }}</th>
              <th>{{ tl('nett_profit') }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="(r, i) in teamRows" :key="i">
              <tr
                v-if="r.kind === 'header'"
                class="cursor-pointer bg-gray-100 dark:bg-gray-800"
                @click="toggle(r.mode)"
              >
                <td colspan="6" class="font-bold">
                  <span
                    :class="
                      collapsed[r.mode] ? 'ti-angle-right' : 'ti-angle-down'
                    "
                  ></span>
                  {{ r.label }} (Count: {{ r.count }})
                </td>
              </tr>
              <tr
                v-else-if="r.kind === 'subtotal_lower'"
                v-show="!collapsed[r.mode]"
              >
                <td colspan="3" class="font-bold">Sub - Total</td>
                <td
                  class="bg-[#f1dda0] text-right font-bold"
                  :class="r.t < 0 ? 'text-red-500' : 'text-gray-900'"
                >
                  {{ fmt(r.t) }}
                </td>
                <td>&nbsp;</td>
                <td
                  class="text-right font-bold"
                  :class="r.np < 0 ? 'text-red-500' : ''"
                >
                  {{ fmt(r.np) }}
                </td>
              </tr>
              <tr v-else-if="r.kind === 'total_lower'" class="font-bold">
                <td colspan="3">Total</td>
                <td
                  class="bg-[#f1dda0] text-right"
                  :class="r.t < 0 ? 'text-red-500' : 'text-gray-900'"
                >
                  {{ fmt(r.t) }}
                </td>
                <td>&nbsp;</td>
                <td class="text-right" :class="r.np < 0 ? 'text-red-500' : ''">
                  {{ fmt(r.np) }}
                </td>
              </tr>
              <tr v-else v-show="!collapsed[r.mode]">
                <td>
                  <a
                    class="cursor-pointer font-bold text-indigo-500"
                    @click="drillAgent(r.agent_id)"
                    >{{ r.username }}</a
                  >
                </td>
                <td class="font-bold">{{ r.nickname }}</td>
                <td>
                  <a
                    v-if="Number(r.scid) !== 1"
                    class="cursor-pointer font-bold italic text-indigo-500"
                    @click="openDetail('detail', r.raw)"
                    >{{ r.catName }}</a
                  >
                  <span v-else class="font-bold italic text-indigo-500">{{
                    r.catName
                  }}</span>
                  <CategoryVendorHint
                    v-if="showVendorHint(r.scid)"
                    :category-name="r.catName"
                    :vendors="vendorsOf(r.scid)"
                  />
                </td>
                <td
                  class="bg-[#f1dda0] text-right font-bold"
                  :class="r.t < 0 ? 'text-red-500' : 'text-gray-900'"
                >
                  {{ fmt(r.t) }}
                </td>
                <td class="text-right font-bold">{{ shareText(r) }}</td>
                <td
                  class="text-right font-bold"
                  :class="r.np < 0 ? 'text-red-500' : ''"
                >
                  {{ fmt(r.np) }}
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </ElCard>

    <PerformanceDetailDialog
      v-model="detailVisible"
      :agent-id="detailCtx.agentId"
      :end-time="detailCtx.endTime"
      :mode="detailCtx.mode"
      :shareholder-category-id="detailCtx.shareholderCategoryId"
      :shareholder-category-name="detailCtx.shareholderCategoryName"
      :start-time="detailCtx.startTime"
      :variant2="variant2"
    />
  </Page>
</template>
