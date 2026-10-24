<script lang="ts" setup>
import type {
  AcfOrderTypeOption,
  AcfOrderTypeRow,
  AgentCashFlowOption,
  AgentCashFlowRow,
} from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';
import { Ellipsis } from '@vben/icons';

import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElDialog,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  agentCashFlowOrderTypeToggleStatusApi,
  agentCashFlowToggleStatusApi,
  deleteAgentCashFlowOrderTypeApi,
  getAcfOrderTypeListApi,
  getAgentOnlineDepositListApi,
  getCurrencyManageListApi,
  getLanguagesApi,
  updateAgentCashFlowApi,
  updateAgentCashFlowOrderTypeApi,
  updateDepositRateByCurrencyApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'AgentOnlineDepositSetting' });

const props = defineProps<{ embedGroupId?: string }>();

const route = useRoute();

const languages = ref<Array<{ code: string; id: number; name: string }>>([]);
const currencyList = ref<Array<{ code?: string; id: number | string }>>([]);

const groupId = ref<number | string>(
  props.embedGroupId || (route.query.group_id as string) || '',
);
const agentGroup = ref<{ id: number | string; name?: string }>({ id: '' });

const loading = ref(false);
const cashFlowList = ref<AgentCashFlowOption[]>([]);
const agentCashFlowList = ref<AgentCashFlowRow[]>([]);

// 篩選
const filters = reactive({
  backend_display: '1' as number | string,
  currency: '' as number | string,
  keyword: '',
  status: '' as number | string,
  type: '' as number | string,
});

const typeOptions = computed(() => {
  const set = new Set<number | string>();
  for (const r of agentCashFlowList.value) {
    if (r.type !== undefined && r.type !== null) set.add(r.type);
  }
  return [...set].toSorted();
});
const currencyOptions = computed(() => {
  const set = new Set<string>();
  for (const r of agentCashFlowList.value) {
    if (r.currency) set.add(r.currency);
  }
  return [...set].toSorted();
});

const filteredList = computed(() => {
  const kw = filters.keyword.trim().toLowerCase();
  return agentCashFlowList.value.filter((r) => {
    const typeMatch =
      filters.type === '' || String(r.type) === String(filters.type);
    const currencyMatch =
      filters.currency === '' ||
      String(r.currency ?? '') === String(filters.currency);
    const statusMatch =
      filters.status === '' || String(r.status) === String(filters.status);
    const bd = r.backend_display ?? 1;
    const backendMatch =
      filters.backend_display === '' ||
      String(bd) === String(filters.backend_display);
    const keywordMatch =
      kw === '' ||
      [
        r.name,
        r.agent_cash_flow_name,
        r.order_type_name,
        r.display_name,
        r.currency,
      ]
        .join(' ')
        .toLowerCase()
        .includes(kw);
    return (
      typeMatch && currencyMatch && statusMatch && backendMatch && keywordMatch
    );
  });
});

async function loadData() {
  if (!groupId.value) return;
  loading.value = true;
  try {
    const res = await getAgentOnlineDepositListApi(groupId.value);
    if (Number(res.code) !== 1000 || !res.data) {
      ElMessage.error(res.message || tl('failure'));
      agentCashFlowList.value = [];
      cashFlowList.value = [];
      return;
    }
    agentGroup.value = res.data.agent_group ?? { id: groupId.value };
    cashFlowList.value = res.data.cash_flow_list ?? [];
    agentCashFlowList.value = res.data.agent_cash_flow_list ?? [];
  } finally {
    loading.value = false;
  }
}

// ── 金流商編輯 modal ──────────────────────────────────
const cashFlowDialog = ref(false);
const cashFlowSaving = ref(false);
const cashFlowActiveLang = ref('en');
const cashFlowForm = reactive({
  agent_cash_flow_name: '',
  backend_display: '1' as number | string,
  cash_flow_id: '' as number | string,
  cost: '',
  display_name: {} as Record<string, string>,
  id: '' as number | string,
  info: '',
  is_recommend: false,
  order_type_id: '' as number | string,
  rate: '',
  sorts: '',
  tax: '',
  tax_percentage: '',
});

function resetCashFlowForm() {
  cashFlowForm.id = '';
  cashFlowForm.cash_flow_id = '';
  cashFlowForm.order_type_id = '';
  cashFlowForm.agent_cash_flow_name = '';
  cashFlowForm.rate = '';
  cashFlowForm.cost = '';
  cashFlowForm.sorts = '';
  cashFlowForm.tax = '';
  cashFlowForm.tax_percentage = '';
  cashFlowForm.backend_display = '1';
  cashFlowForm.is_recommend = false;
  example.invalid = '';
  cashFlowForm.display_name = {};
  cashFlowActiveLang.value = 'en';
}

function openAddCashFlow() {
  resetCashFlowForm();
  cashFlowDialog.value = true;
}

function openEditCashFlow(row: AgentCashFlowRow) {
  resetCashFlowForm();
  cashFlowForm.id = row.id;
  cashFlowForm.cash_flow_id = row.cash_flow_id ?? '';
  cashFlowForm.order_type_id = row.order_type_id ?? '';
  cashFlowForm.agent_cash_flow_name = row.agent_cash_flow_name ?? '';
  cashFlowForm.rate = String(row.rate ?? '');
  cashFlowForm.cost = String(row.cost ?? '');
  cashFlowForm.sorts = String(row.sorts ?? '');
  cashFlowForm.tax = String(row.tax ?? '');
  cashFlowForm.tax_percentage = String(row.tax_percentage ?? '');
  cashFlowForm.backend_display =
    row.backend_display === undefined || row.backend_display === null
      ? '1'
      : String(row.backend_display);
  cashFlowForm.is_recommend = Number(row.is_recommend) === 1;
  // frontend_display_name 由 order_type_names 帶入
  const names = row.order_type_names ?? {};
  for (const [k, v] of Object.entries(names)) cashFlowForm.display_name[k] = v;
  try {
    example.invalid = example.invalid
      ? JSON.stringify(JSON.parse(example.invalid), null, '\t')
      : '';
  } catch {
    example.invalid = '';
  }
  cashFlowDialog.value = true;
}

/** 同步多語：把目前語系的值套到其他空白語系 */
function syncDisplayName(langId: number | string) {
  const val = cashFlowForm.display_name[String(langId)] ?? '';
  if (val.trim() === '') {
    ElMessage.warning(tl('sync_multi_language_text'));
    return;
  }
  for (const l of languages.value) {
    const key = String(l.id);
    if (key !== String(langId) && !cashFlowForm.display_name[key]) {
      cashFlowForm.display_name[key] = val;
    }
  }
}

async function saveCashFlow() {
  cashFlowSaving.value = true;
  try {
    const res = await updateAgentCashFlowApi({
      agent_cash_flow_name: cashFlowForm.agent_cash_flow_name,
      backend_display: cashFlowForm.backend_display,
      cash_flow_id: cashFlowForm.cash_flow_id,
      cost: cashFlowForm.cost,
      display_name: cashFlowForm.display_name,
      group_id: groupId.value,
      id: cashFlowForm.id,
      info: example.invalid,
      is_recommend: cashFlowForm.is_recommend ? 1 : 0,
      order_type_id: cashFlowForm.order_type_id,
      rate: cashFlowForm.rate,
      sorts: cashFlowForm.sorts,
      tax: cashFlowForm.tax,
      tax_percentage: cashFlowForm.tax_percentage,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      cashFlowDialog.value = false;
      loadData();
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    cashFlowSaving.value = false;
  }
}

// ── 切換狀態 ──────────────────────────────────────────
async function toggleRowStatus(row: AgentCashFlowRow) {
  const next = Number(row.status) === 1 ? 0 : 1;
  try {
    await ElMessageBox.confirm(
      `${next === 1 ? tl('enable') : tl('disable')} ${row.name ?? ''}?`,
      tl('confirm'),
    );
  } catch {
    return;
  }
  const res =
    Number(row.type) === 1
      ? await agentCashFlowOrderTypeToggleStatusApi({
          id: row.agent_cash_flow_order_type_id ?? row.id,
          status: next,
        })
      : await agentCashFlowToggleStatusApi({ acf_id: row.id, status: next });
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    loadData();
  } else {
    ElMessage.error(res.message || tl('failure'));
  }
}

// ── 支付方式清單 modal ────────────────────────────────
const orderTypeListDialog = ref(false);
const orderTypeListLoading = ref(false);
const acfId = ref<number | string>('');
const acfOrderTypeList = ref<AcfOrderTypeRow[]>([]);
const orderTypeOptions = ref<AcfOrderTypeOption[]>([]);

async function openOrderTypeList(row: AgentCashFlowRow) {
  acfId.value = row.id;
  orderTypeListLoading.value = true;
  orderTypeListDialog.value = true;
  try {
    const res = await getAcfOrderTypeListApi({
      acf_id: row.id,
      group_id: groupId.value,
    });
    if (Number(res.code) === 1000 && res.data) {
      acfOrderTypeList.value = res.data.acf_order_type_list ?? [];
      orderTypeOptions.value = res.data.order_type_list ?? [];
    } else {
      acfOrderTypeList.value = [];
      orderTypeOptions.value = [];
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    orderTypeListLoading.value = false;
  }
}

async function reloadOrderTypeList() {
  const res = await getAcfOrderTypeListApi({
    acf_id: acfId.value,
    group_id: groupId.value,
  });
  if (Number(res.code) === 1000 && res.data) {
    acfOrderTypeList.value = res.data.acf_order_type_list ?? [];
    orderTypeOptions.value = res.data.order_type_list ?? [];
  }
}

async function toggleOrderTypeStatus(row: AcfOrderTypeRow) {
  const next = Number(row.status) === 1 ? 0 : 1;
  const res = await agentCashFlowOrderTypeToggleStatusApi({
    id: row.id,
    status: next,
  });
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    reloadOrderTypeList();
  } else {
    ElMessage.error(res.message || tl('failure'));
  }
}

async function deleteOrderType(row: AcfOrderTypeRow) {
  try {
    await ElMessageBox.confirm(
      `${tl('deposit_order_type_delete_message')} ID : ${row.id}`,
      tl('confirm'),
    );
  } catch {
    return;
  }
  const res = await deleteAgentCashFlowOrderTypeApi(row.id);
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    reloadOrderTypeList();
  } else {
    ElMessage.error(res.message || tl('failure'));
  }
}

// ── 支付方式編輯 modal ────────────────────────────────
const orderTypeDialog = ref(false);
const orderTypeSaving = ref(false);
const orderTypeActiveTab = ref('default');
const orderTypeForm = reactive({
  agent_cash_flow_id: '' as number | string,
  available_amount: '',
  id: '' as number | string,
  max_amount: '',
  min_amount: '',
  order_type_id: '' as number | string,
  // popup_text：default(0) + 各語系
  popup_text: {} as Record<string, string>,
  sorts: '',
});

function resetOrderTypeForm() {
  orderTypeForm.id = '';
  orderTypeForm.order_type_id = '';
  orderTypeForm.min_amount = '';
  orderTypeForm.max_amount = '';
  orderTypeForm.sorts = '';
  orderTypeForm.available_amount = '';
  orderTypeForm.popup_text = {};
  orderTypeActiveTab.value = 'default';
}

function openAddOrderType() {
  resetOrderTypeForm();
  orderTypeForm.agent_cash_flow_id = acfId.value;
  orderTypeDialog.value = true;
}

function openEditOrderType(row: AcfOrderTypeRow) {
  resetOrderTypeForm();
  orderTypeForm.agent_cash_flow_id = acfId.value;
  orderTypeForm.id = row.id;
  orderTypeForm.order_type_id = (row as any).order_type_id ?? '';
  orderTypeForm.min_amount = String(row.min_amount ?? '');
  orderTypeForm.max_amount = String(row.max_amount ?? '');
  orderTypeForm.sorts = String(row.sorts ?? '');
  orderTypeForm.available_amount = row.available_amount ?? '';
  try {
    const setting = row.settings ? JSON.parse(row.settings) : {};
    if (setting.PopupText) {
      for (const [k, v] of Object.entries(setting.PopupText)) {
        orderTypeForm.popup_text[k] = String(v ?? '');
      }
    }
  } catch {
    // ignore
  }
  orderTypeDialog.value = true;
}

async function saveOrderType() {
  orderTypeSaving.value = true;
  try {
    // []：default(0) + 各語系，依序成陣列
    const popupArr: string[] = [orderTypeForm.popup_text['0'] ?? ''];
    for (const l of languages.value) {
      popupArr.push(orderTypeForm.popup_text[String(l.id)] ?? '');
    }
    const res = await updateAgentCashFlowOrderTypeApi({
      agent_cash_flow_id: orderTypeForm.agent_cash_flow_id,
      available_amount: orderTypeForm.available_amount,
      id: orderTypeForm.id,
      max_amount: orderTypeForm.max_amount,
      min_amount: orderTypeForm.min_amount,
      order_type_id: orderTypeForm.order_type_id,
      popup_text: popupArr,
      sorts: orderTypeForm.sorts,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      orderTypeDialog.value = false;
      reloadOrderTypeList();
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    orderTypeSaving.value = false;
  }
}

// ── 幣別匯率管理 modal ────────────────────────────────
const currencyDialog = ref(false);
const currencySaving = ref(false);
const currencyForm = reactive({
  currency: '' as number | string,
  rate: '',
  status: '2' as number | string,
});

function openCurrencyManage() {
  currencyForm.currency = currencyList.value[0]?.id ?? '';
  currencyForm.rate = '';
  currencyForm.status = '2';
  currencyDialog.value = true;
}

async function saveCurrencyRate() {
  currencySaving.value = true;
  try {
    const res = await updateDepositRateByCurrencyApi({
      currency: currencyForm.currency,
      rate: currencyForm.rate,
      status: currencyForm.status,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      currencyDialog.value = false;
      loadData();
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    currencySaving.value = false;
  }
}

onMounted(async () => {
  const [langs, currencies] = await Promise.all([
    getLanguagesApi().catch(() => []),
    getCurrencyManageListApi().catch(() => []),
  ]);
  languages.value = (langs as any) ?? [];
  currencyList.value = (currencies as any) ?? [];
  await loadData();
});
</script>

<template>
  <Page
    :content-class="embedGroupId ? 'px-0' : ''"
    :title="`${tl('agnet_management')} > ${tl('menu_agent_group')} > ${tl('online_deposit_setting')}`"
  >
    <template #description>
      <b>{{ tl('name') }}</b
      >：{{ agentGroup.name }}
      <span class="text-muted-foreground">({{ groupId }})</span>
    </template>

    <ElCard class="mb-3" shadow="never">
      <div class="mb-3 flex gap-2">
        <ElButton type="success" @click="openAddCashFlow">
          {{ tl('add') }}
        </ElButton>
        <ElButton type="primary" @click="openCurrencyManage">
          {{ tl('currency_rate_manage') }}
        </ElButton>
      </div>
      <ElForm label-width="auto">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-4">
          <ElFormItem :label="tl('type')">
            <ElSelect v-model="filters.type" class="w-full" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption
                v-for="t in typeOptions"
                :key="t"
                :label="tl(`cash_flow_${t}`)"
                :value="t"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('currency')">
            <ElSelect v-model="filters.currency" class="w-full" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption
                v-for="c in currencyOptions"
                :key="c"
                :label="c"
                :value="c"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="w-full" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('enable')" value="1" />
              <ElOption :label="tl('disable')" value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('backend_display')">
            <ElSelect v-model="filters.backend_display" class="w-full">
              <ElOption :label="tl('show')" value="1" />
              <ElOption :label="tl('hidden')" value="0" />
            </ElSelect>
          </ElFormItem>
        </div>
      </ElForm>
      <div class="flex justify-end">
        <ElInput
          v-model="filters.keyword"
          :placeholder="tl('search')"
          class="!w-64"
          clearable
        />
      </div>
    </ElCard>

    <ElCard shadow="never">
      <ElTable
        v-loading="loading"
        :data="filteredList"
        border
        size="small"
        stripe
      >
        <ElTableColumn
          :label="tl('deposit_vendor_name')"
          min-width="160"
          prop="name"
          sortable
        >
          <template #default="{ row }">
            <div class="font-bold">{{ row.name }}</div>
            <div class="text-xs text-gray-400" title="cf_id,acf_id,ot_id">
              id:{{ row.cash_flow_id }}/{{ row.id }}/{{ row.order_type_id }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('backend_display_name')"
          min-width="150"
          prop="agent_cash_flow_name"
          sortable
        >
          <template #default="{ row }">
            <div>{{ row.agent_cash_flow_name }}</div>
            <div class="text-xs text-gray-400">{{ row.order_type_name }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('frontend_display_name')"
          min-width="140"
          prop="display_name"
          sortable
        />
        <ElTableColumn
          :label="tl('type')"
          align="center"
          min-width="110"
          prop="type"
          sortable
        >
          <template #default="{ row }">
            {{ tl(`cash_flow_${row.type}`) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('currency')"
          align="center"
          min-width="90"
          prop="currency"
          sortable
        />
        <ElTableColumn
          :label="tl('deposit_rate')"
          align="right"
          min-width="90"
          prop="rate"
          sortable
        />
        <ElTableColumn
          :label="tl('access_fee')"
          align="right"
          min-width="90"
          prop="cost"
          sortable
        >
          <template #default="{ row }">{{ row.cost }}%</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('sorts')"
          align="right"
          min-width="70"
          prop="sorts"
          sortable
        />
        <ElTableColumn
          :label="tl('status')"
          align="center"
          prop="status"
          sortable
          width="90"
        >
          <template #default="{ row }">
            <b :class="{ 'text-red-500': Number(row.status) !== 1 }">
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </b>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" fixed="right" width="90">
          <template #default="{ row }">
            <ElDropdown>
              <ElButton size="small">
                <Ellipsis class="size-4" />
              </ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem
                    @click="openEditCashFlow(row as AgentCashFlowRow)"
                  >
                    {{ tl('edit') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    @click="toggleRowStatus(row as AgentCashFlowRow)"
                  >
                    {{
                      Number(row.status) === 1 ? tl('disable') : tl('enable')
                    }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    @click="openOrderTypeList(row as AgentCashFlowRow)"
                  >
                    {{ tl('deposit_order_type_setting') }}
                  </ElDropdownItem>
                </ElDropdownMenu>
              </template>
            </ElDropdown>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>

    <!-- 金流商編輯 -->
    <ElDialog
      v-model="cashFlowDialog"
      :title="`${tl('edit')} ${tl('online_deposit_setting')}`"
      width="760px"
    >
      <ElForm label-position="top">
        <ElFormItem :label="tl('deposit_vendor_name')">
          <ElSelect
            v-model="cashFlowForm.cash_flow_id"
            :disabled="!!cashFlowForm.id"
            class="w-full"
            filterable
          >
            <ElOption
              v-for="cf in cashFlowList"
              :key="cf.id"
              :label="cf.name"
              :value="cf.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('backend_display_name')">
          <ElInput v-model="cashFlowForm.agent_cash_flow_name" />
        </ElFormItem>
        <ElFormItem :label="tl('frontend_display_name')">
          <ElTabs v-model="cashFlowActiveLang" class="w-full">
            <ElTabPane
              v-for="l in languages"
              :key="l.id"
              :label="l.name"
              :name="l.code"
            >
              <div class="flex gap-2">
                <ElInput
                  v-model="cashFlowForm.display_name[String(l.id)]"
                  :placeholder="l.name"
                />
                <ElButton type="primary" @click="syncDisplayName(l.id)">
                  {{ tl('sync_multi_language_text') }}
                </ElButton>
              </div>
            </ElTabPane>
          </ElTabs>
        </ElFormItem>
        <div class="grid grid-cols-3 gap-x-4">
          <ElFormItem :label="tl('deposit_rate')">
            <ElInput v-model="cashFlowForm.rate" class="text-right" />
          </ElFormItem>
          <ElFormItem :label="tl('access_fee')">
            <ElInput v-model="cashFlowForm.cost">
              <template #append>%</template>
            </ElInput>
          </ElFormItem>
          <ElFormItem :label="tl('sorts')">
            <ElInput v-model="cashFlowForm.sorts" class="text-right" />
          </ElFormItem>
          <ElFormItem :label="tl('handling_fee')">
            <ElInput v-model="cashFlowForm.tax" class="text-right" />
          </ElFormItem>
          <ElFormItem
            :label="`${tl('handling_fee')} (${tl('tax_type_percentage')})`"
          >
            <ElInput v-model="cashFlowForm.tax_percentage">
              <template #append>%</template>
            </ElInput>
          </ElFormItem>
          <ElFormItem :label="tl('backend_display')">
            <ElSelect v-model="cashFlowForm.backend_display" class="w-full">
              <ElOption :label="tl('show')" value="1" />
              <ElOption :label="tl('hidden')" value="0" />
            </ElSelect>
          </ElFormItem>
        </div>
        <ElFormItem>
          <ElCheckbox v-model="cashFlowForm.is_recommend">
            {{ tl('recommend') }}
          </ElCheckbox>
        </ElFormItem>
        <ElFormItem :label="tl('deposit_vendor_info')">
          <ElInput
            v-model="example.invalid"
            :rows="3"
            readonly
            type="textarea"
          />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="cashFlowDialog = false">{{ tl('cancel') }}</ElButton>
        <ElButton
          :loading="cashFlowSaving"
          type="primary"
          @click="saveCashFlow"
        >
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>

    <!-- 支付方式清單 -->
    <ElDialog
      v-model="orderTypeListDialog"
      :title="tl('deposit_order_type_setting')"
      fullscreen
    >
      <div class="mb-3">
        <ElButton type="success" @click="openAddOrderType">
          {{ tl('add') }}
        </ElButton>
      </div>
      <ElTable
        v-loading="orderTypeListLoading"
        :data="acfOrderTypeList"
        border
        size="small"
        stripe
      >
        <ElTableColumn label="ID" prop="id" width="70" />
        <ElTableColumn :label="tl('deposit_order_type_name')" prop="name" />
        <ElTableColumn
          :label="tl('deposit_order_type_min_amount')"
          prop="min_amount"
        />
        <ElTableColumn
          :label="tl('deposit_order_type_max_amount')"
          prop="max_amount"
        />
        <ElTableColumn
          :label="tl('deposit_order_type_available_amount')"
          prop="available_amount"
        />
        <ElTableColumn :label="tl('sorts')" prop="sorts" />
        <ElTableColumn :label="tl('status')" align="center" width="110">
          <template #default="{ row }">
            <ElButton
              :type="Number(row.status) === 1 ? 'success' : 'info'"
              link
              @click="toggleOrderTypeStatus(row as AcfOrderTypeRow)"
            >
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="150">
          <template #default="{ row }">
            <ElButton
              size="small"
              type="primary"
              @click="openEditOrderType(row as AcfOrderTypeRow)"
            >
              {{ tl('edit') }}
            </ElButton>
            <ElButton
              size="small"
              type="danger"
              @click="deleteOrderType(row as AcfOrderTypeRow)"
            >
              {{ tl('delete') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElDialog>

    <!-- 支付方式編輯 -->
    <ElDialog
      v-model="orderTypeDialog"
      :title="`${tl('edit')} ${tl('deposit_order_type_setting')}`"
      width="760px"
    >
      <ElForm label-position="top">
        <ElFormItem :label="tl('deposit_order_type_name')">
          <ElSelect
            v-model="orderTypeForm.order_type_id"
            :disabled="!!orderTypeForm.id"
            class="w-full"
            filterable
          >
            <ElOption
              v-for="ot in orderTypeOptions"
              :key="ot.id"
              :label="ot.name"
              :value="ot.id"
            />
          </ElSelect>
        </ElFormItem>
        <div class="grid grid-cols-3 gap-x-4">
          <ElFormItem :label="tl('deposit_order_type_min_amount')">
            <ElInput v-model="orderTypeForm.min_amount" />
          </ElFormItem>
          <ElFormItem :label="tl('deposit_order_type_max_amount')">
            <ElInput v-model="orderTypeForm.max_amount" />
          </ElFormItem>
          <ElFormItem :label="tl('sorts')">
            <ElInput v-model="orderTypeForm.sorts" />
          </ElFormItem>
        </div>
        <ElFormItem :label="tl('deposit_order_type_available_amount')">
          <ElInput
            v-model="orderTypeForm.available_amount"
            :rows="3"
            type="textarea"
          />
        </ElFormItem>
        <ElFormItem :label="tl('popup_text')">
          <ElTabs v-model="orderTypeActiveTab" class="w-full">
            <ElTabPane :label="tl('default')" name="default">
              <ElInput
                v-model="orderTypeForm.popup_text['0']"
                :rows="10"
                type="textarea"
              />
            </ElTabPane>
            <ElTabPane
              v-for="l in languages"
              :key="l.id"
              :label="l.name"
              :name="`lang-${l.id}`"
            >
              <ElInput
                v-model="orderTypeForm.popup_text[String(l.id)]"
                :rows="10"
                type="textarea"
              />
            </ElTabPane>
          </ElTabs>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="orderTypeDialog = false">{{ tl('cancel') }}</ElButton>
        <ElButton
          :loading="orderTypeSaving"
          type="primary"
          @click="saveOrderType"
        >
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>

    <!-- 幣別匯率管理 -->
    <ElDialog
      v-model="currencyDialog"
      :title="tl('currency_rate_manage')"
      width="640px"
    >
      <ElForm label-position="top">
        <div class="grid grid-cols-2 gap-x-4">
          <ElFormItem :label="tl('currency')">
            <ElSelect v-model="currencyForm.currency" class="w-full" filterable>
              <ElOption
                v-for="c in currencyList"
                :key="c.id"
                :label="c.code"
                :value="c.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="`${tl('deposit_rate')} (${tl('bulk_info')})`">
            <ElInput
              v-model="currencyForm.rate"
              :placeholder="tl('deposit_rate')"
            />
          </ElFormItem>
        </div>
        <ElFormItem :label="tl('status')">
          <ElRadioGroup v-model="currencyForm.status">
            <ElRadio value="2">{{ tl('no_adjustment') }}</ElRadio>
            <ElRadio value="1">{{ tl('bulk') }}{{ tl('enable') }}</ElRadio>
            <ElRadio value="0">{{ tl('bulk') }}{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="currencyDialog = false">{{ tl('cancel') }}</ElButton>
        <ElButton
          :loading="currencySaving"
          type="primary"
          @click="saveCurrencyRate"
        >
          {{ tl('submit') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
