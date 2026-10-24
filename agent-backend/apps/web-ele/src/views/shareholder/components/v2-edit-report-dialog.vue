<script lang="ts" setup>
import type {
  ShareholderCategoryRow,
  ShareholderForeignSiteSales,
  ShareholderTotalSalesSummary,
} from '#/api';

import { computed, reactive, ref } from 'vue';

import dayjs from 'dayjs';
import {
  ElButton,
  ElDialog,
  ElInput,
  ElMessage,
  ElMessageBox,
} from 'element-plus';

import {
  getShareholderCategoryListApi,
  getShareholderCurrencyCodeApi,
  getShareholderExchangeRateApi,
  getShareholderForeignCategoryApi,
  getShareholderForeignTotalSalesApi,
  getShareholderTotalSalesApi,
  updateShareholderTotalSalesApi,
} from '#/api';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

defineOptions({ name: 'ShareholderV2EditReportDialog' });

const props = defineProps<{
  agentId: number | string;
  payoutRecordId: number | string;
  summaryDate: string;
}>();
const emit = defineEmits<{ success: [] }>();
const visible = defineModel<boolean>({ default: false });

/* 精度運算 */
const F = (n: number) => Math.floor(n);
const numMul = (a: number, b: number) =>
  F((F(a * 10_000) * (b * 10_000)) / 10_000) / 10_000;
const numDiv = (a: number, b: number) => (a * 10_000) / (b * 10_000);
const numSub = (a: number, b: number) => F(a * 10_000 - b * 10_000) / 10_000;
const toFixedFloor = (v: number, d: number) => {
  const f = 10 ** d;
  return Math.floor(v * f) / f;
};

type ForeignSummaryRow = NonNullable<
  ShareholderForeignSiteSales['summary']
>[number];

interface ForeignSite {
  byName: Map<string, ForeignSummaryRow>;
  currency_code: string;
  rate: number;
  site: string;
}

const loading = ref(false);
const saving = ref(false);
const step = ref(1);
const reportStatus = ref<number | string>(0);
const categories = ref<ShareholderCategoryRow[]>([]);
const localRate = ref(0);
const localCurrency = ref('');
const summaryByName = ref<Map<string, ShareholderTotalSalesSummary>>(new Map());
const foreignSites = ref<ForeignSite[]>([]);
const recordIdByCat = reactive<Record<string, number | string>>({});
const usdTurnover = reactive<Record<string, string>>({});
const usdSpending = reactive<Record<string, string>>({});

const apiDate = computed(() =>
  dayjs(`${props.summaryDate} 00:00:00`)
    .subtract(8, 'hour')
    .format('YYYY-MM-DD HH:mm:ss'),
);
const editable = computed(() => Number(reportStatus.value) === 3);

function close() {
  visible.value = false;
}

async function load() {
  loading.value = true;
  step.value = 1;
  try {
    const [
      catRes,
      foreignCatRes,
      rateRes,
      currencyRes,
      salesRes,
      foreignSalesRes,
    ] = await Promise.all([
      getShareholderCategoryListApi(),
      getShareholderForeignCategoryApi(),
      getShareholderExchangeRateApi(),
      getShareholderCurrencyCodeApi(),
      getShareholderTotalSalesApi(
        {
          date: apiDate.value,
          id: props.agentId,
          payout_record_id: props.payoutRecordId,
        },
        true,
      ),
      getShareholderForeignTotalSalesApi(apiDate.value),
    ]);

    const cats: ShareholderCategoryRow[] =
      Number(catRes.code) === 1000 ? (catRes.data ?? []) : [];
    categories.value = cats;

    localRate.value = Number(rateRes.data?.rate ?? 0);
    if (Number(rateRes.code) === 1000 && localRate.value <= 0) {
      ElMessageBox.alert('請檢查本站分桶匯率');
      close();
      return;
    }
    localCurrency.value =
      Number(currencyRes.code) === 1000 ? String(currencyRes.data ?? '') : '';

    reportStatus.value = salesRes.data?.status ?? 0;
    const summary: ShareholderTotalSalesSummary[] =
      Number(salesRes.code) === 1000 ? (salesRes.data?.summary ?? []) : [];
    const byName = new Map<string, ShareholderTotalSalesSummary>();
    for (const s of summary) if (s.name) byName.set(s.name, s);
    summaryByName.value = byName;

    // 外站：1001=無外站；1000 才比對名稱數量
    const sites: ForeignSite[] = [];
    const fData = foreignSalesRes.data;
    if (
      Number(foreignSalesRes.code) === 1000 &&
      fData &&
      !Array.isArray(fData)
    ) {
      for (const [site, row] of Object.entries(fData)) {
        const r = Number(row.rate ?? 0);
        if (r <= 0) {
          ElMessageBox.alert('請檢查分站分桶匯率');
          close();
          return;
        }
        const m = new Map<string, any>();
        for (const s2 of row.summary ?? []) if (s2.name) m.set(s2.name, s2);
        sites.push({
          site,
          rate: r,
          currency_code: row.currency_code ?? '',
          byName: m,
        });
      }
    }
    foreignSites.value = sites;

    if (Number(foreignCatRes.code) === 1000) {
      const localNames = [...cats.map((c) => c.name ?? '')].toSorted();
      const foreignNames: string[] = [];
      const fc = foreignCatRes.data;
      if (fc && typeof fc === 'object') {
        for (const arr of Object.values(fc as Record<string, any[]>)) {
          for (const c2 of arr ?? []) foreignNames.push(c2.name);
        }
      }
      foreignNames.sort();
      if (JSON.stringify(localNames) !== JSON.stringify(foreignNames)) {
        ElMessageBox.alert(
          '請檢查分站與本站的"分桶管理>類別管理"內容數量是否相同',
        );
        close();
        return;
      }
    }

    // 初始化每類別的 USD 輸入值（本站+外站轉 USD 加總）
    for (const c of cats) {
      const key = String(c.id);
      const s = byName.get(c.name ?? '');
      recordIdByCat[key] = s?.id ?? Number(c.id) * -1;
      let totTurnover = numMul(
        Number(s?.original_turnover ?? 0),
        localRate.value,
      );
      let totSpending = numMul(
        Number(s?.original_game_spending ?? 0),
        localRate.value,
      );
      for (const fs of sites) {
        const f = fs.byName.get(c.name ?? '');
        totTurnover += numMul(Number(f?.original_turnover ?? 0), fs.rate);
        totSpending += numMul(Number(f?.original_game_spending ?? 0), fs.rate);
      }
      usdTurnover[key] = String(totTurnover);
      usdSpending[key] = String(totSpending);
    }
  } finally {
    loading.value = false;
  }
}

/** step2 預覽（本站：USD ÷ 匯率還原平台幣 floor4）。 */
const previewRows = computed(() =>
  categories.value.map((c) => {
    const key = String(c.id);
    const s = summaryByName.value.get(c.name ?? '');
    let turnover: number;
    let spending: number;
    if (step.value === 2) {
      turnover = toFixedFloor(
        numDiv(Number(usdTurnover[key]) || 0, localRate.value || 1),
        4,
      );
      spending = toFixedFloor(
        numDiv(Number(usdSpending[key]) || 0, localRate.value || 1),
        4,
      );
    } else {
      turnover = Number(s?.turnover ?? 0);
      spending = Number(s?.game_spending ?? 0);
    }
    const profit = numSub(turnover, spending);
    const foreign = foreignSites.value.map((fs) => {
      let ft: number;
      let fsp: number;
      if (step.value === 2) {
        ft = toFixedFloor(
          numDiv(Number(usdTurnover[key]) || 0, fs.rate || 1),
          4,
        );
        fsp = toFixedFloor(
          numDiv(Number(usdSpending[key]) || 0, fs.rate || 1),
          4,
        );
      } else {
        const f = fs.byName.get(c.name ?? '');
        ft = Number(f?.turnover ?? 0);
        fsp = Number(f?.game_spending ?? 0);
      }
      return {
        site: example.invalid,
        currency_code: fs.currency_code,
        turnover: ft,
        game_spending: fsp,
        game_profit: numSub(ft, fsp),
      };
    });
    return {
      id: c.id,
      name: c.name ?? '',
      currency: localCurrency.value,
      turnover,
      spending,
      profit,
      foreign,
    };
  }),
);

/** step1 編輯（本站列 + 外站子列）。 */
const editRows = computed(() =>
  categories.value.map((c) => {
    const key = String(c.id);
    const s = summaryByName.value.get(c.name ?? '');
    const foreign = foreignSites.value.map((fs) => {
      const f = fs.byName.get(c.name ?? '');
      return {
        site: example.invalid,
        currency_code: fs.currency_code,
        turnover: Number(f?.original_turnover ?? 0),
        game_spending: Number(f?.original_game_spending ?? 0),
        rate: fs.rate,
      };
    });
    // USD 原始加總（顯示用）
    let origTurnover = numMul(
      Number(s?.original_turnover ?? 0),
      localRate.value,
    );
    let origSpending = numMul(
      Number(s?.original_game_spending ?? 0),
      localRate.value,
    );
    for (const fs of foreign) {
      origTurnover += numMul(fs.turnover, fs.rate);
      origSpending += numMul(fs.game_spending, fs.rate);
    }
    return {
      id: c.id,
      key,
      name: c.name ?? '',
      currency: localCurrency.value,
      local_turnover: Number(s?.original_turnover ?? 0),
      local_spending: Number(s?.original_game_spending ?? 0),
      rate: localRate.value,
      orig_usd_turnover: origTurnover,
      orig_usd_spending: origSpending,
      foreign,
      rowspan: 1 + foreign.length,
    };
  }),
);

async function save() {
  saving.value = true;
  try {
    const record = categories.value.map((c) => ({
      id: recordIdByCat[String(c.id)] ?? Number(c.id) * -1,
      turnover: usdTurnover[String(c.id)] || '0.0000',
      game_spending: usdSpending[String(c.id)] || '0.0000',
      category_name: c.name ?? '',
    }));
    const res = await updateShareholderTotalSalesApi(
      {
        payout_record_id: props.payoutRecordId,
        record,
        summary_date: apiDate.value,
      },
      true,
    );
    if (Number(res.code) === 1000) {
      close();
      // 存檔後派發狀態會變，通知父層重載列表（舊版是整頁 reload）
      emit('success');
    } else if (String(res.code) === '2002') {
      ElMessageBox.alert(String(res.data ?? ''));
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}

function onOpen(v: boolean) {
  if (v) load();
}
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('menu_cj7_shareholder_report')"
    width="1140px"
    @open="onOpen(true)"
  >
    <div v-loading="loading" class="overflow-auto">
      <!-- step1 編輯 -->
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
            <th>USD{{ tl('total_bet') }}</th>
            <th>USD{{ tl('total_lose') }}</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="row in editRows" :key="row.key">
            <tr>
              <td :rowspan="row.rowspan">{{ row.name }}</td>
              <td>{{ tl('local_site') }}</td>
              <td>{{ row.currency }}</td>
              <td class="text-right">
                {{ amountThousands(row.local_turnover, 4) }}
              </td>
              <td class="text-right">
                {{ amountThousands(row.local_spending, 4) }}
              </td>
              <td class="text-right">{{ row.rate }}</td>
              <td :rowspan="row.rowspan">
                <div class="mb-1 text-xs">
                  {{ tl('original_data') }}:{{
                    amountThousands(row.orig_usd_turnover, 4)
                  }}
                </div>
                <ElInput v-model="usdTurnover[row.key]" />
              </td>
              <td :rowspan="row.rowspan">
                <div class="mb-1 text-xs">
                  {{ tl('original_data') }}:{{
                    amountThousands(row.orig_usd_spending, 4)
                  }}
                </div>
                <ElInput v-model="usdSpending[row.key]" />
              </td>
            </tr>
            <tr v-for="f in row.foreign" :key="`${row.key}-${example.invalid}`">
              <td>{{ example.invalid }}</td>
              <td>{{ f.currency_code }}</td>
              <td class="text-right">{{ amountThousands(f.turnover, 4) }}</td>
              <td class="text-right">
                {{ amountThousands(f.game_spending, 4) }}
              </td>
              <td class="text-right">{{ f.rate }}</td>
            </tr>
          </template>
        </tbody>
      </table>

      <!-- step2 預覽 -->
      <table
        v-show="!editable || step === 2"
        class="w-full border-collapse border text-sm [&_td]:border [&_td]:p-2 [&_th]:border [&_th]:p-2"
      >
        <thead>
          <tr>
            <th>{{ tl('category') }}</th>
            <th v-show="editable">{{ tl('site') }}</th>
            <th v-show="editable">{{ tl('currency') }}</th>
            <th>{{ tl('total_bet') }}</th>
            <th>{{ tl('total_lose') }}</th>
            <th>{{ tl('net_profit') }}</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="row in previewRows" :key="row.id">
            <tr>
              <td :rowspan="editable ? 1 + row.foreign.length : 1">
                {{ row.name }}
              </td>
              <td v-show="editable">{{ tl('local_site') }}</td>
              <td v-show="editable">{{ row.currency }}</td>
              <td class="text-right">{{ amountThousands(row.turnover, 4) }}</td>
              <td class="text-right">{{ amountThousands(row.spending, 4) }}</td>
              <td class="text-right">{{ amountThousands(row.profit, 4) }}</td>
            </tr>
            <template v-if="editable">
              <tr v-for="f in row.foreign" :key="`${row.id}-${example.invalid}`">
                <td>{{ example.invalid }}</td>
                <td>{{ f.currency_code }}</td>
                <td class="text-right">{{ amountThousands(f.turnover, 4) }}</td>
                <td class="text-right">
                  {{ amountThousands(f.game_spending, 4) }}
                </td>
                <td class="text-right">
                  {{ amountThousands(f.game_profit, 4) }}
                </td>
              </tr>
            </template>
          </template>
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
