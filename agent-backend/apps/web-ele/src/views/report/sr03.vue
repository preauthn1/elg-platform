<script lang="ts" setup>
import type { Sr03Params, Sr03Row } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getSr03AgentApi, getSr03MemberApi, getSr03UserApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountPlain, amountThousands } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'ReportSr03' });

const filters = reactive({
  date_range: [
    dayjs().subtract(7, 'day').format('YYYY-MM-DD'),
    dayjs().format('YYYY-MM-DD'),
  ] as string[],
  username: '',
});

const loading = ref(false);
const userRows = ref<Sr03Row[]>([]);
const agentRows = ref<Sr03Row[]>([]);
const memberRows = ref<Sr03Row[]>([]);

const fmtAmount = (v?: number | string) => amountThousands(v, 4);

function diff(row: Sr03Row) {
  return fmtAmount(Number(row.total_in ?? 0) - Number(row.total_out ?? 0));
}

async function loadData() {
  const [start, end] = filters.date_range ?? [];
  if (!start || !end) return;
  const params: Sr03Params = { end_date: end, source: 0, start_date: start };
  if (filters.username) params.agent_username = filters.username;
  loading.value = true;
  try {
    const [u, a, m] = await Promise.all([
      getSr03UserApi(params).catch(() => []),
      getSr03AgentApi(params).catch(() => []),
      getSr03MemberApi(params).catch(() => []),
    ]);
    userRows.value = u ?? [];
    agentRows.value = a ?? [];
    memberRows.value = m ?? [];
  } finally {
    loading.value = false;
  }
}

const exporting = ref(false);
/** 匯出 Excel（僅匯出會員表） */
async function handleExport() {
  exporting.value = true;
  try {
    await exportExcel(
      `sr03_${Date.now()}`,
      [
        tl('member_account'),
        tl('member_total_deposit'),
        tl('member_total_withdrawl'),
        `${tl('total_deposit')}-${tl('total_withdrawl')}`,
        tl('agent_account'),
      ],
      memberRows.value.map((r) => [
        r.member_username,
        amountPlain(r.total_in, 4),
        amountPlain(r.total_out, 4),
        diff(r),
        r.agent_username,
      ]),
      {
        audit: { conditions: { ...filters } },
        title: tl('menu_report_sr03'),
        tzOffset: 8,
      },
    );
  } finally {
    exporting.value = false;
  }
}

onMounted(loadData);
</script>

<template>
  <Page :title="`${tl('menu_statistics')} > ${tl('menu_report_sr03')}`">
    <!-- 查詢 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="loadData">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields v-model="filters.date_range" :fixed-offset="8" />
            <FixedTimezoneHint />
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('excel') }}
          </ElButton>
          <ElButton :loading="loading" type="primary" @click="loadData">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <!-- 總公司 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('header_customer') }}</span>
      </template>
      <ElTable v-loading="loading" :data="userRows" border size="small" stripe>
        <ElTableColumn :label="tl('head_customer_account')" min-width="140">
          <template #default>
            <span class="text-info font-medium">{{ tl('admin') }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_total_deposit')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">{{ fmtAmount(row.total_in) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_total_withdrawl')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">{{ fmtAmount(row.total_out) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('total_deposit')}-${tl('total_withdrawl')}`"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">{{ diff(row as Sr03Row) }}</template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>

    <!-- 代理 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('agent_name') }}</span>
      </template>
      <ElTable v-loading="loading" :data="agentRows" border size="small" stripe>
        <ElTableColumn :label="tl('agent_name')" min-width="140">
          <template #default="{ row }">
            <span class="text-info font-medium">
              <AgentAccount :username="row.agent_username" />
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_total_deposit')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">{{ fmtAmount(row.total_in) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_total_withdrawl')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">{{ fmtAmount(row.total_out) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('total_deposit')}-${tl('total_withdrawl')}`"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">{{ diff(row as Sr03Row) }}</template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>

    <!-- 會員 -->
    <ElCard shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('member_list') }}</span>
      </template>
      <ElTable
        v-loading="loading"
        :data="memberRows"
        border
        size="small"
        stripe
      >
        <ElTableColumn :label="tl('member_account')" min-width="140">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_total_deposit')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">{{ fmtAmount(row.total_in) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_total_withdrawl')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">{{ fmtAmount(row.total_out) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('total_deposit')}-${tl('total_withdrawl')}`"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">{{ diff(row as Sr03Row) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_account')"
          prop="agent_username"
          min-width="120"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>
  </Page>
</template>
