<script lang="ts" setup>
import type { UnlimitBetRankingRow } from '#/api';

import { computed, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getUnlimitBetRankingApi, updateUnlimitCancelApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'UnlimitBetRanking' });

const PER_PAGE = 30;

const filters = reactive({ username: '', fuzzy_query: false });

const loading = ref(false);
const toggling = ref(false);
const list = ref<UnlimitBetRankingRow[]>([]);
const total = ref(0);
const page = ref(1);

const fmt = (v?: number | string) => amountThousands(v, 2);

const title = computed(
  () => `${tl('menu_reward_mode_management')} > ${tl('menu_bet_ranking')}`,
);

function rankIndex(index: number) {
  return (page.value - 1) * PER_PAGE + index + 1;
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getUnlimitBetRankingApi({
      fuzzy_query: filters.fuzzy_query ? 1 : 0,
      page: page.value,
      username: filters.username,
    });
    if (Number(res.code) === 1000) {
      list.value = res.data ?? [];
      total.value = res.pagination?.total ?? 0;
    } else {
      list.value = [];
      total.value = 0;
      ElMessage.error(res.message || tl('system_error'));
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
  filters.username = '';
  filters.fuzzy_query = false;
  handleSearch();
}

function handlePageChange(p: number) {
  page.value = p;
  loadData();
}

async function toggleCalculate(row: UnlimitBetRankingRow) {
  const next = Number(row.status) === 1 ? 0 : 1;
  toggling.value = true;
  try {
    const res = await updateUnlimitCancelApi({
      bet: row.bet ?? 0,
      id: row.id ?? '',
      status: next,
    });
    if (Number(res.code) === 1000) {
      loadData();
    } else if (String(res.code) === '2002') {
      ElMessage.warning(String(res.data ?? ''));
    } else {
      ElMessage.error(res.message || tl('system_error'));
    }
  } finally {
    toggling.value = false;
  }
}

loadData();
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('member_account')">
            <div class="w-full">
              <ElInput v-model="filters.username" clearable />
              <ElCheckbox v-model="filters.fuzzy_query" class="mt-1">
                {{ tl('fuzzy_query') }}
              </ElCheckbox>
            </div>
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
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('ranking')" min-width="90">
          <template #default="{ $index }">{{ rankIndex($index) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="140">
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('event_action.bet')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">{{ fmt(row.bet) }}</template>
        </ElTableColumn>
        <ElTableColumn label="" align="center" min-width="120" fixed="right">
          <template #default="{ row }">
            <ElButton
              v-if="Number((row as UnlimitBetRankingRow).status) === 1"
              :loading="toggling"
              type="danger"
              @click="toggleCalculate(row as UnlimitBetRankingRow)"
            >
              {{ tl('prohibit_calculate') }}
            </ElButton>
            <ElButton
              v-else
              :loading="toggling"
              type="success"
              @click="toggleCalculate(row as UnlimitBetRankingRow)"
            >
              {{ tl('can_calculate') }}
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
