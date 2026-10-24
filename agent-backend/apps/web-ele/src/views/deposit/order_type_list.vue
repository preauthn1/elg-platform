<script lang="ts" setup>
import type { OrderTypeRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElDialog,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElForm,
  ElFormItem,
  ElImage,
  ElInput,
  ElMessage,
  ElOption,
  ElRadioButton,
  ElRadioGroup,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getLanguagesApi,
  getOrderTypeNamesApi,
  getOrderTypePagingApi,
  updateOrderTypeApi,
  updateOrderTypeNamesApi,
} from '#/api';
import { getCurrencyListApi } from '#/api/report';
import ImageInput from '#/components/image-input.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

defineOptions({ name: 'DepositOrderTypeList' });

const PER_PAGE = 20;

/** 支付方式 */
const PAY_TYPE_LABEL: Record<number, string> = {
  1: '電子',
  2: '網路轉帳',
  3: 'QR掃碼',
  4: '加密貨幣',
};

function payTypeText(t?: number | string) {
  return PAY_TYPE_LABEL[Number(t)] ?? '其他';
}

const currencies = ref<Array<{ id: number; name: string }>>([]);
const languages = ref<Array<{ id: number | string; name: string }>>([]);

const filters = reactive({ name: '' });

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<OrderTypeRow>(
    ({ page, per_page }) =>
      getOrderTypePagingApi({
        name: filters.name,
        page,
        per_page,
      }),
    { perPage: PER_PAGE, showError: true },
  );

function createdTime(v?: string) {
  return v ? dayjs(v).add(8, 'hour').format('YYYY-MM-DD HH:mm:ss') : '';
}

function handleReset() {
  filters.name = '';
}

// ---------- 編輯 modal ----------
const editVisible = ref(false);
const editSaving = ref(false);
const editForm = reactive({
  currency: '' as number | string,
  id: '' as number | string,
  image_path: '',
  key: '',
  name: '',
  sorts: '' as number | string,
  status: 1 as number | string,
  type: 1 as number | string,
});

function openEdit(row: OrderTypeRow) {
  editForm.id = row.id;
  editForm.name = row.name ?? '';
  editForm.key = row.key ?? '';
  editForm.sorts = row.sorts ?? '';
  editForm.status = Number(row.status) === 1 ? 1 : 0;
  editForm.type = Number(row.type) || 1;
  editForm.currency = row.currency_id ?? '';
  editForm.image_path = row.image_path ?? '';
  editVisible.value = true;
}

async function saveEdit() {
  editSaving.value = true;
  try {
    const res = await updateOrderTypeApi({
      currency: editForm.currency,
      id: editForm.id,
      image_path: editForm.image_path,
      key: editForm.key,
      name: editForm.name,
      sorts: editForm.sorts,
      status: editForm.status,
      type: editForm.type,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      editVisible.value = false;
      loadData();
    } else {
      ElMessage.error(`${res.message}`);
    }
  } finally {
    editSaving.value = false;
  }
}

// ---------- 多語系名稱 modal ----------
const nameVisible = ref(false);
const nameSaving = ref(false);
const nameId = ref<number | string>('');
const nameValues = reactive<Record<string, string>>({});

async function openNames(row: OrderTypeRow) {
  nameId.value = row.id;
  for (const k of Object.keys(nameValues)) delete nameValues[k];
  for (const l of languages.value) nameValues[String(l.id)] = '';
  nameVisible.value = true;
  const res = await getOrderTypeNamesApi(row.id);
  if (Number(res.code) === 1000) {
    for (const item of res.data ?? []) {
      nameValues[String(item.language_id)] = item.name ?? '';
    }
  } else {
    ElMessage.error(`${res.message}`);
  }
}

async function saveNames() {
  nameSaving.value = true;
  try {
    const res = await updateOrderTypeNamesApi({
      id: nameId.value,
      order_type_names: { ...nameValues },
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      nameVisible.value = false;
      loadData();
    } else {
      ElMessage.error(`${res.message}`);
    }
  } finally {
    nameSaving.value = false;
  }
}

onMounted(() => {
  getCurrencyListApi()
    .then((d) => {
      currencies.value = d ?? [];
    })
    .catch(() => {});
  getLanguagesApi()
    .then((d) => {
      languages.value = (d ?? []).map((l) => ({ id: l.id, name: l.name }));
    })
    .catch(() => {});
  loadData();
});
</script>

<template>
  <Page
    :title="`${tl('menu_system_management')} > ${tl('menu_deposit_order_types_list')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('name')">
            <ElInput v-model="filters.name" clearable />
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="handleReset">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('id')" prop="id" width="80" />
        <ElTableColumn :label="tl('deposit_vendor_name')" min-width="120">
          <template #default="{ row }">
            <span :title="String(row.cash_flow_id ?? '')">
              {{ row.cash_flow_name }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('name')" prop="name" min-width="110" />
        <ElTableColumn :label="tl('code')" prop="key" min-width="110" />
        <ElTableColumn :label="tl('pay_method')" width="110">
          <template #default="{ row }">{{ payTypeText(row.type) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('currency')"
          prop="currency_name"
          width="100"
        />
        <ElTableColumn :label="tl('sorts')" prop="sorts" width="80" />
        <ElTableColumn :label="tl('picture')" width="80">
          <template #default="{ row }">
            <ElImage
              v-if="row.image_path"
              :src="row.image_path"
              fit="contain"
              style="height: 40px"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" width="90">
          <template #default="{ row }">
            <span v-if="Number(row.status) === 1">{{ tl('enable') }}</span>
            <span v-else class="text-red-500">{{ tl('disable') }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('time')" width="160">
          <template #default="{ row }">
            {{ createdTime(row.created_at) }}
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="80" fixed="right">
          <template #default="{ row }">
            <ElDropdown trigger="click">
              <ElButton size="small">
                <span class="i-lucide-chevron-down"></span>···
              </ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem @click="openEdit(row as OrderTypeRow)">
                    {{ tl('edit') }}
                  </ElDropdownItem>
                  <ElDropdownItem @click="openNames(row as OrderTypeRow)">
                    {{ tl('edit_order_type_names') }}
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
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>

    <!-- 編輯 -->
    <ElDialog
      v-model="editVisible"
      :title="tl('detailinfo_edit')"
      width="640px"
    >
      <ElForm label-width="120px">
        <ElFormItem :label="tl('id')">
          <ElInput :model-value="String(editForm.id)" disabled />
        </ElFormItem>
        <ElFormItem :label="tl('name')">
          <ElInput v-model="editForm.name" />
        </ElFormItem>
        <ElFormItem :label="tl('code')">
          <ElInput v-model="editForm.key" maxlength="50" />
        </ElFormItem>
        <ElFormItem :label="tl('sorts')">
          <ElInput v-model="editForm.sorts" maxlength="50" />
        </ElFormItem>
        <ElFormItem :label="tl('status')">
          <ElRadioGroup v-model="editForm.status">
            <ElRadioButton :value="1">{{ tl('enable') }}</ElRadioButton>
            <ElRadioButton :value="0">{{ tl('disable') }}</ElRadioButton>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem :label="tl('pay_method')">
          <ElSelect v-model="editForm.type" class="w-full">
            <ElOption
              v-for="t in [1, 2, 3, 4]"
              :key="t"
              :label="payTypeText(t)"
              :value="t"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('currency')">
          <ElSelect v-model="editForm.currency" class="w-full" filterable>
            <ElOption
              v-for="c in currencies"
              :key="c.id"
              :label="c.name"
              :value="c.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('picture')">
          <ImageInput v-model="editForm.image_path" class="w-full" />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="editVisible = false">{{ tl('cancel') }}</ElButton>
        <ElButton :loading="editSaving" type="primary" @click="saveEdit">
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>

    <!-- 多語系名稱 -->
    <ElDialog
      v-model="nameVisible"
      :title="tl('edit_order_type_names')"
      width="640px"
    >
      <p class="mb-2 text-sm font-medium">{{ tl('multi_language_text') }}</p>
      <ElForm label-width="120px">
        <ElFormItem v-for="l in languages" :key="l.id" :label="l.name">
          <ElInput
            v-model="nameValues[String(l.id)]"
            :rows="2"
            type="textarea"
          />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="nameVisible = false">{{ tl('cancel') }}</ElButton>
        <ElButton :loading="nameSaving" type="primary" @click="saveNames">
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
