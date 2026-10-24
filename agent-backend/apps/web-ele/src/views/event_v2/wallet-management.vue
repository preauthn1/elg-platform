<script lang="ts" setup>
import type { EventV2WalletRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElFormItem,
  ElInput,
  ElInputNumber,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getEventV2WalletListApi,
  quitEventV2Api,
  searchAgentOptionsApi,
  updateEventV2WalletBalanceApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'EventV2Wallet' });

const PER_PAGE = 20;

const filters = reactive({ agent_username: '', member_username: '' });
const agentOptions = ref<Array<{ id: number; username: string }>>([]);
const agentLoading = ref(false);

const loading = ref(false);
const list = ref<EventV2WalletRow[]>([]);
const total = ref(0);
const page = ref(1);

// 修改餘額彈窗
const balanceVisible = ref(false);
const balanceSaving = ref(false);
const balanceForm = reactive({
  operate: 1 as number,
  point: 0 as number,
  wallet_id: 0 as number | string,
});

async function searchAgent(query: string) {
  if (!query) return;
  agentLoading.value = true;
  try {
    const res = await searchAgentOptionsApi(query);
    if (Number(res.code) === 1000) agentOptions.value = res.data ?? [];
  } finally {
    agentLoading.value = false;
  }
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getEventV2WalletListApi({
      agent_username: filters.agent_username,
      member_username: filters.member_username,
      page: page.value,
      per_page: PER_PAGE,
    });
    if (Number(res.code) === 200) {
      list.value = res.data?.items ?? [];
      total.value = res.data?.pagination?.total ?? 0;
    } else {
      list.value = [];
      total.value = 0;
      ElMessage.warning(res.message);
    }
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadData();
}

function onPage(p: number) {
  page.value = p;
  loadData();
}

function openBalance(row: EventV2WalletRow) {
  balanceForm.wallet_id = row.wallet_id ?? 0;
  balanceForm.operate = 1;
  balanceForm.point = 0;
  balanceVisible.value = true;
}

async function saveBalance() {
  if (balanceForm.point < 0) {
    ElMessage.error('point can not be negative');
    return;
  }
  balanceSaving.value = true;
  try {
    const balance =
      balanceForm.operate === 2 ? -balanceForm.point : balanceForm.point;
    const res = await updateEventV2WalletBalanceApi({
      balance,
      wallet_id: balanceForm.wallet_id,
    });
    if (Number(res.code) === 200) {
      ElMessage.success(res.message);
      balanceVisible.value = false;
      loadData();
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    balanceSaving.value = false;
  }
}

async function quit(row: EventV2WalletRow) {
  try {
    await ElMessageBox.confirm(
      `${tl('give_up')}${tl('event')}?`,
      tl('give_up'),
    );
  } catch {
    return;
  }
  const res = await quitEventV2Api(row.join_id ?? 0);
  if (Number(res.code) === 200) {
    ElMessage.success(res.message);
    loadData();
  } else {
    ElMessage.error(res.message);
  }
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('event_exclusive_offers')} > ${tl('menu_event_v2_wallet')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <div class="flex flex-wrap items-end gap-3">
        <ElFormItem :label="tl('agent_account')" class="!mb-0">
          <ElSelect
            v-model="filters.agent_username"
            :debounce="500"
            :loading="agentLoading"
            :remote-method="searchAgent"
            class="!w-52"
            clearable
            filterable
            remote
          >
            <ElOption
              v-for="a in agentOptions"
              :key="a.id"
              :label="a.username"
              :value="a.username"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('member_account')" class="!mb-0">
          <ElInput
            v-model="filters.member_username"
            clearable
            @keyup.enter="handleSearch"
          />
        </ElFormItem>
        <ElButton :loading="loading" type="primary" @click="handleSearch">
          {{ tl('search') }}
        </ElButton>
      </div>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="`${tl('event')}${tl('created_time')}`"
          align="center"
          prop="created_at"
          width="160"
        />
        <ElTableColumn
          :label="`${tl('event')}ID`"
          align="center"
          prop="event_v2_detail_id"
          width="90"
        />
        <ElTableColumn
          :label="tl('event_name')"
          align="center"
          prop="name"
          min-width="150"
        />
        <ElTableColumn
          :label="tl('member_name')"
          align="center"
          min-width="110"
        >
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_name')"
          align="center"
          prop="agent_username"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('event_wallet_balance')"
          align="center"
          prop="balance"
          width="130"
        />
        <ElTableColumn
          :label="tl('features')"
          align="center"
          width="90"
          fixed="right"
        >
          <template #default="{ row }">
            <ElDropdown>
              <ElButton size="small">{{ tl('features') }}</ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem @click="openBalance(row as EventV2WalletRow)">
                    {{ `${tl('event_wallet_balance')}${tl('modify')}` }}
                  </ElDropdownItem>
                  <ElDropdownItem @click="quit(row as EventV2WalletRow)">
                    {{ `${tl('give_up')}${tl('event')}` }}
                  </ElDropdownItem>
                </ElDropdownMenu>
              </template>
            </ElDropdown>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_data') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="onPage"
      />
    </ElCard>

    <ElDialog
      v-model="balanceVisible"
      :title="`${tl('event_wallet_balance')}${tl('modify')}`"
      align-center
      width="480"
    >
      <ElFormItem :label="tl('operate')" label-width="auto">
        <ElSelect v-model="balanceForm.operate" class="w-full">
          <ElOption :label="tl('add_event_v2_balance')" :value="1" />
          <ElOption :label="tl('reduce_event_v2_balance')" :value="2" />
        </ElSelect>
      </ElFormItem>
      <ElFormItem :label="tl('point')" label-width="auto">
        <ElInputNumber
          v-model="balanceForm.point"
          :min="0"
          :step="0.01"
          class="!w-full"
        />
      </ElFormItem>
      <template #footer>
        <ElButton @click="balanceVisible = false">{{ tl('cancel') }}</ElButton>
        <ElButton :loading="balanceSaving" type="primary" @click="saveBalance">
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
