<script lang="ts" setup>
import type { VendorAccountRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getVendorAccountListApi,
  getVendorDropdownListApi,
  resetVendorPasswordApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'MemberVendorAccounts' });

const PER_PAGE = 10;

const vendors = ref<Array<{ id: number; name: string }>>([]);
const showResetPasswordButton = ref(false);

const filters = reactive({
  account_name: '',
  username: '',
  vendor_id: ' ' as number | string,
});

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<VendorAccountRow>(
    ({ page, per_page }) =>
      getVendorAccountListApi({
        account_name: filters.account_name,
        page,
        per_page,
        username: filters.username,
        vendor_id: filters.vendor_id,
      }),
    {
      guard: () => {
        // 遊戲商必選
        if (filters.vendor_id === ' ' || filters.vendor_id === '') {
          ElMessage.warning(`${tl('please_choose')} ${tl('label_vendor')}`);
          return false;
        }
        return true;
      },
      onLoaded: (res) => {
        showResetPasswordButton.value = Boolean(
          (res as { meta?: { showResetPasswordButton?: boolean } }).meta
            ?.showResetPasswordButton,
        );
      },
      perPage: PER_PAGE,
      showError: true,
    },
  );

/** binded_at 顯示 +8 小時 */
function bindedAt(v?: string) {
  return v ? dayjs(v).add(8, 'hour').format('YYYY-MM-DD HH:mm:ss') : '';
}

function handleClear() {
  filters.account_name = '';
  filters.username = '';
  filters.vendor_id = ' ';
}

/** 重置遊戲帳號密碼（兩段確認） */
async function handleResetPassword(row: VendorAccountRow) {
  try {
    await ElMessageBox.confirm(
      row.vendor_username ?? '',
      tl('reset_vendor_password'),
      {
        cancelButtonText: tl('cancel'),
        confirmButtonText: tl('confirm'),
        type: 'warning',
      },
    );
    await ElMessageBox.confirm(
      tl('are_you_sure'),
      tl('reset_vendor_password'),
      {
        cancelButtonText: tl('cancel'),
        confirmButtonText: tl('confirm'),
        type: 'warning',
      },
    );
  } catch {
    return;
  }
  try {
    const res = await resetVendorPasswordApi({
      member_id: row.member_id ?? '',
      vendor_id: row.vendor_id ?? '',
      vendor_username: row.vendor_username ?? '',
    });
    if (Number(res.code) === 200) {
      ElMessage.success(tl('success'));
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } catch {
    ElMessage.error(tl('failure'));
  }
}

// 不自動查詢，先載入遊戲商下拉
onMounted(() => {
  getVendorDropdownListApi()
    .then((d) => {
      vendors.value = d ?? [];
    })
    .catch(() => {});
});
</script>

<template>
  <Page
    :title="`${tl('member_lower_management')} > ${tl('menu_member_vendor_accounts')}`"
  >
    <!-- 查詢 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="`${tl('label_vendor')}${tl('member_account')}`">
            <ElInput v-model="filters.account_name" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem required :label="tl('label_vendor')">
            <ElSelect v-model="filters.vendor_id" class="w-full" filterable>
              <ElOption :label="tl('please_choose')" value=" " />
              <ElOption
                v-for="v in vendors"
                :key="v.id"
                :label="v.name"
                :value="v.id"
              />
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

    <!-- 列表 -->
    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('id')" prop="id" width="90" />
        <ElTableColumn
          :label="`${tl('label_vendor')}${tl('id')}`"
          prop="vendor_id"
          width="100"
        />
        <ElTableColumn
          :label="tl('label_vendor')"
          prop="vendor_name"
          min-width="120"
        />
        <ElTableColumn
          :label="`${tl('game')}${tl('member_account')}`"
          min-width="140"
        >
          <template #default="{ row }">
            <span class="text-info">{{ row.vendor_username }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink
              :member-id="row.member_id"
              :username="row.member_username"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('nickname')" min-width="110">
          <template #default="{ row }">
            <span :title="String(row.member_id ?? '')">
              {{ row.member_nickname }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent')"
          prop="agent_username"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('event')}ID`"
          prop="event_v2_detail_id"
          width="100"
        />
        <ElTableColumn :label="`${tl('bind')}${tl('time')}`" width="160">
          <template #default="{ row }">{{ bindedAt(row.binded_at) }}</template>
        </ElTableColumn>
        <ElTableColumn
          v-if="showResetPasswordButton"
          :label="tl('features')"
          align="center"
          width="140"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              size="small"
              @click="handleResetPassword(row as VendorAccountRow)"
            >
              {{ tl('reset_vendor_password') }}
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
  </Page>
</template>
