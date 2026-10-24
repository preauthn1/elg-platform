<script lang="ts" setup>
import type { BankEditLogRow, BankManagementRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getBankEditLogApi,
  getBankManagementListApi,
  getCustomizedSettingApi,
  toggleBankAccountStatusApi,
  updateBankRateByCurrencyApi,
} from '#/api';
// getCurrencyListApi 直接從來源模組 import：避開 report.ts 與 currency.ts(s1)
// 對 getCurrencyListApi 的重複 star export（barrel 執行期 conflicting star exports）。
import { getCurrencyListApi } from '#/api/report';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';
import { hasMenuCode } from '#/router/agent-menu';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands } from '#/utils/format';

import BankAccountEditDialog from './components/bank-account-edit-dialog.vue';

defineOptions({ name: 'BankManagementIndex' });

const router = useRouter();
const editRef = ref<InstanceType<typeof BankAccountEditDialog>>();
/** 4545：新增銀行帳號改由獨立權限節點控制，後端 bank/addAccount 也會檢查同一把鍵 */
const canCreateAccount = hasMenuCode('bank_account_create_permission');
const PAGE_SIZES = [10, 25, 50, 100];
const pageSize = ref(10);

const loading = ref(false);
const rows = ref<BankManagementRow[]>([]);

/** customizedSetting 4011 開啟 BSB 欄、3015 BSB 欄位別名 */
const enableBsb = ref(false);
const bsbAlias = ref('BSB Code');

const currencies = ref<
  Array<{ code?: string; id: number | string; name?: string }>
>([]);

const {
  handlePageChange,
  handleSearch,
  handleSortChange,
  keyword,
  page,
  pagedRows,
  total,
} = useClientTable(rows, {
  perPage: pageSize,
  searchFields: (r) => [
    r.account_nickname,
    r.bank_name,
    r.bank_code,
    r.account_name,
    r.account_no,
  ],
});

function handleSizeChange(size: number) {
  pageSize.value = size;
  page.value = 1;
}

const fmt2 = (v?: null | number | string) => amountThousands(v, 2);

async function loadData() {
  loading.value = true;
  try {
    const res = await getBankManagementListApi();
    rows.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
  } finally {
    loading.value = false;
  }
}

async function loadSettings() {
  const list = await getCustomizedSettingApi().catch(() => []);
  const map = new Map(
    (list ?? []).map((s: any) => [Number(s.setting_key), s.value]),
  );
  enableBsb.value = Number(map.get(4011) ?? 0) === 1;
  const alias = map.get(3015);
  if (alias) bsbAlias.value = String(alias);
}

/* ---- 啟用/停用 ---- */
async function toggleStatus(row: BankManagementRow) {
  const next = Number(row.status) === 1 ? 0 : 1;
  const res = await toggleBankAccountStatusApi({
    id: row.account_id ?? '',
    status: next,
  });
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    loadData();
  } else {
    ElMessage.error(res.message || tl('failure'));
  }
}

/**
 * 4545：戶口建立後不再開放編輯（後端 bank/editAccount 一律拒絕），這裡只用於新增。
 * 要改戶口資料請新增一筆、停用舊的。
 */
function goCreate() {
  editRef.value?.open();
}

const exporting = ref(false);
/** 匯出 Excel：欄位（9 欄；狀態沿用畫面 ===1 啟用） */
async function handleExport() {
  exporting.value = true;
  try {
    await exportExcel(
      `bank_account_management_${Date.now()}`,
      [
        tl('account_code'),
        tl('bank_name'),
        tl('bank_code'),
        tl('account_name'),
        tl('account_no'),
        tl('min_amount'),
        tl('max_amount'),
        tl('account_amount'),
        tl('status'),
      ],
      rows.value.map((r) => {
        const rr = r as BankManagementRow & Record<string, unknown>;
        return [
          r.account_nickname,
          r.bank_name,
          r.bank_code,
          r.account_name,
          r.account_no,
          r.min_amount,
          r.max_amount,
          (rr.account_amount as number | string) ?? r.total_amount,
          Number(r.status) === 1 ? tl('enable') : tl('disable'),
        ];
      }),
      { title: tl('menu_bank_account_management') },
    );
  } finally {
    exporting.value = false;
  }
}

function goBankList() {
  router.push('/bank_management/bank_list');
}

/* ---- 貨幣匯率管理 modal ---- */
const rateDialog = reactive({
  currency: '' as number | string,
  rate: '',
  status: 2 as number | string,
  submitting: false,
  visible: false,
});

function openRateDialog() {
  rateDialog.currency = currencies.value[0]?.id ?? '';
  rateDialog.rate = '';
  rateDialog.status = 2;
  rateDialog.visible = true;
}

async function submitRate() {
  rateDialog.submitting = true;
  try {
    const res = await updateBankRateByCurrencyApi({
      currency: rateDialog.currency,
      rate: rateDialog.rate,
      status: rateDialog.status,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      rateDialog.visible = false;
      loadData();
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    rateDialog.submitting = false;
  }
}

/* ---- 編輯紀錄 dialog ---- */
const logDialog = reactive({
  accountId: '' as number | string,
  page: 1,
  total: 0,
  visible: false,
});
const logRows = ref<BankEditLogRow[]>([]);
const logLoading = ref(false);

async function loadLog() {
  logLoading.value = true;
  try {
    const res = await getBankEditLogApi({
      id: logDialog.accountId,
      page: logDialog.page,
      per_page: 10,
    });
    if (Number(res.code) === 1000) {
      logRows.value = res.data ?? [];
      logDialog.total = res.pagination?.total ?? 0;
    } else {
      logRows.value = [];
      logDialog.total = 0;
    }
  } finally {
    logLoading.value = false;
  }
}

function openLog(row: BankManagementRow) {
  logDialog.accountId = row.account_id ?? '';
  logDialog.page = 1;
  logDialog.visible = true;
  loadLog();
}

function handleLogPage(p: number) {
  logDialog.page = p;
  loadLog();
}

onMounted(() => {
  loadData();
  loadSettings();
  getCurrencyListApi()
    .then((d) => {
      currencies.value = d ?? [];
    })
    .catch(() => {});
});
</script>

<template>
  <Page
    :title="`${tl('menu_auto_deposit')} > ${tl('menu_bank_account_management')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <div class="flex flex-wrap items-center gap-2">
        <ElButton type="success" @click="goBankList">
          {{ tl('bank_management') }}
        </ElButton>
        <ElButton v-if="canCreateAccount" type="warning" @click="goCreate()">
          {{ tl('add_bank_account') }}
        </ElButton>
        <ElButton type="info" @click="openRateDialog">
          {{ tl('currency_rate_manage') }}
        </ElButton>
        <ElButton :loading="exporting" type="success" @click="handleExport">
          {{ tl('excel') }}
        </ElButton>
        <div class="ml-auto">
          <ElInput
            v-model="keyword"
            :placeholder="tl('search')"
            clearable
            class="!w-60"
            @input="handleSearch"
          />
        </div>
      </div>
    </ElCard>

    <ElCard shadow="never">
      <ElTable
        v-loading="loading"
        :data="pagedRows"
        border
        size="small"
        @sort-change="handleSortChange"
      >
        <ElTableColumn
          :label="tl('account_code')"
          prop="account_nickname"
          min-width="120"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('bank_name')"
          prop="bank_name"
          min-width="120"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('bank_code')"
          prop="bank_code"
          min-width="100"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('account_name')"
          prop="account_name"
          min-width="120"
          sortable="custom"
        />
        <ElTableColumn
          :label="
            enableBsb ? `${bsbAlias} / ${tl('account_no')}` : tl('account_no')
          "
          min-width="140"
          prop="account_no"
          sortable="custom"
        >
          <template #default="{ row }">
            <div v-if="enableBsb && (row as BankManagementRow).bsb_code">
              {{ (row as BankManagementRow).bsb_code }}
            </div>
            <div>{{ (row as BankManagementRow).account_no }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('total_amount')"
          align="right"
          min-width="120"
          prop="total_amount"
          sortable="custom"
        >
          <template #default="{ row }">
            {{ fmt2((row as BankManagementRow).total_amount) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('total_count_2')"
          align="right"
          prop="total_count"
          min-width="90"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('min_amount')"
          align="right"
          prop="min_amount"
          min-width="100"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('max_amount')"
          align="right"
          prop="max_amount"
          min-width="100"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('currency')"
          prop="currency"
          min-width="90"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('rate')"
          prop="rate"
          min-width="80"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('status')"
          min-width="100"
          prop="status"
          sortable="custom"
        >
          <template #default="{ row }">
            <div
              :class="
                Number((row as BankManagementRow).status) === 1
                  ? ''
                  : 'text-red-500'
              "
            >
              {{
                Number((row as BankManagementRow).status) === 1
                  ? tl('enable')
                  : tl('disable')
              }}
            </div>
            <div
              v-if="Number((row as BankManagementRow).is_reached) === 1"
              class="text-red-500"
            >
              {{ tl('reached_target') }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('operate')"
          align="center"
          width="90"
          fixed="right"
        >
          <template #default="{ row }">
            <ElDropdown trigger="click">
              <ElButton size="small">
                <i class="ti-angle-down"></i>···
              </ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem @click="openLog(row as BankManagementRow)">
                    {{ tl('account_edit_log') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    @click="toggleStatus(row as BankManagementRow)"
                  >
                    {{
                      Number((row as BankManagementRow).status) === 1
                        ? tl('disable')
                        : tl('enable')
                    }}
                  </ElDropdownItem>
                </ElDropdownMenu>
              </template>
            </ElDropdown>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="pageSize"
        :page-sizes="PAGE_SIZES"
        :total="total"
        layout="total, sizes, prev, pager, next, jumper"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </ElCard>

    <!-- 貨幣匯率管理 -->
    <ElDialog
      v-model="rateDialog.visible"
      :title="tl('currency_rate_manage')"
      width="560px"
    >
      <ElForm label-width="auto">
        <ElFormItem :label="tl('currency')">
          <ElSelect v-model="rateDialog.currency" class="!w-full">
            <ElOption
              v-for="c in currencies"
              :key="c.id"
              :label="c.code ?? c.name"
              :value="c.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="`${tl('rate')} (${tl('bulk_info')})`">
          <ElInput v-model="rateDialog.rate" :placeholder="tl('rate')" />
        </ElFormItem>
        <ElFormItem :label="tl('status')">
          <ElRadioGroup v-model="rateDialog.status">
            <ElRadio :value="2">{{ tl('no_adjustment') }}</ElRadio>
            <ElRadio :value="1">{{ tl('bulk') }}{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('bulk') }}{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="rateDialog.visible = false">
          {{ tl('cancel') }}
        </ElButton>
        <ElButton
          :loading="rateDialog.submitting"
          type="primary"
          @click="submitRate"
        >
          {{ tl('submit') }}
        </ElButton>
      </template>
    </ElDialog>

    <!-- 戶口編輯紀錄 -->
    <ElDialog
      v-model="logDialog.visible"
      :title="tl('account_edit_log')"
      width="720px"
    >
      <ElTable v-loading="logLoading" :data="logRows" border size="small">
        <ElTableColumn :label="tl('date')" prop="created_at" align="center" />
        <ElTableColumn
          :label="tl('before_amount')"
          prop="before_amount"
          align="center"
        />
        <ElTableColumn
          :label="tl('after_amount')"
          prop="after_amount"
          align="center"
        />
        <ElTableColumn :label="tl('operator')" prop="operator" align="center">
          <template #default="{ row }">
            <AgentAccount :username="row.operator" />
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <TablePagination
        :current-page="logDialog.page"
        :page-size="10"
        :total="logDialog.total"
        layout="total, prev, pager, next"
        @current-change="handleLogPage"
      />
    </ElDialog>

    <BankAccountEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
