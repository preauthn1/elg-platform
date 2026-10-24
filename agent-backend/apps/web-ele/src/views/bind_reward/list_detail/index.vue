<script lang="ts" setup>
import type { BindRewardDetailRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  deleteBindRewardDetailApi,
  getBindRewardDetailListApi,
  getBindRewardSimpleListApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import AddPhoneDialog from './components/add-phone-dialog.vue';

defineOptions({ name: 'BindRewardListDetail' });

const PAGE_SIZES = [30, 50, 100];

const addPhoneRef = ref<InstanceType<typeof AddPhoneDialog>>();

/** 名單下拉（獎勵名稱_代理名稱_狀態） */
const lists = ref<Array<{ id: number | string; name: string }>>([]);

const filters = reactive({
  bind_status: 2 as number | string,
  list_id: '' as number | string,
  per_page: 30,
});

const loading = ref(false);
const list = ref<BindRewardDetailRow[]>([]);
const total = ref(0);
const page = ref(1);
/** 查詢當下的名單 id（新增/刪除後重載同一名單） */
const queriedListId = ref<number | string>('');

async function loadData() {
  if (!filters.list_id) {
    ElMessage.warning(tl('bind_reward_list'));
    return;
  }
  loading.value = true;
  queriedListId.value = filters.list_id;
  try {
    const res = await getBindRewardDetailListApi({
      bind_reward_id: filters.list_id,
      bind_status: filters.bind_status,
      page: page.value,
      per_page: filters.per_page,
    });
    if (Number(res.code) === 1000) {
      list.value = res.data ?? [];
      total.value = res.pagination?.total ?? 0;
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

function handlePageChange(p: number) {
  page.value = p;
  loadData();
}

function handleSizeChange(size: number) {
  filters.per_page = size;
  page.value = 1;
  loadData();
}

/** 僅未領取可刪，二次確認後刪除 */
async function onDelete(row: BindRewardDetailRow) {
  if (Number(row.status) === 1) {
    ElMessage.warning(tl('claim_enable'));
    return;
  }
  try {
    await ElMessageBox.confirm(
      `${tl('deposit_order_type_delete_message')}\n${tl('num')}: ${row.id}\n${tl('mobile')}: ${row.phone}`,
      tl('delete'),
      {
        cancelButtonText: tl('cancel'),
        confirmButtonText: tl('confirm'),
        customStyle: { whiteSpace: 'pre-line' } as any,
      },
    );
  } catch {
    return;
  }
  const res = await deleteBindRewardDetailApi(row.id).catch(() => null);
  if (res && Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    loadData();
  } else {
    ElMessage.error(res?.message || tl('error'));
  }
}

onMounted(async () => {
  const res = await getBindRewardSimpleListApi().catch(() => []);
  lists.value = (res ?? []).map((r) => ({
    id: r.id,
    name: `${r.reward_name}_${r.agent_name}_${
      Number(r.status) === 1 ? tl('enable') : tl('disable')
    }`,
  }));
});
</script>

<template>
  <Page :title="`${tl('bind_reward')} > ${tl('menu_bind_reward_list_detail')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="flex flex-wrap items-center gap-4">
          <ElFormItem :label="tl('bind_reward_list')" class="!mb-0">
            <ElSelect v-model="filters.list_id" class="!w-72" filterable>
              <ElOption
                v-for="l in lists"
                :key="l.id"
                :label="l.name"
                :value="l.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('claim_status')" class="!mb-0">
            <ElSelect v-model="filters.bind_status" class="!w-40">
              <ElOption :label="tl('all')" :value="2" />
              <ElOption :label="tl('claim_enable')" :value="1" />
              <ElOption :label="tl('claim_disable')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('show')" class="!mb-0">
            <ElSelect v-model="filters.per_page" class="!w-24">
              <ElOption
                v-for="s in PAGE_SIZES"
                :key="s"
                :label="String(s)"
                :value="s"
              />
            </ElSelect>
          </ElFormItem>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-3">
        <ElButton
          :disabled="!filters.list_id"
          type="success"
          @click="addPhoneRef?.open(filters.list_id)"
        >
          {{ tl('add') }}
        </ElButton>
      </div>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('num')" prop="id" width="90" />
        <ElTableColumn :label="tl('mobile')" min-width="120" prop="phone" />
        <ElTableColumn :label="tl('claim_status')" min-width="90">
          <template #default="{ row }">
            <span
              :class="
                Number(row.status) === 1 ? 'text-green-600' : 'text-red-500'
              "
            >
              {{
                Number(row.status) === 1
                  ? tl('claim_enable')
                  : tl('claim_disable')
              }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_name" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('unlimit_parent_member')"
          min-width="110"
          prop="agent_name"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_name" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('register_time')"
          min-width="150"
          prop="bind_time"
        />
        <ElTableColumn :label="tl('register_ip')" min-width="120" prop="ip" />
        <ElTableColumn
          :label="tl('real_name')"
          min-width="110"
          prop="member_real_name"
        />
        <ElTableColumn
          :label="tl('operate')"
          align="center"
          width="90"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              v-if="Number(row.status) === 0"
              size="small"
              type="danger"
              @click="onDelete(row as BindRewardDetailRow)"
            >
              {{ tl('delete') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="filters.per_page"
        :total="total"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </ElCard>

    <AddPhoneDialog ref="addPhoneRef" @success="loadData" />
  </Page>
</template>
