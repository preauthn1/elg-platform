<script lang="ts" setup>
import type { ThreeHierarchyUserRow, ThreeHierarchyUserSummary } from '#/api';

import { reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import { getThreeHierarchyUserSearchApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountPlain, amountThousands } from '#/utils/format';
import { fromApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'ThreeHierarchyUserSearch' });

const PER_PAGE = 20;

// 工單4190：identity 身分篩選收在後端（後端分頁下前端過濾會使每頁筆數不定）
const filters = reactive({ agent: '', identity: '', member: '' });
/** 查詢當下的 member（用於高亮命中列） */
const searchedMember = ref('');

/** 工單4190：遠端排序（後端白名單；依可提現/已提現排序時後端自動限會員身分） */
const sortField = ref('');
const sortDirection = ref('');

const loading = ref(false);
const list = ref<ThreeHierarchyUserRow[]>([]);
const summary = ref<ThreeHierarchyUserSummary>({});
const total = ref(0);
const page = ref(1);

/** 代理列的佔位（'_ '）顯示為「—」：代表不適用，非資料缺失（工單4190） */
function cellText(v?: number | string) {
  return Number.isNaN(Number(v)) ? '—' : amountThousands(v, 2);
}

/** 會員帳號欄：代理列無會員帳號，顯示「—」 */
function memberText(v?: string) {
  const s = String(v ?? '').trim();
  return s === '' || s === '_' ? '—' : s;
}

const rowClass = ({ row }: { row: ThreeHierarchyUserRow }) =>
  searchedMember.value && row.member_username === searchedMember.value
    ? 'bg-primary-50 dark:bg-primary-900/20'
    : '';

async function loadData() {
  loading.value = true;
  searchedMember.value = filters.member;
  try {
    const res = await getThreeHierarchyUserSearchApi({
      agent: filters.agent,
      direction: sortDirection.value,
      identity: filters.identity,
      member: filters.member,
      page: page.value,
      sort: sortField.value,
    });
    // apis 後端成功碼 200（4190 起由 API_Agent 移植）
    if (Number(res.code) === 200) {
      list.value = res.data ?? [];
      summary.value = res.summary ?? {};
      total.value = res.pagination?.total ?? 0;
    } else {
      list.value = [];
      summary.value = {};
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
  filters.identity = '';
  filters.member = '';
  sortField.value = '';
  sortDirection.value = '';
  handleSearch();
}

function handlePageChange(p: number) {
  page.value = p;
  loadData();
}

/** ElTable 遠端排序：prop 即後端白名單欄位名；取消排序時清空回預設序 */
function handleSortChange(e: { order?: null | string; prop?: string }) {
  if (e?.order) {
    sortField.value = e.prop ?? '';
    sortDirection.value = e.order === 'ascending' ? 'asc' : 'desc';
  } else {
    sortField.value = '';
    sortDirection.value = '';
  }
  page.value = 1;
  loadData();
}

const exporting = ref(false);
/** 匯出 Excel：抓全部符合的列（per_page 大） */
async function handleExport() {
  exporting.value = true;
  try {
    const params = {
      agent: filters.agent,
      direction: sortDirection.value,
      identity: filters.identity,
      member: filters.member,
      page: 1,
      per_page: 100_000,
      sort: sortField.value,
    };
    const res = await getThreeHierarchyUserSearchApi(params);
    const rows = Number(res.code) === 200 ? (res.data ?? []) : [];
    await exportExcel(
      `user_search_${Date.now()}`,
      [
        tl('identity'),
        tl('agent_account'),
        tl('member_account'),
        tl('self_rebate'),
        tl('down_rebate'),
        tl('down2_rebate'),
        tl('th_total_bonus'),
        tl('can_withdraw_reward'),
        tl('has_withdraw_reward'),
        tl('createdate'),
      ],
      rows.map((r) => [
        r.identity === 'agent' ? tl('agent') : tl('member'),
        r.parent_username,
        memberText(r.member_username),
        amountPlain(r.rebate_amount, 2),
        amountPlain(r.down_rebate_amount, 2),
        amountPlain(r.down2_rebate_amount, 2),
        amountPlain(r.bonus_amount, 2),
        cellText(r.withdraw_able),
        cellText(r.withdraw_amount),
        fromApiTime(r.created_at),
      ]),
      {
        audit: { conditions: params },
        title: tl('menu_three_layers_user_search'),
      },
    );
  } finally {
    exporting.value = false;
  }
}

loadData();
</script>

<template>
  <Page
    :title="`${tl('menu_reward_mode_management')} > ${tl('menu_three_layers_agents_mode_including_agent')} > ${tl('menu_three_layers_user_search')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.agent" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.member" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('identity')">
            <ElSelect v-model="filters.identity" class="w-full">
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('member')" value="member" />
              <ElOption :label="tl('agent')" value="agent" />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="handleReset">{{ tl('clear') }}</ElButton>
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('excel') }}
          </ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <!-- 工單4190：兩組統計卡。會員合計解場景 G/B；代理合計無可提現/已提現
         （代理返利由排程直接派發，無提領概念） -->
    <div class="mb-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
      <ElCard shadow="never">
        <template #header>
          <span class="font-medium">{{ tl('th_member_summary') }}</span>
        </template>
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
          <div>
            <div class="text-xs opacity-60">{{ tl('th_total_bonus') }}</div>
            <div class="text-lg font-bold">
              {{ amountThousands(summary.member?.bonus_amount ?? 0, 2) }}
            </div>
          </div>
          <div>
            <div class="text-xs opacity-60">{{ tl('th_withdraw_total') }}</div>
            <div class="text-lg font-bold">
              {{ amountThousands(summary.member?.withdraw_amount ?? 0, 2) }}
            </div>
          </div>
          <div>
            <div class="text-xs opacity-60">
              {{ tl('th_withdrawable_total') }}
            </div>
            <div class="text-lg font-bold">
              {{ amountThousands(summary.member?.withdraw_able ?? 0, 2) }}
            </div>
          </div>
          <div>
            <div class="text-xs opacity-60">{{ tl('th_member_count') }}</div>
            <div class="text-lg font-bold">
              {{ summary.member?.total ?? 0 }}
            </div>
          </div>
        </div>
      </ElCard>
      <ElCard shadow="never">
        <template #header>
          <span class="font-medium">{{ tl('th_agent_summary') }}</span>
        </template>
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
          <div>
            <div class="text-xs opacity-60">
              {{ tl('th_down_rebate_total') }}
            </div>
            <div class="text-lg font-bold">
              {{ amountThousands(summary.agent?.down_rebate_amount ?? 0, 2) }}
            </div>
          </div>
          <div>
            <div class="text-xs opacity-60">
              {{ tl('th_down2_rebate_total') }}
            </div>
            <div class="text-lg font-bold">
              {{ amountThousands(summary.agent?.down2_rebate_amount ?? 0, 2) }}
            </div>
          </div>
          <div>
            <div class="text-xs opacity-60">{{ tl('th_total_bonus') }}</div>
            <div class="text-lg font-bold">
              {{ amountThousands(summary.agent?.bonus_amount ?? 0, 2) }}
            </div>
          </div>
          <div>
            <div class="text-xs opacity-60">{{ tl('agent_count') }}</div>
            <div class="text-lg font-bold">{{ summary.agent?.total ?? 0 }}</div>
          </div>
        </div>
        <div class="mt-2 text-xs opacity-50">
          {{ tl('th_agent_payout_note') }}
        </div>
      </ElCard>
    </div>

    <ElCard shadow="never">
      <ElTable
        v-loading="loading"
        :data="list"
        :row-class-name="rowClass"
        border
        size="small"
        stripe
        @sort-change="(e: any) => handleSortChange(e)"
      >
        <ElTableColumn :label="tl('identity')" align="center" min-width="80">
          <template #default="{ row }">
            <ElTag :type="row.identity === 'agent' ? 'warning' : 'primary'">
              {{ row.identity === 'agent' ? tl('agent') : tl('member') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_account')"
          prop="parent_username"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.parent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_account')"
          align="center"
          min-width="110"
        >
          <template #default="{ row }">
            <MemberAccountLink
              :label="memberText(row.member_username)"
              :username="row.member_username"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('self_rebate')"
          align="right"
          min-width="110"
          prop="rebate_amount"
          sortable="custom"
        >
          <template #default="{ row }">
            {{ amountThousands(row.rebate_amount, 2) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('down_rebate')"
          align="right"
          min-width="110"
          prop="down_rebate_amount"
          sortable="custom"
        >
          <template #default="{ row }">
            {{ amountThousands(row.down_rebate_amount, 2) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('down2_rebate')"
          align="right"
          min-width="120"
          prop="down2_rebate_amount"
          sortable="custom"
        >
          <template #default="{ row }">
            {{ amountThousands(row.down2_rebate_amount, 2) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('th_total_bonus')"
          align="right"
          min-width="110"
          prop="bonus_amount"
          sortable="custom"
        >
          <template #default="{ row }">
            {{ amountThousands(row.bonus_amount, 2) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('can_withdraw_reward')"
          align="right"
          min-width="130"
          prop="withdraw_able"
          sortable="custom"
        >
          <template #default="{ row }">
            {{ cellText(row.withdraw_able) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('has_withdraw_reward')"
          align="right"
          min-width="130"
          prop="withdraw_amount"
          sortable="custom"
        >
          <template #default="{ row }">
            {{ cellText(row.withdraw_amount) }}
          </template>
        </ElTableColumn>
        <!-- 後端輸出 GMT+8，依所選時區平移顯示（target/tickets#4492） -->
        <ElTableColumn
          :formatter="
            (row: ThreeHierarchyUserRow) => fromApiTime(row.created_at)
          "
          :label="tl('createdate')"
          align="center"
          prop="created_at"
          min-width="150"
          sortable="custom"
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
