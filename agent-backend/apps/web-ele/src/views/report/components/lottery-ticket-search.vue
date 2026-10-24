<script lang="ts" setup>
import type {
  LotteryDropdownRow,
  LotteryTicketRow,
  LotteryVariant,
} from '#/api';

import { computed, onMounted, ref } from 'vue';

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
} from 'element-plus';

import { getLotteryDropdownApi, getLotteryTicketApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { toHalfWidth } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

const props = defineProps<{
  reportKey: string;
  titleKey: string;
  variant: LotteryVariant;
}>();

const PER_PAGE = 20;

const periods = ref<LotteryDropdownRow[]>([]);
const filters = ref({
  period_id: 0 as number | string,
  ticket_number: '',
});

const loading = ref(false);
const searched = ref(false);
const list = ref<LotteryTicketRow[]>([]);
const total = ref(0);
const page = ref(1);
/** 查詢當下的關鍵字（用於結果高亮） */
const keyword = ref('');

function periodLabel(r: LotteryDropdownRow) {
  return `${tl('periods').replace('{N}', String(r.no))} - ${r.start_time} ~ ${r.end_time}`;
}

/** 把命中的票號片段標紅 */
function highlightParts(ticket = '') {
  const k = keyword.value;
  if (!k || !ticket.includes(k)) return [{ hit: false, text: ticket }];
  const parts: { hit: boolean; text: string }[] = [];
  let rest = ticket;
  let idx = rest.indexOf(k);
  while (idx !== -1) {
    if (idx > 0) parts.push({ hit: false, text: rest.slice(0, idx) });
    parts.push({ hit: true, text: k });
    rest = rest.slice(idx + k.length);
    idx = rest.indexOf(k);
  }
  if (rest) parts.push({ hit: false, text: rest });
  return parts;
}

const periodTitle = computed(
  () =>
    `${tl('statistical_report')} > ${tl(props.reportKey)} > ${tl(props.titleKey)}`,
);

async function loadData() {
  loading.value = true;
  searched.value = true;
  keyword.value = toHalfWidth(filters.value.ticket_number);
  try {
    const res = await getLotteryTicketApi(props.variant, {
      page: page.value,
      period_id: filters.value.period_id,
      ticket_number: keyword.value,
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

function handlePageChange(p: number) {
  page.value = p;
  loadData();
}

const exporting = ref(false);
/** 匯出 Excel：欄位/17Ticket（5 欄） */
async function handleExport() {
  exporting.value = true;
  try {
    const params = {
      page: 1,
      per_page: 100_000,
      period_id: filters.value.period_id,
      ticket_number: toHalfWidth(filters.value.ticket_number),
    };
    const res = await getLotteryTicketApi(props.variant, params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await exportExcel(
      `${props.variant}_ticket_${Date.now()}`,
      [
        tl('num'),
        tl('period'),
        tl('lottery_ticket_no'),
        tl('agent_account'),
        tl('member_account'),
      ],
      rows.map((r) => [
        r.id,
        tl('periods').replace('{N}', String(r.no)),
        r.ticket_number,
        r.agent_username,
        r.member_username,
      ]),
      { audit: { conditions: params }, title: tl(props.titleKey) },
    );
  } finally {
    exporting.value = false;
  }
}

onMounted(() => {
  // 期數下拉與查詢是兩支互相獨立的 API（對應舊版 curlPost 各自呼叫），
  // 下拉失敗不應擋到查詢自動觸發
  getLotteryDropdownApi(props.variant)
    .then((data) => {
      periods.value = data ?? [];
    })
    .catch(() => {
      // 下拉載入失敗不阻擋頁面
    });

  // 舊版 sr17/sr17_6d 一進站就帶 period_id=0（全部期數）自動查一次；
  // sr16 要點查詢才會查（對應舊版 sr16_ticket_search.php 的 action=1 才觸發）
  if (props.variant !== 'sr16') {
    handleSearch();
  }
});
</script>

<template>
  <Page :title="periodTitle">
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('period')">
            <ElSelect v-model="filters.period_id" class="w-full" filterable>
              <ElOption :label="tl('all')" :value="0" />
              <ElOption
                v-for="r in periods"
                :key="r.id"
                :label="periodLabel(r)"
                :value="r.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('lottery_ticket_no')">
            <div class="w-full">
              <ElInput v-model="filters.ticket_number" clearable />
              <span class="text-xs text-red-500">
                *{{ tl('least_four_numbers') }}
              </span>
            </div>
          </ElFormItem>
          <ElFormItem>
            <div class="flex gap-2">
              <ElButton :loading="loading" native-type="submit" type="primary">
                {{ tl('search') }}
              </ElButton>
              <ElButton
                :loading="exporting"
                type="success"
                @click="handleExport"
              >
                {{ tl('excel') }}
              </ElButton>
            </div>
          </ElFormItem>
        </div>
      </ElForm>
    </ElCard>

    <ElCard v-if="searched" shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('num')" prop="id" width="90" />
        <ElTableColumn :label="tl('period')" min-width="100">
          <template #default="{ row }">
            {{ tl('periods').replace('{N}', String(row.no)) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('lottery_ticket_no')" min-width="160">
          <template #default="{ row }">
            <span
              v-for="(p, i) in highlightParts(row.ticket_number)"
              :key="i"
              :class="{ 'text-red-500': p.hit }"
              >{{ p.text }}</span
            >
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_account')"
          prop="agent_username"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
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
