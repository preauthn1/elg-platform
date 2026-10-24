<script lang="ts" setup>
import type { CurrencyManageRow, ShareholderV3CurrencyRateRow } from '#/api';

import { computed, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getCurrencyManageListApi,
  getShareholderV3CurrencyRateListApi,
  setShareholderV3CurrencyRateApi,
} from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';
import { numberFormat } from '#/utils/format';

defineOptions({ name: 'ShareholderV3ExchangeRate' });

const PER_PAGE = 50;

// currency_rate/list 一次回全部、沒有 pagination，分頁在前端做
const loading = ref(false);
const rows = ref<ShareholderV3CurrencyRateRow[]>([]);
const { handlePageChange, page, pagedRows, total } = useClientTable(rows, {
  perPage: PER_PAGE,
});

async function loadData() {
  loading.value = true;
  try {
    const res = await getShareholderV3CurrencyRateListApi({});
    if (Number(res.code) === 1000) {
      rows.value = res.data ?? [];
    } else {
      rows.value = [];
      ElMessage.error(res.message);
    }
  } finally {
    loading.value = false;
  }
}

const title = computed(
  () => `${tl('menu_shareholder')} > ${tl('menu_shareholder_exchange_rateV3')}`,
);

const currencies = ref<CurrencyManageRow[]>([]);

/** 已設過匯率的幣別不重複新增（比對 currency_rate/list 的 currency 代碼） */
const availableCurrencies = computed(() => {
  const used = new Set(rows.value.map((r) => String(r.currency ?? '')));
  return currencies.value.filter((c) => !used.has(String(c.code ?? '')));
});

const dialogVisible = ref(false);
const saving = ref(false);
/** 有 currencyLabel 代表編輯（幣別固定），沒有代表新增 */
const editing = ref(false);
const form = ref({ currencyId: '' as number | string, rate: '' });

const selectedCode = computed(
  () =>
    currencies.value.find((c) => String(c.id) === String(form.value.currencyId))
      ?.code ?? '',
);
const editingCode = ref('');
const rateUnit = computed(() =>
  editing.value ? editingCode.value : selectedCode.value,
);

async function loadCurrencies() {
  currencies.value = (await getCurrencyManageListApi().catch(() => [])) ?? [];
}

function openCreate() {
  editing.value = false;
  editingCode.value = '';
  form.value = { currencyId: '', rate: '' };
  dialogVisible.value = true;
}

function openEdit(row: ShareholderV3CurrencyRateRow) {
  editing.value = true;
  editingCode.value = String(row.currency ?? '');
  form.value = {
    currencyId: row.currency_id,
    rate: String(row.exchange_rate_to_usd ?? ''),
  };
  dialogVisible.value = true;
}

async function save() {
  if (!editing.value && !form.value.currencyId) {
    ElMessage.warning(`${tl('currency')} ${tl('required')}`);
    return;
  }
  if (!form.value.rate || Number(form.value.rate) <= 0) {
    ElMessage.warning(tl('please_enter_reason'));
    return;
  }
  saving.value = true;
  try {
    const res = await setShareholderV3CurrencyRateApi({
      currency_id: form.value.currencyId,
      exchange_rate_to_usd: form.value.rate,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      dialogVisible.value = false;
      await loadData();
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}

loadCurrencies();
loadData();
</script>

<template>
  <Page :title="title">
    <ElCard shadow="never">
      <div class="mb-3">
        <ElButton type="success" @click="openCreate">
          {{ tl('add') }}
        </ElButton>
      </div>
      <ElTable v-loading="loading" :data="pagedRows" border size="small" stripe>
        <ElTableColumn :label="tl('currency')" min-width="120">
          <template #default="{ row }">
            <strong>{{ row.currency }}</strong>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('rate_to_usd')" align="right" min-width="160">
          <template #default="{ row }">
            {{ numberFormat(row.exchange_rate_to_usd, 6) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('updated_at')" align="center" min-width="160">
          <template #default="{ row }">
            <span class="text-muted-foreground text-xs">
              {{ row.updated_at || '-' }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('features')" align="center" width="120">
          <template #default="{ row }">
            <ElButton
              size="small"
              type="primary"
              @click="openEdit(row as ShareholderV3CurrencyRateRow)"
            >
              {{ tl('edit') }}
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

    <ElDialog
      v-model="dialogVisible"
      :title="
        editing
          ? `${tl('edit')} - ${editingCode} / USD`
          : `${tl('add')} ${tl('menu_shareholder_exchange_rateV3')}`
      "
      width="480px"
    >
      <ElForm label-width="auto" @submit.prevent="save">
        <ElFormItem v-if="!editing" :label="tl('currency')" required>
          <ElSelect v-model="form.currencyId" class="!w-64" clearable>
            <ElOption
              v-for="c in availableCurrencies"
              :key="c.id"
              :label="c.name || c.code"
              :value="c.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('rate_to_usd')" required>
          <div class="flex items-center gap-2">
            <span class="whitespace-nowrap">1</span>
            <strong class="min-w-10">{{ rateUnit || '-' }}</strong>
            <span>=</span>
            <ElInput
              v-model="form.rate"
              class="!w-40"
              min="0"
              step="0.000001"
              type="number"
              @keyup.enter="save"
            />
            <span>USD</span>
          </div>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="dialogVisible = false">{{ tl('cancel') }}</ElButton>
        <ElButton :loading="saving" type="primary" @click="save">
          {{ tl('confirm') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
