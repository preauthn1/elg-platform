<script lang="ts" setup>
import type { WithdrawalBankRow } from '#/api';

import { reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElForm,
  ElFormItem,
  ElImage,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getWithdrawalBankListApi,
  getWithdrawalTypeListApi,
  toggleWithdrawalBankStatusApi,
} from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

import BankEditDialog from './components/bank-edit-dialog.vue';
import BankNameEditDialog from './components/bank-name-edit-dialog.vue';
import CurrencyRateDialog from './components/currency-rate-dialog.vue';

defineOptions({ name: 'WithdrawalBankList' });

const PER_PAGE = 10;

const bankEditRef = ref<InstanceType<typeof BankEditDialog>>();
const bankNameEditRef = ref<InstanceType<typeof BankNameEditDialog>>();
const currencyRateRef = ref<InstanceType<typeof CurrencyRateDialog>>();

const filters = reactive({
  name: '',
  status: '1' as number | string,
  type: '-1' as number | string,
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<WithdrawalBankRow>(
    ({ page, per_page }) =>
      getWithdrawalBankListApi({
        name: filters.name,
        page,
        per_page,
        status: filters.status,
        type: filters.type,
      }),
    { perPage: PER_PAGE, showError: true },
  );

const types = ref<Array<{ id: number; name: string }>>([]);

function handleClear() {
  filters.name = '';
  filters.type = '-1';
  filters.status = '1';
}

function taxText(row: WithdrawalBankRow) {
  return Number(row.tax_type) === 0
    ? row.bank_tax
    : `${Number(row.bank_tax) * 100}%`;
}

async function toggleStatus(row: WithdrawalBankRow) {
  const next = Number(row.status) === 1 ? 0 : 1;
  const res = await toggleWithdrawalBankStatusApi({
    id: row.bank_id,
    status: next,
  });
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    loadData();
  } else {
    ElMessage.error(Number(res.code) === 2002 ? String(res.data) : res.message);
  }
}

getWithdrawalTypeListApi()
  .then((d) => (types.value = d ?? []))
  .catch(() => (types.value = []));
loadData();
</script>

<template>
  <Page :title="`${tl('menu_auto_withdrawal')} > ${tl('bank_management')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <div class="flex items-center justify-between">
          <span class="font-medium">{{ tl('search') }}</span>
          <div class="flex gap-2">
            <ElButton type="success" @click="bankEditRef?.open()">
              {{ tl('add_bank') }}
            </ElButton>
            <ElButton type="info" @click="currencyRateRef?.open()">
              {{ tl('currency_rate_manage') }}
            </ElButton>
          </div>
        </div>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('bank_name')">
            <ElInput v-model="filters.name" clearable />
          </ElFormItem>
          <ElFormItem :label="`${tl('bank')}${tl('type')}`">
            <ElSelect v-model="filters.type" class="w-full">
              <ElOption :label="tl('all')" value="-1" />
              <ElOption
                v-for="t in types"
                :key="t.id"
                :label="t.name"
                :value="String(t.id)"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="w-full">
              <ElOption :label="tl('all')" value="-1" />
              <ElOption :label="tl('enable')" value="1" />
              <ElOption :label="tl('disable')" value="0" />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('bank_name')" align="center" min-width="120">
          <template #default="{ row }">
            <b>{{ row.bank_name }}</b>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('bank_code')"
          align="center"
          prop="bank_code"
          min-width="100"
        />
        <ElTableColumn
          :label="`${tl('bank')}${tl('type')}`"
          align="center"
          prop="bank_type"
          min-width="100"
        />
        <ElTableColumn
          :label="tl('currency_name')"
          align="center"
          prop="currency_name"
          min-width="100"
        />
        <ElTableColumn
          :label="`${tl('min_amount')} / ${tl('max_amount')}`"
          align="center"
          min-width="140"
        >
          <template #default="{ row }">
            {{ row.min_amount }} / {{ row.max_amount }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('handling_fee')"
          align="right"
          min-width="100"
        >
          <template #default="{ row }">
            {{ taxText(row as WithdrawalBankRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('rate')"
          align="right"
          prop="bank_rate"
          min-width="80"
        />
        <ElTableColumn :label="tl('picture')" align="center" width="80">
          <template #default="{ row }">
            <ElImage
              v-if="row.image_url"
              :preview-src-list="[row.image_url]"
              :src="row.image_url"
              fit="contain"
              preview-teleported
              style="max-width: 30px; max-height: 30px"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('sorts')"
          align="center"
          prop="sort"
          width="70"
        />
        <ElTableColumn :label="tl('status')" align="center" width="90">
          <template #default="{ row }">
            <span
              :class="
                Number(row.status) === 1 ? 'text-green-600' : 'text-red-500'
              "
            >
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="90" fixed="right">
          <template #default="{ row }">
            <ElDropdown>
              <ElButton size="small">{{ tl('features') }}</ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem @click="bankEditRef?.open(row.bank_id)">
                    {{ tl('edit_bank') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    @click="
                      bankNameEditRef?.open(row.bank_id, row.bank_name ?? '')
                    "
                  >
                    {{ tl('edit_bank_name') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    @click="toggleStatus(row as WithdrawalBankRow)"
                  >
                    {{
                      Number(row.status) === 1 ? tl('disable') : tl('enable')
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
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>

    <BankEditDialog ref="bankEditRef" @saved="loadData" />
    <BankNameEditDialog ref="bankNameEditRef" @saved="loadData" />
    <CurrencyRateDialog ref="currencyRateRef" />
  </Page>
</template>
