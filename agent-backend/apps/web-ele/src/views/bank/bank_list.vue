<script lang="ts" setup>
import type { BankShowRow } from '#/api';

import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getBankShowListApi, toggleBankStatusApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';

import BankEditDialog from '../bank_management/components/bank-edit-dialog.vue';

defineOptions({ name: 'BankBankList' });

const router = useRouter();
const editRef = ref<InstanceType<typeof BankEditDialog>>();
const PER_PAGE = 10;

const loading = ref(false);
const rows = ref<BankShowRow[]>([]);

const {
  handlePageChange,
  handleSearch,
  handleSortChange,
  keyword,
  page,
  pagedRows,
  total,
} = useClientTable(rows, {
  perPage: PER_PAGE,
  searchFields: (r) => [r.bank_name, r.bank_code],
});

async function loadData() {
  loading.value = true;
  try {
    const res = await getBankShowListApi({ page: 1, per_page: 100 });
    rows.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
  } finally {
    loading.value = false;
  }
}

async function toggleStatus(row: BankShowRow) {
  const next = Number(row.status) === 1 ? 0 : 1;
  const res = await toggleBankStatusApi({
    id: row.bank_id ?? '',
    status: next,
  });
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    loadData();
  } else {
    ElMessage.error(res.message || tl('failure'));
  }
}

function goBankEdit(row?: BankShowRow) {
  editRef.value?.open(row ? (row.bank_id ?? '') : '');
}

onMounted(loadData);
</script>

<template>
  <Page :title="`${tl('menu_auto_deposit')} > ${tl('bank_management')}`">
    <ElCard class="mb-3" shadow="never">
      <div class="flex flex-wrap items-center gap-2">
        <ElButton type="success" @click="goBankEdit()">
          {{ tl('add_bank') }}
        </ElButton>
        <ElButton type="warning" @click="router.push('/bank/index')">
          {{ tl('menu_bank') }}
        </ElButton>
        <div class="ml-auto">
          <ElInput
            v-model="keyword"
            :placeholder="tl('search')"
            class="!w-60"
            clearable
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
          :label="tl('bank_name')"
          prop="bank_name"
          min-width="180"
          sortable="custom"
        >
          <template #default="{ row }">
            <b>{{ (row as BankShowRow).bank_name }}</b>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('bank_code')"
          prop="bank_code"
          min-width="130"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('status')"
          min-width="110"
          prop="status"
          sortable="custom"
        >
          <template #default="{ row }">
            <span
              :class="
                Number((row as BankShowRow).status) === 1 ? '' : 'text-red-500'
              "
            >
              {{
                Number((row as BankShowRow).status) === 1
                  ? tl('enable')
                  : tl('disable')
              }}
            </span>
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
              <ElButton size="small">···</ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem @click="goBankEdit(row as BankShowRow)">
                    {{ tl('edit_bank') }}
                  </ElDropdownItem>
                  <ElDropdownItem @click="toggleStatus(row as BankShowRow)">
                    {{
                      Number((row as BankShowRow).status) === 1
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
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>

    <BankEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
