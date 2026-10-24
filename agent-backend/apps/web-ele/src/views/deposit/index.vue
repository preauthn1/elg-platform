<script lang="ts" setup>
import type { CashFlowOrderTypeRow, DepositCashFlowRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

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
  ElSelect,
  ElSwitch,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  cashFlowOrderTypeToggleStatusApi,
  cashFlowToggleStatusApi,
  getCashFlowApi,
  getCashFlowOrderTypeNameApi,
  getDepositOrderTypeListApi,
  updateCashFlowApi,
  updateCashFlowOrderTypeApi,
  updateCashFlowOrderTypeNameApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'DepositIndex' });

const loading = ref(false);
const cashFlows = ref<DepositCashFlowRow[]>([]);
const languages = ref<Array<{ id: number | string; name: string }>>([]);

// 客端搜尋（比對 ID / 名稱 / 類型文字）
const keyword = ref('');
const filteredCashFlows = computed(() => {
  const k = keyword.value.trim().toLowerCase();
  if (!k) return cashFlows.value;
  return cashFlows.value.filter((r) => {
    const typeText = Number(r.type) === 1 ? tl('onlinepay') : tl('offlinepay');
    return (
      String(r.id).includes(k) ||
      String(r.name ?? '')
        .toLowerCase()
        .includes(k) ||
      typeText.toLowerCase().includes(k)
    );
  });
});

async function loadData() {
  loading.value = true;
  try {
    const data = await getCashFlowApi();
    cashFlows.value = data?.cash_flow_list ?? [];
    languages.value = data?.language_list ?? [];
  } finally {
    loading.value = false;
  }
}

async function toggleCashFlow(
  row: DepositCashFlowRow,
  next: boolean | number | string,
) {
  const res = await cashFlowToggleStatusApi({
    cf_id: row.id,
    status: next ? 1 : 0,
  });
  if (Number(res.code) === 1000) {
    row.status = res.data?.status ?? (next ? 1 : 0);
    ElMessage.success(tl('success'));
  } else {
    ElMessage.error(res.message);
  }
}

// ---------- 編輯支付商戶 ----------
const cfVisible = ref(false);
const cfSaving = ref(false);
const cfForm = reactive({
  id: '' as number | string,
  name: '',
  type: 1 as number | string,
});

function openCashFlow(row?: DepositCashFlowRow) {
  cfForm.id = row?.id ?? '';
  cfForm.name = row?.name ?? '';
  cfForm.type = Number(row?.type) || 1;
  cfVisible.value = true;
}

async function saveCashFlow() {
  cfSaving.value = true;
  try {
    const res = await updateCashFlowApi({
      id: cfForm.id,
      name: cfForm.name,
      type: cfForm.type,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      cfVisible.value = false;
      loadData();
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    cfSaving.value = false;
  }
}

// ---------- 支付方式清單 modal ----------
const otListVisible = ref(false);
const otListLoading = ref(false);
const otCashFlowId = ref<number | string>('');
const orderTypes = ref<CashFlowOrderTypeRow[]>([]);

async function openOrderTypeList(row: DepositCashFlowRow) {
  otCashFlowId.value = row.id;
  otListVisible.value = true;
  otListLoading.value = true;
  try {
    const res = await getDepositOrderTypeListApi(row.id);
    orderTypes.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
    if (Number(res.code) !== 1000) ElMessage.error(res.message);
  } finally {
    otListLoading.value = false;
  }
}

async function toggleOrderType(
  row: CashFlowOrderTypeRow,
  next: boolean | number | string,
) {
  const res = await cashFlowOrderTypeToggleStatusApi({
    id: row.id,
    status: next ? 1 : 0,
  });
  if (Number(res.code) === 1000) {
    row.status = res.data?.status ?? (next ? 1 : 0);
    ElMessage.success(tl('success'));
  } else {
    ElMessage.error(res.message);
  }
}

// ---------- 編輯支付方式 ----------
const otVisible = ref(false);
const otSaving = ref(false);
const otForm = reactive({
  cash_flow_id: '' as number | string,
  id: '' as number | string,
  key: '',
  name: '',
  sorts: '' as number | string,
});

function openOrderType(row?: CashFlowOrderTypeRow) {
  otForm.id = row?.id ?? '';
  otForm.cash_flow_id = otCashFlowId.value;
  otForm.name = row?.name ?? '';
  otForm.key = row?.key ?? '';
  otForm.sorts = row?.sorts ?? '';
  otVisible.value = true;
}

async function saveOrderType() {
  otSaving.value = true;
  try {
    const res = await updateCashFlowOrderTypeApi({
      cash_flow_id: otForm.cash_flow_id,
      id: otForm.id,
      key: otForm.key,
      name: otForm.name,
      sorts: otForm.sorts,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      orderTypes.value =
        res.data?.cash_flow_order_type_list ?? orderTypes.value;
      otVisible.value = false;
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    otSaving.value = false;
  }
}

// ---------- 支付方式多語系名稱 ----------
const otNameVisible = ref(false);
const otNameSaving = ref(false);
const otNameId = ref<number | string>('');
const otNameBase = ref('');
const otNameValues = reactive<Record<string, string>>({});

async function openOrderTypeName(row: CashFlowOrderTypeRow) {
  for (const k of Object.keys(otNameValues)) delete otNameValues[k];
  for (const l of languages.value) otNameValues[String(l.id)] = '';
  otNameVisible.value = true;
  const res = await getCashFlowOrderTypeNameApi(row.id);
  if (Number(res.code) === 1000) {
    otNameId.value = res.data?.order_type?.id ?? row.id;
    otNameBase.value = res.data?.order_type?.name ?? '';
    for (const item of res.data?.order_type_name_list ?? []) {
      otNameValues[String(item.language_id)] = item.name ?? '';
    }
  } else {
    ElMessage.error(res.message);
  }
}

async function saveOrderTypeName() {
  otNameSaving.value = true;
  try {
    const res = await updateCashFlowOrderTypeNameApi({
      id: otNameId.value,
      name: { ...otNameValues },
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      otNameVisible.value = false;
      loadData();
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    otNameSaving.value = false;
  }
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('menu_system_management')} > ${tl('menu_deposit_vendor_management')}`"
  >
    <div class="mb-3">
      <ElButton type="success" @click="openCashFlow()">
        {{ tl('add') }}
      </ElButton>
    </div>

    <ElCard shadow="never">
      <div class="mb-3 flex justify-end">
        <ElInput
          v-model="keyword"
          :placeholder="tl('search')"
          class="!w-60"
          clearable
        />
      </div>
      <ElTable
        v-loading="loading"
        :data="filteredCashFlows"
        border
        size="small"
        stripe
      >
        <ElTableColumn :label="tl('id')" prop="id" sortable width="100" />
        <ElTableColumn
          :label="tl('deposit_vendor_name')"
          min-width="160"
          prop="name"
          sortable
        >
          <template #default="{ row }">
            <span class="text-info">{{ row.name }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('type')" prop="type" sortable width="150">
          <template #default="{ row }">
            {{ Number(row.type) === 1 ? tl('onlinepay') : tl('offlinepay') }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" prop="status" sortable width="120">
          <template #default="{ row }">
            <ElSwitch
              :model-value="Number(row.status) === 1"
              @change="(v) => toggleCashFlow(row as DepositCashFlowRow, v)"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="90" fixed="right">
          <template #default="{ row }">
            <ElDropdown trigger="click">
              <ElButton size="small">···</ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem
                    @click="openCashFlow(row as DepositCashFlowRow)"
                  >
                    {{ tl('edit') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    v-if="Number(row.id) !== 5 && Number(row.id) !== 6"
                    @click="openOrderTypeList(row as DepositCashFlowRow)"
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

    <!-- 編輯支付商戶 -->
    <ElDialog
      v-model="cfVisible"
      :title="`${tl('edit')} ${tl('online_deposit_setting')}`"
      width="560px"
    >
      <ElForm label-width="120px">
        <ElFormItem :label="tl('deposit_vendor_name')">
          <ElInput v-model="cfForm.name" />
        </ElFormItem>
        <ElFormItem :label="tl('type')">
          <ElSelect v-model="cfForm.type" class="w-full">
            <ElOption :label="tl('onlinepay')" :value="1" />
            <ElOption :label="tl('offlinepay')" :value="2" />
          </ElSelect>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="cfVisible = false">{{ tl('cancel') }}</ElButton>
        <ElButton :loading="cfSaving" type="primary" @click="saveCashFlow">
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>

    <!-- 支付方式清單 -->
    <ElDialog
      v-model="otListVisible"
      :title="tl('deposit_order_type_setting')"
      width="900px"
    >
      <div class="mb-3">
        <ElButton type="success" @click="openOrderType()">
          {{ tl('add') }}
        </ElButton>
      </div>
      <ElTable
        v-loading="otListLoading"
        :data="orderTypes"
        border
        size="small"
        stripe
      >
        <ElTableColumn label="ID" prop="id" width="80" />
        <ElTableColumn
          :label="tl('deposit_order_type_name')"
          prop="name"
          min-width="120"
        />
        <ElTableColumn
          :label="tl('deposit_vendor_name')"
          prop="cash_flow_name"
          min-width="120"
        />
        <ElTableColumn
          :label="tl('deposit_order_type_key')"
          prop="key"
          min-width="110"
        />
        <ElTableColumn :label="tl('sorts')" prop="sorts" width="80" />
        <ElTableColumn :label="tl('status')" width="90">
          <template #default="{ row }">
            <ElSwitch
              :model-value="Number(row.status) === 1"
              @change="(v) => toggleOrderType(row as CashFlowOrderTypeRow, v)"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="150">
          <template #default="{ row }">
            <ElButton
              size="small"
              type="primary"
              @click="openOrderType(row as CashFlowOrderTypeRow)"
            >
              {{ tl('edit') }}
            </ElButton>
            <ElButton
              size="small"
              type="danger"
              @click="openOrderTypeName(row as CashFlowOrderTypeRow)"
            >
              {{ tl('language') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElDialog>

    <!-- 編輯支付方式 -->
    <ElDialog
      v-model="otVisible"
      :title="`${tl('edit')} ${tl('deposit_order_type_setting')}`"
      width="640px"
    >
      <ElForm label-width="120px">
        <ElFormItem :label="tl('deposit_order_type_name')">
          <ElInput v-model="otForm.name" />
        </ElFormItem>
        <ElFormItem :label="tl('deposit_order_type_key')">
          <ElInput v-model="otForm.key" />
        </ElFormItem>
        <ElFormItem :label="tl('sorts')">
          <ElInput v-model="otForm.sorts" />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="otVisible = false">{{ tl('cancel') }}</ElButton>
        <ElButton :loading="otSaving" type="primary" @click="saveOrderType">
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>

    <!-- 支付方式多語系名稱 -->
    <ElDialog
      v-model="otNameVisible"
      :title="`${tl('edit')} ${tl('deposit_order_type_setting')}`"
      width="640px"
    >
      <ElForm label-width="140px">
        <ElFormItem :label="tl('deposit_vendor_name')">
          <ElInput :model-value="otNameBase" disabled />
        </ElFormItem>
        <ElFormItem v-for="l in languages" :key="l.id" :label="l.name">
          <ElInput v-model="otNameValues[String(l.id)]" />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="otNameVisible = false">{{ tl('cancel') }}</ElButton>
        <ElButton
          :loading="otNameSaving"
          type="primary"
          @click="saveOrderTypeName"
        >
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
