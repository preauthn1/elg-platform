<script lang="ts" setup>
import type { UnlimitMemberRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getCustomizedSettingApi, getUnlimitMemberSearchApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'UnlimitMemberSearch' });

const PER_PAGE = 20;

const filters = reactive({ agent: '', member: '' });
/** 查詢當下的 member（用於高亮命中列） */
const searchedMember = ref('');

const loading = ref(false);
const list = ref<UnlimitMemberRow[]>([]);
const total = ref(0);
const page = ref(1);
/** customizedSetting 4501 == 1 顯示本身流水三欄 */
const isAgentItselfRebate = ref(false);

const fmt = (v?: number | string) => amountThousands(v, 2);

const title = computed(
  () =>
    `${tl('menu_reward_mode_management')} > ${tl('menu_unlimit')} > ${tl('menu_unlimit_search')}`,
);

const rowClass = ({ row }: { row: UnlimitMemberRow }) =>
  searchedMember.value && row.username === searchedMember.value
    ? 'bg-primary-50 dark:bg-primary-900/20'
    : '';

function identity(row: UnlimitMemberRow) {
  return Number(row.member_direct_count) === 0 ? tl('player') : tl('agent');
}

function promotionTotal(row: UnlimitMemberRow) {
  return (
    Number(row.member_direct_count ?? 0) + Number(row.member_team_count ?? 0)
  );
}

async function loadData() {
  loading.value = true;
  searchedMember.value = filters.member;
  try {
    const res = await getUnlimitMemberSearchApi({
      agent: filters.agent,
      member: filters.member,
      page: page.value,
    });
    if (Number(res.code) === 1000) {
      list.value = res.data ?? [];
      total.value = res.pagination?.total ?? 0;
    } else {
      list.value = [];
      total.value = 0;
    }
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadData();
}

function handleReset() {
  filters.agent = '';
  filters.member = '';
  handleSearch();
}

/** 下線數連結：以該代理＋該會員帳號再查一次（下鑽其下線） */
function drillPromotion(row: UnlimitMemberRow) {
  if (Number(row.member_direct_count) <= 0) return;
  filters.member = row.username ?? '';
  handleSearch();
}

function handlePageChange(p: number) {
  page.value = p;
  loadData();
}

onMounted(async () => {
  try {
    const settings = await getCustomizedSettingApi();
    const s = (settings ?? []).find((x) => Number(x.setting_key) === 4501);
    isAgentItselfRebate.value = Number(s?.value) === 1;
  } catch {
    isAgentItselfRebate.value = false;
  }
  loadData();
});
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.agent" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.member" clearable />
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
      <ElTable
        v-loading="loading"
        :data="list"
        :row-class-name="rowClass"
        border
        size="small"
        stripe
      >
        <ElTableColumn
          :label="tl('agent_name')"
          prop="agent_name"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_name" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('level')" prop="agent_level" min-width="80" />
        <ElTableColumn :label="tl('identity')" min-width="90">
          <template #default="{ row }">
            {{ identity(row as UnlimitMemberRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('unlimit_top_member')"
          prop="first_username"
          min-width="120"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.first_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('unlimit_parent_member')"
          prop="topper_username"
          min-width="120"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.topper_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('unlimit_promotions')" min-width="120">
          <template #default="{ row }">
            <ElButton
              v-if="Number((row as UnlimitMemberRow).member_direct_count) > 0"
              link
              type="primary"
              @click="drillPromotion(row as UnlimitMemberRow)"
            >
              {{ promotionTotal(row as UnlimitMemberRow) }} ({{
                (row as UnlimitMemberRow).member_direct_count
              }}+{{ (row as UnlimitMemberRow).member_team_count }})
            </ElButton>
            <span v-else>
              {{ promotionTotal(row as UnlimitMemberRow) }} ({{
                (row as UnlimitMemberRow).member_direct_count
              }}+{{ (row as UnlimitMemberRow).member_team_count }})
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('unlimit_sales')"
          align="right"
          min-width="110"
        >
          <template #default="{ row }">
            {{ fmt(row.performance_amount) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_availableapply_socre')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">{{ fmt(row.bonus_amount) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_alreadyapply_socre')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">{{ fmt(row.withdraw_amount) }}</template>
        </ElTableColumn>
        <ElTableColumn
          v-if="isAgentItselfRebate"
          :label="tl('itself_flow_amount')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">{{ fmt(row.flow_amount) }}</template>
        </ElTableColumn>
        <ElTableColumn
          v-if="isAgentItselfRebate"
          :label="tl('itself_availableapply_score')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            {{ fmt(row.itself_bonus_amount) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          v-if="isAgentItselfRebate"
          :label="tl('itself_alreadyapply_score')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            {{ fmt(row.itself_withdraw_amount) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('createdate')"
          prop="reg_time"
          align="center"
          min-width="150"
        />
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
