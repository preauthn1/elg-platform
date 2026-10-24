<script lang="ts" setup>
import type { BankManagementRow } from '#/api';

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

import { getBankManagementListApi, toggleBankAccountStatusApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';
import { hasMenuCode } from '#/router/agent-menu';

import BankAccountEditDialog from '../bank_management/components/bank-account-edit-dialog.vue';

defineOptions({ name: 'BankIndex' });

const router = useRouter();
const editRef = ref<InstanceType<typeof BankAccountEditDialog>>();
/** 4545：新增銀行帳號改由獨立權限節點控制，後端 bank/addAccount 也會檢查同一把鍵 */
const canCreateAccount = hasMenuCode('bank_account_create_permission');
const PER_PAGE = 10;

const loading = ref(false);
const rows = ref<BankManagementRow[]>([]);

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
  searchFields: (r) => [r.bank_name, r.bank_code, r.account_name, r.account_no],
});

async function loadData() {
  loading.value = true;
  try {
    const res = await getBankManagementListApi({ page: 1, per_page: 100 });
    rows.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
  } finally {
    loading.value = false;
  }
}

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
 * 4545：戶口建立後不再開放編輯（後端 bank/editAccount 已棄用），這裡只用於新增。
 * 要改戶口資料請新增一筆、停用舊的。
 */
function goCreate() {
  editRef.value?.open();
}

onMounted(loadData);
</script>

<template>
  <Page :title="`${tl('menu_auto_deposit')} > ${tl('menu_bank')}`">
    <ElCard class="mb-3" shadow="never">
      <div class="flex flex-wrap items-center gap-2">
        <ElButton type="success" @click="router.push('/bank/bank_list')">
          {{ tl('bank_management') }}
        </ElButton>
        <ElButton v-if="canCreateAccount" type="warning" @click="goCreate()">
          {{ tl('add_bank_account') }}
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
          min-width="140"
          sortable="custom"
        >
          <template #default="{ row }">
            <b>{{ (row as BankManagementRow).bank_name }}</b>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('bank_code')"
          prop="bank_code"
          min-width="110"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('account_name')"
          prop="account_name"
          min-width="140"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('account_no')"
          prop="account_no"
          min-width="140"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('min_amount')"
          align="right"
          prop="min_amount"
          min-width="110"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('max_amount')"
          align="right"
          prop="max_amount"
          min-width="110"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('status')"
          min-width="100"
          prop="status"
          sortable="custom"
        >
          <template #default="{ row }">
            <span
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
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>

    <BankAccountEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
