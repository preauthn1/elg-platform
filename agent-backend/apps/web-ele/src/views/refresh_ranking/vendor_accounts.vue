<script lang="ts" setup>
import type { RefreshRankingVendorAccountRow } from '#/api';

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
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getRefreshRankingVendorAccountsApi,
  getRefreshRankingVendorDropdownApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'RefreshRankingVendorAccounts' });

const PER_PAGE = 10;

const vendors = ref<Array<{ id: number | string; name: string }>>([]);

const filters = reactive({
  account_name: '',
  username: '',
  vendor_id: ' ' as number | string,
});

const searched = ref(false);

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<RefreshRankingVendorAccountRow>(
    ({ page, per_page }) => {
      searched.value = true;
      return getRefreshRankingVendorAccountsApi({
        account_name: filters.account_name,
        page,
        per_page,
        username: filters.username,
        vendor_id: filters.vendor_id,
      });
    },
    {
      guard: () => {
        if (filters.vendor_id === '' || filters.vendor_id === ' ') {
          ElMessage.warning(`${tl('please_choose')} ${tl('label_vendor')}`);
          return false;
        }
        return true;
      },
      perPage: PER_PAGE,
      showError: true,
    },
  );

function registerTime(v?: string) {
  return v ? dayjs(v).add(8, 'hour').format('YYYY-MM-DD HH:mm:ss') : '';
}

function handleReset() {
  filters.account_name = '';
  filters.username = '';
  filters.vendor_id = ' ';
}

onMounted(() => {
  getRefreshRankingVendorDropdownApi()
    .then((d) => {
      vendors.value = d ?? [];
    })
    .catch(() => {});
});
</script>

<template>
  <Page
    :title="`${tl('menu_refresh_ranking')} > ${tl('menu_member_vendor_accounts')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="`${tl('label_vendor')}${tl('member_account')}`">
            <ElInput v-model="filters.account_name" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem>
            <template #label>
              <span>
                {{ tl('label_vendor') }}
                <span class="text-red-500">*</span>
              </span>
            </template>
            <ElSelect
              v-model="filters.vendor_id"
              :placeholder="tl('please_choose')"
              class="w-full"
              filterable
            >
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
          <ElButton @click="handleReset">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard v-if="searched" shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('id')" prop="id" width="90" />
        <ElTableColumn
          :label="`${tl('label_vendor')}${tl('id')}`"
          prop="vendor_id"
          width="110"
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
        <ElTableColumn :label="tl('member_account')" min-width="120">
          <template #default="{ row }">
            <MemberAccountLink
              :member-id="row.member_id"
              :username="row.member_username"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('nickname')" min-width="120">
          <template #default="{ row }">
            <span :title="String(row.member_id ?? '')">
              {{ row.member_nickname }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent')"
          prop="agent_username"
          min-width="120"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('register_time')" width="170">
          <template #default="{ row }">
            {{ registerTime(row.member_created_at) }}
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
