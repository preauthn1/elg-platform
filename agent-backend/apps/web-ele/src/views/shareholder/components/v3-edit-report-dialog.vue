<script lang="ts" setup>
import type {
  ShareholderCategoryRow,
  ShareholderTotalSalesSummary,
  ShareholderV3ForeignTotalSalesRow,
} from '#/api';

import { computed, reactive, ref } from 'vue';

import dayjs from 'dayjs';
import {
  ElButton,
  ElDialog,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElTag,
} from 'element-plus';

import {
  getShareholderCategoryListApi,
  getShareholderCurrencyCodeApi,
  getShareholderForeignCategoryApi,
  getShareholderTotalSalesApi,
  getShareholderV3CurrencyRateListApi,
  getShareholderV3ForeignTotalSalesApi,
  updateShareholderV3TotalSalesApi,
} from '#/api';
import { tl } from '#/locales';
import { amountThousands, numberFormat } from '#/utils/format';

defineOptions({ name: 'ShareholderV3EditReportDialog' });

const props = defineProps<{
  agentId: number | string;
  payoutRecordId: number | string;
  summaryDate: string;
}>();
const emit = defineEmits<{ success: [] }>();
const visible = defineModel<boolean>({ default: false });

/** 同步狀態 3=排除，排除的子站不計入加總 */
const SYNC_EXCLUDED = 3;
/** 報表狀態 3=已輸入數據（未結算），只有此階段可編輯 */
const EDITABLE_STATUS = 3;

/*
 * 精度運算（無條件捨去）。
 * numMul / numSub 對齊 PHP 版：PHP 先 strval() 才運算，以預設 precision=14 輸出，
 * 浮點尾差不會被帶進 floor；直接用 JS 乘除會少 0.0001。
 * numDiv / toFixedFloor 是瀏覽器端換算用的，維持原本的 JS 語意。
 */
const p14 = (n: number) => Number(n.toPrecision(14));
const numMul = (a: number, b: number) =>
  Math.floor(Math.floor(p14(a * 10_000) * p14(b * 10_000)) / 10_000) / 10_000;
const numSub = (a: number, b: number) =>
  Math.floor(p14(a * 10_000) - p14(b * 10_000)) / 10_000;
const numDiv = (a: number, b: number) => (a * 10_000) / (b * 10_000);
const toFixedFloor = (v: number, d: number) => {
  const f = 10 ** d;
  return Math.floor(v * f) / f;
};

/** 只允許數字與小數點（總賠出不可為負） */
function filterPositiveDecimal(v: string) {
  const s = v.replaceAll(/[^\d.]/g, '');
  const i = s.indexOf('.');
  return i === -1 ? s : s.slice(0, i + 1) + s.slice(i + 1).replaceAll('.', '');
}
/** 只允許數字、小數點與開頭負號 */
function filterDecimal(v: string) {
  const neg = v.startsWith('-');
  return (neg ? '-' : '') + filterPositiveDecimal(v);
}

interface ForeignSite {
  byCategory: Map<string, ShareholderV3ForeignTotalSalesRow>;
  currencyCode: string;
  excluded: boolean;
  rate: number;
  site: string;
}

const loading = ref(false);
const saving = ref(false);
const step = ref(1);
const reportStatus = ref(0);
const categories = ref<ShareholderCategoryRow[]>([]);
const localRate = ref(0);
const localCurrency = ref('');
const summaryByName = ref<Map<string, ShareholderTotalSalesSummary>>(new Map());
const foreignSites = ref<ForeignSite[]>([]);
const recordIdByCat = reactive<Record<string, number | string>>({});
const usdTurnover = reactive<Record<string, string>>({});
const usdSpending = reactive<Record<string, string>>({});

/** 業績日期轉 API 需要的 -8h 時間字串 */
const apiDate = computed(() =>
  dayjs(`${props.summaryDate} 00:00:00`)
    .subtract(8, 'hour')
    .format('YYYY-MM-DD HH:mm:ss'),
);
const editable = computed(() => reportStatus.value === EDITABLE_STATUS);

function close() {
  visible.value = false;
}

async function alertAndClose(message: string) {
  close();
  await ElMessageBox.alert(message).catch(() => {});
}

/** 外站類別必須是本站類別的子集 */
function checkForeignCategories(
  foreignCategoryData: unknown,
  localNames: string[],
) {
  const foreignNames = new Set<string>();
  if (foreignCategoryData && typeof foreignCategoryData === 'object') {
    for (const arr of Object.values(
      foreignCategoryData as Record<string, Array<{ name?: string }>>,
    )) {
      for (const c of arr ?? []) if (c?.name) foreignNames.add(c.name);
    }
  }
  const known = new Set(localNames);
  return [...foreignNames].filter((n) => !known.has(n));
}

/** 每次開啟都從乾淨狀態開始，避免帶入上一列的數字 */
function resetState() {
  step.value = 1;
  reportStatus.value = 0;
  categories.value = [];
  foreignSites.value = [];
  summaryByName.value = new Map();
  localRate.value = 0;
  localCurrency.value = '';
  for (const k of Object.keys(recordIdByCat)) delete recordIdByCat[k];
  for (const k of Object.keys(usdTurnover)) delete usdTurnover[k];
  for (const k of Object.keys(usdSpending)) delete usdSpending[k];
}

async function load() {
  loading.value = true;
  resetState();
  try {
    const [catRes, foreignCatRes, currencyRes, rateRes] = await Promise.all([
      getShareholderCategoryListApi(),
      getShareholderForeignCategoryApi(),
      getShareholderCurrencyCodeApi(),
      getShareholderV3CurrencyRateListApi({ per_page: 999 }),
    ]);

    const cats: ShareholderCategoryRow[] =
      Number(catRes.code) === 1000 ? (catRes.data ?? []) : [];
    if (Number(catRes.code) !== 1000) ElMessage.error(catRes.message);
    categories.value = cats.filter((c) => c.name);

    // 1001 = 無外站資訊，不需比對
    if (Number(foreignCatRes.code) === 1000) {
      const invalid = checkForeignCategories(
        foreignCatRes.data,
        cats.map((c) => c.name ?? ''),
      );
      if (invalid.length > 0) {
        await alertAndClose(
          `外站類別不得超出本站範圍，請檢查分桶管理>類別管理：${invalid.join('、')}`,
        );
        return;
      }
    } else if (Number(foreignCatRes.code) !== 1001) {
      // 取不到外站類別只提示，仍照本站資料往下走
      ElMessage.error(foreignCatRes.message);
    }

    if (Number(currencyRes.code) !== 1000) {
      await alertAndClose(currencyRes.message);
      return;
    }
    localCurrency.value = String(currencyRes.data ?? '');

    const rateRow =
      Number(rateRes.code) === 1000
        ? (rateRes.data ?? []).find(
            (r) => String(r.currency ?? '') === localCurrency.value,
          )
        : undefined;
    localRate.value = Number(rateRow?.exchange_rate_to_usd ?? 0);
    if (localRate.value <= 0) {
      await alertAndClose(`請至匯率管理設定 ${localCurrency.value} 對美金匯率`);
      return;
    }

    const [salesRes, foreignSalesRes] = await Promise.all([
      getShareholderTotalSalesApi(
        {
          date: apiDate.value,
          id: props.agentId,
          payout_record_id: props.payoutRecordId,
        },
        true,
      ),
      getShareholderV3ForeignTotalSalesApi(apiDate.value),
    ]);

    if (Number(salesRes.code) === 1000) {
      reportStatus.value = Number(salesRes.data?.status ?? 0);
      const byName = new Map<string, ShareholderTotalSalesSummary>();
      for (const s of salesRes.data?.summary ?? []) {
        if (s.name) byName.set(s.name, s);
      }
      summaryByName.value = byName;
    } else {
      reportStatus.value = 0;
      summaryByName.value = new Map();
      ElMessage.error(salesRes.message);
    }

    const sites = new Map<string, ForeignSite>();
    if (Number(foreignSalesRes.code) === 1000) {
      for (const row of foreignSalesRes.data ?? []) {
        const site = String(row.site_name ?? '');
        if (!site) continue;
        let entry = sites.get(site);
        if (!entry) {
          entry = {
            byCategory: new Map(),
            currencyCode: String(row.currency ?? ''),
            excluded: Number(row.sync_status ?? 0) === SYNC_EXCLUDED,
            rate: Number(row.rate_to_usd ?? 0),
            site,
          };
          sites.set(site, entry);
        }
        if (row.category_name) entry.byCategory.set(row.category_name, row);
      }
    } else if (Number(foreignSalesRes.code) !== 1001) {
      ElMessage.error(foreignSalesRes.message);
    }
    foreignSites.value = [...sites.values()];

    // 初始化每個類別的 USD 輸入值（本站 + 未排除的外站，各自換算 USD 後加總）
    for (const c of categories.value) {
      const key = String(c.id);
      const s = summaryByName.value.get(c.name ?? '');
      recordIdByCat[key] = s?.id ?? Number(c.id) * -1;
      let totalTurnover = numMul(
        Number(s?.original_turnover ?? s?.turnover ?? 0),
        localRate.value,
      );
      let totalSpending = numMul(
        Number(s?.original_game_spending ?? s?.game_spending ?? 0),
        localRate.value,
      );
      for (const fs of foreignSites.value) {
        if (fs.excluded) continue;
        const f = fs.byCategory.get(c.name ?? '');
        totalTurnover += numMul(Number(f?.turnover ?? 0), fs.rate);
        totalSpending += numMul(Number(f?.win ?? 0), fs.rate);
      }
      usdTurnover[key] = String(totalTurnover);
      usdSpending[key] = String(totalSpending);
    }
  } finally {
    loading.value = false;
  }
}

/** step1：本站列（含各外站子列） */
const editRows = computed(() =>
  categories.value.map((c) => {
    const key = String(c.id);
    const s = summaryByName.value.get(c.name ?? '');
    return {
      foreign: foreignSites.value.map((fs) => {
        const f = fs.byCategory.get(c.name ?? '');
        return {
          currencyCode: fs.currencyCode,
          excluded: fs.excluded,
          rate: fs.rate,
          site: example.invalid,
          spending: Number(f?.win ?? 0),
          turnover: Number(f?.turnover ?? 0),
        };
      }),
      id: c.id,
      key,
      localSpending: Number(s?.original_game_spending ?? s?.game_spending ?? 0),
      localTurnover: Number(s?.original_turnover ?? s?.turnover ?? 0),
      name: c.name ?? '',
      rowspan: 1 + foreignSites.value.length,
    };
  }),
);

/** step2：可編輯時由 USD 輸入值反推本站幣，唯讀時直接顯示本站業績 */
const previewRows = computed(() =>
  categories.value.map((c) => {
    const key = String(c.id);
    const s = summaryByName.value.get(c.name ?? '');
    if (editable.value) {
      const usdT = Number(usdTurnover[key]) || 0;
      const usdS = Number(usdSpending[key]) || 0;
      return {
        id: c.id,
        name: c.name ?? '',
        spending: toFixedFloor(numDiv(usdS, localRate.value || 1), 4),
        turnover: toFixedFloor(numDiv(usdT, localRate.value || 1), 4),
        usdProfit: toFixedFloor(usdT - usdS, 4),
        usdSpending: usdS,
        usdTurnover: usdT,
      };
    }
    const localT = Number(s?.turnover ?? 0);
    const localS = Number(s?.game_spending ?? 0);
    const usdT = Math.round(localT * localRate.value * 10_000) / 10_000;
    const usdS = Math.round(localS * localRate.value * 10_000) / 10_000;
    return {
      id: c.id,
      name: c.name ?? '',
      spending: localS,
      turnover: localT,
      usdProfit: numSub(usdT, usdS),
      usdSpending: usdS,
      usdTurnover: usdT,
    };
  }),
);

/** step1 顯示的 USD 原始加總 */
function origUsd(
  row: (typeof editRows.value)[number],
  field: 'spending' | 'turnover',
) {
  let total = numMul(
    field === 'turnover' ? row.localTurnover : row.localSpending,
    localRate.value,
  );
  for (const f of row.foreign) {
    if (f.excluded) continue;
    total += numMul(field === 'turnover' ? f.turnover : f.spending, f.rate);
  }
  return total;
}

async function save() {
  saving.value = true;
  try {
    const record = categories.value.map((c) => ({
      category_name: c.name ?? '',
      game_spending: usdSpending[String(c.id)] || '0.0000',
      id: recordIdByCat[String(c.id)] ?? Number(c.id) * -1,
      turnover: usdTurnover[String(c.id)] || '0.0000',
    }));
    const res = await updateShareholderV3TotalSalesApi({
      payout_record_id: props.payoutRecordId,
      record,
      summary_date: apiDate.value,
    });
    if (Number(res.code) === 1000) {
      close();
      emit('success');
    } else if (String(res.code) === '2002') {
      await ElMessageBox.alert(String(res.data ?? '')).catch(() => {});
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('menu_cj7_shareholder_report')"
    width="1140px"
    @open="load"
  >
    <div v-loading="loading" class="overflow-auto">
      <!-- step1：輸入 USD 總押注 / 總賠出 -->
      <table
        v-show="editable && step === 1"
        class="w-full border-collapse border text-sm [&_td]:border [&_td]:p-2 [&_th]:border [&_th]:p-2"
      >
        <thead>
          <tr>
            <th>{{ tl('category') }}</th>
            <th>{{ tl('site') }}</th>
            <th>{{ tl('currency') }}</th>
            <th>{{ tl('total_bet') }}</th>
            <th>{{ tl('total_lose') }}</th>
            <th>USD{{ tl('rate') }}</th>
            <th>{{ tl('status') }}</th>
            <th>USD{{ tl('total_bet') }}</th>
            <th>USD{{ tl('total_lose') }}</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="row in editRows" :key="row.key">
            <tr>
              <td :rowspan="row.rowspan">{{ row.name }}</td>
              <td>{{ tl('local_site') }}</td>
              <td>{{ localCurrency }}</td>
              <td class="text-right">
                {{ amountThousands(row.localTurnover, 4) }}
              </td>
              <td class="text-right">
                {{ amountThousands(row.localSpending, 4) }}
              </td>
              <td class="text-right">{{ numberFormat(localRate, 8) }}</td>
              <td>-</td>
              <td :rowspan="row.rowspan">
                <div class="mb-1 text-xs">
                  {{ tl('original_data') }}:{{
                    amountThousands(origUsd(row, 'turnover'), 4)
                  }}
                </div>
                <ElInput
                  v-model="usdTurnover[row.key]"
                  :formatter="filterDecimal"
                  :parser="filterDecimal"
                />
              </td>
              <td :rowspan="row.rowspan">
                <div class="mb-1 text-xs">
                  {{ tl('original_data') }}:{{
                    amountThousands(origUsd(row, 'spending'), 4)
                  }}
                </div>
                <ElInput
                  v-model="usdSpending[row.key]"
                  :formatter="filterPositiveDecimal"
                  :parser="filterPositiveDecimal"
                />
              </td>
            </tr>
            <tr v-for="f in row.foreign" :key="`${row.key}-${example.invalid}`">
              <td>{{ example.invalid }}</td>
              <td>{{ f.currencyCode }}</td>
              <td class="text-right">{{ amountThousands(f.turnover, 4) }}</td>
              <td class="text-right">{{ amountThousands(f.spending, 4) }}</td>
              <td class="text-right">{{ numberFormat(f.rate, 8) }}</td>
              <td class="whitespace-nowrap">
                <ElTag :type="f.excluded ? 'warning' : 'success'" size="small">
                  {{ f.excluded ? tl('excluded') : tl('synced') }}
                </ElTag>
              </td>
            </tr>
          </template>
        </tbody>
      </table>

      <!-- step2：換算結果 -->
      <table
        v-show="!editable || step === 2"
        class="w-full border-collapse border text-sm [&_td]:border [&_td]:p-2 [&_th]:border [&_th]:p-2"
      >
        <thead>
          <tr>
            <th>{{ tl('category') }}</th>
            <th>{{ tl('currency') }}</th>
            <th>{{ tl('total_bet') }}</th>
            <th>{{ tl('total_lose') }}</th>
            <th>USD{{ tl('rate') }}</th>
            <th>USD{{ tl('total_bet') }}</th>
            <th>USD{{ tl('total_lose') }}</th>
            <th>USD{{ tl('net_profit') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in previewRows" :key="row.id">
            <td>{{ row.name }}</td>
            <td>{{ localCurrency }}</td>
            <td class="text-right">{{ amountThousands(row.turnover, 4) }}</td>
            <td class="text-right">{{ amountThousands(row.spending, 4) }}</td>
            <td class="text-right">{{ numberFormat(localRate, 8) }}</td>
            <td class="text-right">
              {{ amountThousands(row.usdTurnover, 4) }}
            </td>
            <td class="text-right">
              {{ amountThousands(row.usdSpending, 4) }}
            </td>
            <td class="text-right">{{ amountThousands(row.usdProfit, 4) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <template #footer>
      <template v-if="editable">
        <ElButton v-if="step === 1" type="success" @click="step = 2">
          {{ tl('next') }}
        </ElButton>
        <ElButton v-if="step === 2" @click="step = 1">
          {{ tl('previous') }}
        </ElButton>
        <ElButton
          v-if="step === 2"
          :loading="saving"
          type="success"
          @click="save"
        >
          {{ tl('next') }}
        </ElButton>
      </template>
    </template>
  </ElDialog>
</template>
