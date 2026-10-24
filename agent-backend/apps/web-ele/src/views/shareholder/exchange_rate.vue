<script lang="ts" setup>
import type { ShareholderExchangeRateLog } from '#/api';

import { computed, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getShareholderExchangeRateApi,
  getShareholderExchangeRateLogApi,
  setShareholderExchangeRateApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { hasMenuCode } from '#/router/agent-menu';

defineOptions({ name: 'ShareholderExchangeRate' });

const PER_PAGE = 20;

const userStore = useUserStore();
const selfId = Number((userStore.userInfo as any)?.id ?? 0);

const canCreate = hasMenuCode('shareholder_exchange_rate');

const current = ref<{
  currencyCode?: string;
  organization?: string;
  rate?: number | string;
}>({});

const loading = ref(false);
const list = ref<ShareholderExchangeRateLog[]>([]);
const total = ref(0);
const page = ref(1);

const dialogVisible = ref(false);
const saving = ref(false);
const rateInput = ref('');

const fmtTime = (v?: string) =>
  v ? dayjs(v).add(8, 'hour').format('YYYY-MM-DD HH:mm:ss') : '';

const title = computed(
  () =>
    `${tl('shareholder_management')} > ${tl('menu_shareholder_exchange_rate')}`,
);

async function loadCurrent() {
  const res = await getShareholderExchangeRateApi();
  if (Number(res.code) === 1000) {
    current.value = res.data ?? {};
  }
}

async function loadLog() {
  loading.value = true;
  try {
    const res = await getShareholderExchangeRateLogApi({ page: page.value });
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

function handlePageChange(p: number) {
  page.value = p;
  loadLog();
}

function showCreateModal() {
  rateInput.value = String(current.value.rate ?? '');
  dialogVisible.value = true;
}

async function saveCreate() {
  saving.value = true;
  try {
    const res = await setShareholderExchangeRateApi({
      member_id: selfId,
      rate: rateInput.value,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      dialogVisible.value = false;
      page.value = 1;
      await Promise.all([loadCurrent(), loadLog()]);
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}

loadCurrent();
loadLog();
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <div class="space-y-2">
        <div class="font-medium">
          {{ tl('organization') }}：<span>{{ current.organization }}</span>
        </div>
        <div class="font-medium">
          {{ tl('currency_code') }}：<span>{{ current.currencyCode }}</span>
        </div>
        <div class="font-medium">
          {{ tl('current_exchange_rate') }}(USD)：<span>{{
            current.rate
          }}</span>
        </div>
        <div v-if="canCreate" class="pt-2">
          <ElButton type="primary" @click="showCreateModal">
            {{ tl('setting') }}
          </ElButton>
        </div>
      </div>
    </ElCard>

    <ElCard shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('operation_record') }}</span>
      </template>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('time')" min-width="160">
          <template #default="{ row }">{{ fmtTime(row.created_at) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('before_change')"
          prop="before_change"
          min-width="120"
        />
        <ElTableColumn
          :label="tl('after_change')"
          prop="after_change"
          min-width="120"
        />
        <ElTableColumn :label="tl('operator')" prop="admin" min-width="120">
          <template #default="{ row }">
            <AgentAccount :username="row.admin" />
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

    <ElDialog v-model="dialogVisible" :title="tl('btn_update')" width="500px">
      <ElForm label-width="auto" @submit.prevent="saveCreate">
        <ElFormItem :label="tl('current_exchange_rate')">
          <ElInput v-model="rateInput" clearable @keyup.enter="saveCreate" />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="dialogVisible = false">{{ tl('cancel') }}</ElButton>
        <ElButton :loading="saving" type="primary" @click="saveCreate">
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
