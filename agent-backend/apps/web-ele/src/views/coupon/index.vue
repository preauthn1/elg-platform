<script lang="ts" setup>
import type { CouponBatchRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElProgress,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getCouponListApi,
  searchAgentOptionsApi,
  toggleCouponStatusApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';

import CouponDetailDialog from './components/coupon-detail-dialog.vue';
import CouponEditDialog from './components/coupon-edit-dialog.vue';
import CouponEventModeDialog from './components/coupon-event-mode-dialog.vue';

defineOptions({ name: 'CouponIndex' });

const editRef = ref<InstanceType<typeof CouponEditDialog>>();
const detailRef = ref<InstanceType<typeof CouponDetailDialog>>();
const eventModeRef = ref<InstanceType<typeof CouponEventModeDialog>>();
const PER_PAGE = 20;

/** 點數類型 */
function typeText(t?: number | string) {
  return Number(t) === 2 ? tl('rolling_chip') : tl('platform_point');
}

const filters = reactive({
  agent_id: '' as number | string,
  batch_code: '',
  code: '',
});

const agentOptions = ref<Array<{ id: number | string; username: string }>>([]);
const agentLoading = ref(false);

async function searchAgents(q: string) {
  if (!q) return;
  agentLoading.value = true;
  try {
    const res = await searchAgentOptionsApi(q);
    agentOptions.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
  } finally {
    agentLoading.value = false;
  }
}

const loading = ref(false);
const allRows = ref<CouponBatchRow[]>([]);

const {
  handlePageChange,
  handleSearch: handleQuickSearch,
  handleSortChange,
  keyword,
  page,
  pagedRows,
  total,
} = useClientTable(allRows, {
  perPage: PER_PAGE,
  searchFields: (r) => [
    r.batch_code,
    r.agent_username,
    r.created_at,
    r.quantity,
    r.amount,
  ],
});

function usedPercentage(row: CouponBatchRow) {
  const q = Number(row.quantity) || 0;
  const u = Number(row.used_count) || 0;
  return q > 0 ? Math.round((u / q) * 10_000) / 100 : 0;
}

async function loadData() {
  loading.value = true;
  try {
    const data = await getCouponListApi({
      agent_id: filters.agent_id || undefined,
      batch_code: filters.batch_code || undefined,
      code: filters.code || undefined,
    });
    // 預設依建立時間新到舊
    allRows.value = [...(data ?? [])].toSorted((a, b) =>
      String(b.created_at ?? '').localeCompare(String(a.created_at ?? '')),
    );
  } catch {
    allRows.value = [];
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadData();
}

async function toggleStatus(row: CouponBatchRow) {
  const next = Number(row.status) === 1 ? 0 : 1;
  const res = await toggleCouponStatusApi({ id: row.id, status: next });
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    loadData();
  } else {
    ElMessage.error(res.message);
  }
}

onMounted(loadData);
</script>

<template>
  <Page :title="`${tl('event_exclusive_offers')} > ${tl('menu_coupon')}`">
    <div class="mb-3">
      <ElButton type="success" @click="editRef?.open()">
        {{ tl('add') }}
      </ElButton>
    </div>

    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('batch_code')">
            <ElInput v-model="filters.batch_code" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('code')">
            <ElInput v-model="filters.code" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('agent_account')">
            <ElSelect
              v-model="filters.agent_id"
              :debounce="500"
              :loading="agentLoading"
              :remote-method="searchAgents"
              class="w-full"
              clearable
              filterable
              remote
            >
              <ElOption
                v-for="a in agentOptions"
                :key="a.id"
                :label="a.username"
                :value="a.id"
              />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex justify-end">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-3">
        <ElInput
          v-model="keyword"
          :placeholder="tl('search')"
          class="!w-56"
          clearable
          @input="handleQuickSearch"
        />
      </div>
      <ElTable
        v-loading="loading"
        :data="pagedRows"
        border
        size="small"
        stripe
        @sort-change="handleSortChange"
      >
        <ElTableColumn
          :label="tl('batch_code')"
          prop="batch_code"
          min-width="140"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('agent_account')"
          prop="agent_username"
          min-width="110"
          sortable="custom"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('created_time')"
          prop="created_at"
          sortable="custom"
          width="160"
        />
        <ElTableColumn
          :label="tl('type')"
          prop="type"
          sortable="custom"
          width="130"
        >
          <template #default="{ row }">
            {{
              Number(row.reward_mode) === 2
                ? tl('random_reward')
                : typeText(row.type)
            }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('quantity')"
          prop="quantity"
          sortable="custom"
          width="110"
        />
        <ElTableColumn
          :label="tl('denomination')"
          prop="amount"
          sortable="custom"
          width="120"
        >
          <template #default="{ row }">
            {{ Number(row.reward_mode) === 2 ? '-' : row.amount }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('received')" width="160">
          <template #default="{ row }">
            <div class="flex items-center gap-2">
              <ElProgress
                :percentage="usedPercentage(row as CouponBatchRow)"
                :show-text="false"
                :stroke-width="8"
                class="w-20"
              />
              <span>{{ usedPercentage(row as CouponBatchRow) }}%</span>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('status')"
          prop="status"
          sortable="custom"
          width="110"
        >
          <template #default="{ row }">
            <span
              :class="
                Number(row.status) === 1 ? 'text-green-600' : 'text-red-500'
              "
              class="font-bold"
            >
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('operate')"
          align="center"
          width="90"
          fixed="right"
        >
          <template #default="{ row }">
            <ElDropdown trigger="click">
              <ElButton size="small">···</ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem
                    @click="detailRef?.open((row as CouponBatchRow).id)"
                  >
                    {{ tl('detail') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    @click="
                      eventModeRef?.open(
                        (row as CouponBatchRow).id,
                        (row as CouponBatchRow).batch_code,
                      )
                    "
                  >
                    {{ tl('event_mode') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    divided
                    @click="toggleStatus(row as CouponBatchRow)"
                  >
                    {{
                      Number(row.status) === 1 ? tl('disable') : tl('enable')
                    }}
                  </ElDropdownItem>
                </ElDropdownMenu>
              </template>
            </ElDropdown>
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

    <CouponEditDialog ref="editRef" @success="loadData" />
    <CouponDetailDialog ref="detailRef" @success="loadData" />
    <CouponEventModeDialog ref="eventModeRef" @success="loadData" />
  </Page>
</template>
