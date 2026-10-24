<script lang="ts" setup>
import type { BankTransactionRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElButtonGroup,
  ElCard,
  ElDatePicker,
  ElForm,
  ElFormItem,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getBankTransactionAccountsApi,
  getBankTransactionDetailApi,
  getBankTransactionTotalApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { SIX_TYPES_KINDS, sixTypesQuickRange } from '#/utils/quick-range';
import { fromApiTime, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'BankTransactionIndex' });

const PER_PAGE = 20;
const userStore = useUserStore();

const [defStart, defEnd] = sixTypesQuickRange('today');

const filters = reactive({
  account_id: 0 as number | string,
  end_time: defEnd,
  source_temp: 0 as number | string,
  start_time: defStart,
});

const accounts = ref<
  Array<{ account_id: number | string; account_nickname: string }>
>([]);
const loading = ref(false);
const searched = ref(false);
const summary = ref<{
  total_deposit: number | string;
  total_withdrawl: number | string;
}>({ total_deposit: 0, total_withdrawl: 0 });
const list = ref<BankTransactionRow[]>([]);
const total = ref(0);
const page = ref(1);

function toNum(v: number | string): number {
  return Number(v) || 0;
}

function setQuick(kind: string) {
  const [s, e] = sixTypesQuickRange(kind);
  filters.start_time = s;
  filters.end_time = e;
}

function payload() {
  return {
    account_id: filters.account_id,
    end_date: toApiTime(`${filters.end_time}:59`),
    source_temp: filters.source_temp,
    start_date: toApiTime(`${filters.start_time}:00`),
  };
}

async function loadData() {
  loading.value = true;
  try {
    const [totalRes, detailRes] = await Promise.all([
      getBankTransactionTotalApi(payload()),
      getBankTransactionDetailApi({
        ...payload(),
        page: page.value,
        per_page: PER_PAGE,
      }),
    ]);
    if (Number(totalRes.code) === 1000) {
      summary.value = totalRes.data?.[0] ?? {
        total_deposit: 0,
        total_withdrawl: 0,
      };
    }
    if (Number(detailRes.code) === 1000) {
      list.value = detailRes.data ?? [];
      total.value = detailRes.pagination?.total ?? 0;
    } else {
      list.value = [];
      total.value = 0;
    }
    searched.value = true;
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
/** 匯出 Excel：欄位（12 欄；僅明細，不含彙總） */
async function handleExport() {
  exporting.value = true;
  try {
    const params = { ...payload(), page: 1, per_page: 100_000 };
    const res = await getBankTransactionDetailApi(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await exportExcel(
      `bank_transaction_${Date.now()}`,
      [
        tl('num'),
        tl('ordernum'),
        tl('date'),
        tl('account_code'),
        tl('agent_account'),
        `${tl('deposit')}/${tl('withdrawal')}`,
        tl('member_account'),
        tl('change_point'),
        tl('moment_point'),
        tl('source'),
        tl('execution_account'),
        tl('memo'),
      ],
      rows.map((r) => [
        r.id,
        r.order_id,
        fromApiTime(r.created_at),
        r.account_nickname,
        r.agent_name,
        Number(r.source) === 1 || Number(r.source) === 3
          ? tl('deposit')
          : tl('withdrawal'),
        r.member_name,
        toNum(r.point ?? 0).toLocaleString(),
        toNum(r.before_amount ?? 0).toLocaleString(),
        Number(r.source) === 1 || Number(r.source) === 2
          ? tl('cs')
          : tl('auto_transfer'),
        r.operator_username,
        r.remark,
      ]),
      {
        audit: { conditions: params },
        dateRange: [filters.start_time, filters.end_time],
        title: tl('menu_bank_transaction'),
      },
    );
  } finally {
    exporting.value = false;
  }
}

onMounted(async () => {
  const uid = (userStore.userInfo as any)?.id;
  accounts.value =
    (await getBankTransactionAccountsApi(uid).catch(() => [])) ?? [];
});
</script>

<template>
  <Page
    :title="`${tl('menu_bank_management')} > ${tl('menu_bank_transaction')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2 lg:grid-cols-4">
          <ElFormItem :label="tl('account_nickname')">
            <ElSelect v-model="filters.account_id" class="w-full" filterable>
              <ElOption :label="tl('all')" :value="0" />
              <ElOption
                v-for="a in accounts"
                :key="a.account_id"
                :label="a.account_nickname"
                :value="a.account_id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('source')">
            <ElSelect v-model="filters.source_temp" class="w-full">
              <ElOption :label="tl('all')" :value="0" />
              <ElOption :label="tl('cs')" :value="1" />
              <ElOption :label="tl('auto_transfer')" :value="2" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('label_start_time')">
            <ElDatePicker
              v-model="filters.start_time"
              class="!w-full"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
          <ElFormItem :label="tl('label_end_time')">
            <ElDatePicker
              v-model="filters.end_time"
              class="!w-full"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <ElButtonGroup>
            <ElButton
              v-for="k in SIX_TYPES_KINDS"
              :key="k"
              size="small"
              @click="setQuick(k)"
            >
              {{ tl(k) }}
            </ElButton>
          </ElButtonGroup>
          <div class="flex gap-2">
            <ElButton :loading="exporting" type="success" @click="handleExport">
              {{ tl('excel') }}
            </ElButton>
            <ElButton :loading="loading" type="primary" @click="handleSearch">
              {{ tl('search') }}
            </ElButton>
          </div>
        </div>
      </ElForm>
    </ElCard>

    <ElCard v-if="searched" class="mb-3" shadow="never">
      <ElTable :data="[summary]" border size="small">
        <ElTableColumn :label="tl('total_deposit')" align="right">
          <template #default="{ row }">
            {{ toNum(row.total_deposit).toLocaleString() }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('total_withdrawl')" align="right">
          <template #default="{ row }">
            {{ toNum(row.total_withdrawl).toLocaleString() }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('total_deposit')} - ${tl('total_withdrawl')}`"
          align="right"
        >
          <template #default="{ row }">
            {{
              (
                toNum(row.total_deposit) - toNum(row.total_withdrawl)
              ).toLocaleString()
            }}
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-2 font-medium">{{ tl('detaillist') }}</div>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('num')" prop="id" width="80" />
        <ElTableColumn
          :label="tl('ordernum')"
          min-width="120"
          prop="order_id"
        />
        <ElTableColumn
          :formatter="(row: BankTransactionRow) => fromApiTime(row.created_at)"
          :label="tl('date')"
          min-width="150"
        />
        <ElTableColumn :label="tl('account_nickname')" min-width="120">
          <template #default="{ row }">
            <b class="text-green-600">{{ row.account_nickname }}</b>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_account')"
          min-width="110"
          prop="agent_name"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_name" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('deposit')}/${tl('withdrawal')}`"
          min-width="90"
        >
          <template #default="{ row }">
            {{
              Number(row.source) === 1 || Number(row.source) === 3
                ? tl('deposit')
                : tl('withdrawal')
            }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_name" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('change_point')"
          align="right"
          min-width="110"
        >
          <template #default="{ row }">
            {{ toNum(row.point).toLocaleString() }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('moment_point')"
          align="right"
          min-width="110"
        >
          <template #default="{ row }">
            {{ toNum(row.before_amount).toLocaleString() }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('source')" min-width="90">
          <template #default="{ row }">
            {{
              Number(row.source) === 1 || Number(row.source) === 2
                ? tl('cs')
                : tl('auto_transfer')
            }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('execution_account')"
          min-width="110"
          prop="operator_username"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.operator_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" min-width="120" prop="remark" />
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
