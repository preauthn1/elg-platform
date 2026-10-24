<script lang="ts" setup>
import type {
  RefreshRankingExcludeRow,
  RefreshRankingPayoutRow,
  RefreshRankingRankingRow,
} from '#/api';

import { onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElMessage,
  ElMessageBox,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getRefreshRankingPayoutExcludeApi,
  getRefreshRankingPayoutListApi,
  getRefreshRankingPayoutRankingApi,
  payoutRefreshRankingApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'RefreshRankingPayout' });

const PER_PAGE = 20;

function long2ip(n?: number) {
  if (!n || n <= 0) return '-';

  return [(n >>> 24) & 255, (n >>> 16) & 255, (n >>> 8) & 255, n & 255].join(
    '.',
  );
}

const { handlePageChange, list, loadData, loading, page, total } =
  useListQuery<RefreshRankingPayoutRow>(
    ({ page, per_page }) => getRefreshRankingPayoutListApi({ page, per_page }),
    { perPage: PER_PAGE, showError: true },
  );

/** 結算派發 */
async function handlePayout(row: RefreshRankingPayoutRow) {
  try {
    await ElMessageBox.confirm('', `${tl('settle')} - ${tl('are_you_sure')}`, {
      cancelButtonText: tl('cancel'),
      confirmButtonText: tl('confirm'),
      type: 'warning',
    });
  } catch {
    return;
  }
  const res = await payoutRefreshRankingApi(row.id);
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    loadData();
  } else {
    ElMessage.error(res.message);
  }
}

/** 派發名單 modal */
const rankingVisible = ref(false);
const rankingLoading = ref(false);
const rankingId = ref<number | string>('');
const rankingList = ref<RefreshRankingRankingRow[]>([]);
const rankingTotal = ref(0);
const rankingPage = ref(1);

async function loadRanking() {
  rankingLoading.value = true;
  try {
    const res = await getRefreshRankingPayoutRankingApi({
      id: rankingId.value,
      page: rankingPage.value,
      per_page: PER_PAGE,
    });
    if (Number(res.code) === 1000) {
      rankingList.value = res.data ?? [];
      rankingTotal.value = res.pagination?.total ?? 0;
    } else {
      rankingList.value = [];
      rankingTotal.value = 0;
    }
  } finally {
    rankingLoading.value = false;
  }
}

function openRanking(row: RefreshRankingPayoutRow) {
  rankingId.value = row.id;
  rankingPage.value = 1;
  rankingVisible.value = true;
  loadRanking();
}

function rankingPageChange(p: number) {
  rankingPage.value = p;
  loadRanking();
}

/** 排除名單 modal */
const excludeVisible = ref(false);
const excludeLoading = ref(false);
const excludeId = ref<number | string>('');
const excludeList = ref<RefreshRankingExcludeRow[]>([]);
const excludeTotal = ref(0);
const excludePage = ref(1);

async function loadExclude() {
  excludeLoading.value = true;
  try {
    const res = await getRefreshRankingPayoutExcludeApi({
      id: excludeId.value,
      page: excludePage.value,
      per_page: PER_PAGE,
    });
    if (Number(res.code) === 1000) {
      excludeList.value = res.data ?? [];
      excludeTotal.value = res.pagination?.total ?? 0;
    } else {
      excludeList.value = [];
      excludeTotal.value = 0;
    }
  } finally {
    excludeLoading.value = false;
  }
}

function openExclude(row: RefreshRankingPayoutRow) {
  excludeId.value = row.id;
  excludePage.value = 1;
  excludeVisible.value = true;
  loadExclude();
}

function excludePageChange(p: number) {
  excludePage.value = p;
  loadExclude();
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('menu_refresh_ranking')} > ${tl('menu_refresh_ranking_payout_prize')}`"
  >
    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('label_start_time')"
          prop="start_time"
          width="170"
        />
        <ElTableColumn
          :label="tl('label_end_time')"
          prop="end_time"
          width="170"
        />
        <ElTableColumn :label="tl('period')" prop="no" min-width="100" />
        <ElTableColumn
          :label="tl('features')"
          align="right"
          width="220"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              v-if="Number(row.status) === 0"
              :title="tl('payout')"
              size="small"
              type="danger"
              @click="handlePayout(row as RefreshRankingPayoutRow)"
            >
              {{ tl('payout') }}
            </ElButton>
            <ElButton
              size="small"
              type="primary"
              @click="openRanking(row as RefreshRankingPayoutRow)"
            >
              {{ tl('ranking') }}
            </ElButton>
            <ElButton
              size="small"
              type="danger"
              @click="openExclude(row as RefreshRankingPayoutRow)"
            >
              {{ tl('menu_refresh_ranking_exclude_member') }}
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

    <ElDialog
      v-model="rankingVisible"
      :title="`${tl('payout')}${tl('member_account')}`"
      width="700px"
    >
      <ElTable
        v-loading="rankingLoading"
        :data="rankingList"
        border
        size="small"
        stripe
      >
        <ElTableColumn :label="tl('ranking')" prop="ranking" width="90" />
        <ElTableColumn
          :label="tl('superior_account')"
          prop="agent_username"
          min-width="120"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="120">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('bet_amount')" align="right" width="140">
          <template #default="{ row }">
            {{ amountThousands(row.bet_amount, 2) }}
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <TablePagination
        :current-page="rankingPage"
        :page-size="PER_PAGE"
        :total="rankingTotal"
        @current-change="rankingPageChange"
      />
    </ElDialog>

    <ElDialog
      v-model="excludeVisible"
      :title="tl('menu_refresh_ranking_exclude_member')"
      width="700px"
    >
      <ElTable
        v-loading="excludeLoading"
        :data="excludeList"
        border
        size="small"
        stripe
      >
        <ElTableColumn
          :label="tl('superior_account')"
          prop="agent_username"
          min-width="120"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="120">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('real_name')"
          prop="first_name"
          min-width="110"
        />
        <ElTableColumn
          :label="`${tl('register_ip')}/${tl('bet')}IP`"
          width="220"
        >
          <template #default="{ row }">
            {{ long2ip(row.register_ip) }}/{{ long2ip(row.last_login_ip) }}
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <TablePagination
        :current-page="excludePage"
        :page-size="PER_PAGE"
        :total="excludeTotal"
        @current-change="excludePageChange"
      />
    </ElDialog>
  </Page>
</template>
