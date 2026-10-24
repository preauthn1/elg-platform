<script lang="ts" setup>
import type {
  RefreshRankingExcludeMemberRow,
  RefreshRankingPeriodOption,
} from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getRefreshRankingPeriodApi,
  getRefreshRankingPeriodDropdownApi,
  updateRefreshRankingExcludeApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands, buildPlainAmounts } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'RefreshRankingExcludeMember' });

const PER_PAGE = 50;
const SHOW_RANKING_OPTIONS = [30, 50, 100, 150, 250, 500, 1000];

const periods = ref<RefreshRankingPeriodOption[]>([]);

const filters = reactive({
  period_id: '' as number | string,
  show_ranking: 100,
  type: 1,
});

const loading = ref(false);
const searched = ref(false);
const list = ref<RefreshRankingExcludeMemberRow[]>([]);
const betCnt = ref<number | string>(0);
const total = ref(0);
const page = ref(1);

function periodLabel(p: RefreshRankingPeriodOption) {
  return `${tl('periods').replace('{N}', String(p.no ?? ''))} - ${p.start_time} ~ ${p.end_time}`;
}

function long2ip(n?: number) {
  if (!n || n <= 0) return '-';

  return [(n >>> 24) & 255, (n >>> 16) & 255, (n >>> 8) & 255, n & 255].join(
    '.',
  );
}

const fmt4 = (v?: null | number | string) => amountThousands(v, 4);

async function loadData() {
  loading.value = true;
  searched.value = true;
  try {
    const res = await getRefreshRankingPeriodApi({
      page: page.value,
      per_page: PER_PAGE,
      period_id: filters.period_id || 0,
      show_ranking: filters.show_ranking,
      type: filters.type,
    });
    if (Number(res.code) === 1000) {
      list.value = res.data?.main_data ?? [];
      betCnt.value = res.data?.bet_cnt ?? 0;
      total.value = res.pagination?.total ?? 0;
    } else {
      list.value = [];
      betCnt.value = 0;
      total.value = 0;
      if (Number(res.code) !== -1) ElMessage.warning(res.message);
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

const exporting = ref(false);
/** 匯出 Excel：欄位（8 欄；不含投注時間欄） */
async function handleExport() {
  exporting.value = true;
  try {
    const params = {
      page: 1,
      per_page: 100_000,
      period_id: filters.period_id || 0,
      show_ranking: filters.show_ranking,
      type: filters.type,
    };
    const res = await getRefreshRankingPeriodApi(params);
    const rows = Number(res.code) === 1000 ? (res.data?.main_data ?? []) : [];
    await buildPlainAmounts(() =>
      exportExcel(
        `exclude_member_${Date.now()}`,
        [
          tl('ranking'),
          tl('member_account'),
          tl('agent_account'),
          tl('bet_amount'),
          tl('register_ip'),
          `${tl('login')}${tl('ip')}`,
          tl('real_name'),
          tl('mobile'),
          tl('status'),
        ],
        rows.map((r) => [
          r.ranking,
          r.member_username,
          r.agent_username,
          fmt4(r.bet_amount),
          long2ip(r.register_ip),
          long2ip(r.last_login_ip),
          r.first_name,
          r.mobile ?? '',
          Number(r.is_calc) === 1
            ? tl('prohibit_calculate')
            : tl('can_calculate'),
        ]),
        {
          audit: { conditions: params },
          title: tl('menu_refresh_ranking_exclude_member'),
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}

async function toggleCalc(row: RefreshRankingExcludeMemberRow) {
  const status = Number(row.is_calc) === 1 ? 2 : 1;
  const res = await updateRefreshRankingExcludeApi({ id: row.id, status });
  if (Number(res.code) === 1000) {
    loadData();
  } else {
    ElMessage.error(res.message);
  }
}

onMounted(() => {
  getRefreshRankingPeriodDropdownApi()
    .then((d) => {
      periods.value = d ?? [];
      if (!filters.period_id && periods.value.length > 0) {
        filters.period_id = periods.value[0]?.id ?? filters.period_id;
      }
    })
    .catch(() => {});
});
</script>

<template>
  <Page
    :title="`${tl('menu_refresh_ranking')} > ${tl('menu_refresh_ranking_exclude_member')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('period')">
            <ElSelect v-model="filters.period_id" class="w-full" filterable>
              <ElOption
                v-for="p in periods"
                :key="p.id"
                :label="periodLabel(p)"
                :value="p.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('show')">
            <ElSelect v-model="filters.show_ranking" class="w-full">
              <ElOption
                v-for="n in SHOW_RANKING_OPTIONS"
                :key="n"
                :label="n"
                :value="n"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="`${tl('show')}${tl('type')}`">
            <ElSelect v-model="filters.type" class="w-full">
              <ElOption :label="tl('all')" :value="1" />
              <ElOption
                :label="`${tl('abnormal_account')}(${tl('real_name')})`"
                :value="2"
              />
              <ElOption
                :label="`${tl('abnormal_account')}(${tl('register_ip')})`"
                :value="3"
              />
              <ElOption
                :label="`${tl('abnormal_account')}(${tl('mobile')})`"
                :value="4"
              />
              <ElOption
                :label="`${tl('abnormal_account')}(${tl('bet')}IP)`"
                :value="5"
              />
              <ElOption
                :label="tl('menu_refresh_ranking_exclude_member')"
                :value="6"
              />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('excel') }}
          </ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard v-if="searched" shadow="never">
      <div class="mb-2 text-right font-medium">
        {{ tl('bet_number') }} : {{ betCnt }}
      </div>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('ranking')" prop="ranking" width="90" />
        <ElTableColumn :label="tl('member_account')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('superior_account')"
          prop="agent_username"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('bet_time')" prop="bet_time" width="160" />
        <ElTableColumn :label="tl('bet_amount')" align="right" width="130">
          <template #default="{ row }">{{ fmt4(row.bet_amount) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('register_ip')" width="130">
          <template #default="{ row }">{{ long2ip(row.register_ip) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="`${tl('login')}${tl('ip')}`" width="130">
          <template #default="{ row }">
            {{ long2ip(row.last_login_ip) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('real_name')"
          prop="first_name"
          min-width="110"
        />
        <ElTableColumn :label="tl('mobile')" prop="mobile" min-width="120" />
        <ElTableColumn align="center" width="120" fixed="right">
          <template #default="{ row }">
            <ElButton
              :type="Number(row.is_calc) === 1 ? 'danger' : 'success'"
              size="small"
              @click="toggleCalc(row as RefreshRankingExcludeMemberRow)"
            >
              {{
                Number(row.is_calc) === 1
                  ? tl('prohibit_calculate')
                  : tl('can_calculate')
              }}
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
