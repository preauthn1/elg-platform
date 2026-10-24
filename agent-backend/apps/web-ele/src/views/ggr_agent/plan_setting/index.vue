<script lang="ts" setup>
import type { GgrPlanRow } from '#/api';

import { reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElOption,
  ElPopover,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import { getGgrPlanListApi, getGgrPlanViewApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';

import PlanEditDialog from './components/plan-edit-dialog.vue';

defineOptions({ name: 'GgrPlanSetting' });

const PER_PAGE = 20;

const editRef = ref<InstanceType<typeof PlanEditDialog>>();

const loading = ref(false);
const list = ref<GgrPlanRow[]>([]);
const page = ref(1);
const total = ref(0);

const filters = reactive({
  name: '',
  settlement_cycle: '' as number | string,
  status: '' as number | string,
});

/** 層級等級列表 popover：點眼睛時 lazy 載入該方案的綁定摘要 */
const bindingSummary = reactive(
  new Map<number, { condition: string; specials: string[]; vendor: string }>(),
);

function cycleLabel(cycle?: number) {
  if (Number(cycle) === 1) return tl('ggr_cycle_daily');
  if (Number(cycle) === 2) return tl('ggr_cycle_weekly');
  return tl('ggr_cycle_monthly');
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getGgrPlanListApi({
      page: page.value,
      per_page: PER_PAGE,
      ...(filters.name.trim() ? { name: filters.name.trim() } : {}),
      ...(filters.status === '' ? {} : { status: filters.status }),
      ...(filters.settlement_cycle === ''
        ? {}
        : { settlement_cycle: filters.settlement_cycle }),
    });
    const ok = Number(res.code) === 200;
    list.value = ok ? (res.data?.items ?? []) : [];
    total.value = ok ? (res.data?.pagination?.total ?? 0) : 0;
  } finally {
    loading.value = false;
  }
}

async function loadBindingSummary(row: GgrPlanRow) {
  if (bindingSummary.has(row.id)) return;
  const res = await getGgrPlanViewApi(row.id).catch(() => null);
  const detail = res && Number(res.code) === 200 ? res.data : null;
  if (!detail) return;
  bindingSummary.set(row.id, {
    condition: detail.downline_condition_name ?? '-',
    specials: (detail.special_agents ?? []).map(
      (item) =>
        `${item.agent_username ?? item.agent_id}: ${item.vendor_setting_name ?? '-'} / ${item.condition_name ?? '-'}`,
    ),
    vendor: detail.downline_vendor_setting_name ?? '-',
  });
}

function search() {
  page.value = 1;
  loadData();
}

function clear() {
  filters.name = '';
  filters.status = '';
  filters.settlement_cycle = '';
  search();
}

function handlePageChange(next: number) {
  page.value = next;
  loadData();
}

function reload() {
  bindingSummary.clear();
  loadData();
}

loadData();
</script>

<template>
  <Page
    :title="`${tl('menu_ggr_agents_mode')} > ${tl('menu_ggr_plan_setting')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-4">
          <ElFormItem :label="tl('ggr_plan_title')">
            <ElInput v-model="filters.name" clearable @keyup.enter="search" />
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="!w-full">
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('disable')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('ggr_cycle')">
            <ElSelect v-model="filters.settlement_cycle" class="!w-full">
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('ggr_cycle_daily')" :value="1" />
              <ElOption :label="tl('ggr_cycle_weekly')" :value="2" />
              <ElOption :label="tl('ggr_cycle_monthly')" :value="3" />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="clear">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" type="primary" @click="search">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <template #header>
        <div class="flex items-center justify-end">
          <ElButton type="success" @click="editRef?.open()">
            {{ tl('add') }}
          </ElButton>
        </div>
      </template>

      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('ggr_plan_title')"
          align="center"
          min-width="150"
          prop="name"
          show-overflow-tooltip
        />
        <ElTableColumn :label="tl('ggr_cycle')" align="center" width="90">
          <template #default="{ row }">
            {{ cycleLabel(row.settlement_cycle) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('ggr_tier_list')" align="center" width="110">
          <template #default="{ row }">
            <ElPopover
              placement="left"
              trigger="click"
              width="360"
              @before-enter="loadBindingSummary(row as GgrPlanRow)"
            >
              <template #reference>
                <ElButton circle size="small">👁</ElButton>
              </template>
              <div class="text-sm">
                <div class="mb-1 font-medium">
                  {{ tl('ggr_downline_section') }}
                </div>
                <div>
                  {{ tl('menu_ggr_vendor_setting') }}:
                  {{
                    bindingSummary.get((row as GgrPlanRow).id)?.vendor ?? '…'
                  }}
                </div>
                <div>
                  {{ tl('menu_ggr_condition_setting') }}:
                  {{
                    bindingSummary.get((row as GgrPlanRow).id)?.condition ?? '…'
                  }}
                </div>
                <template
                  v-if="
                    bindingSummary.get((row as GgrPlanRow).id)?.specials?.length
                  "
                >
                  <div class="mb-1 mt-2 font-medium">
                    {{ tl('ggr_special_section') }}
                  </div>
                  <div
                    v-for="line in bindingSummary.get((row as GgrPlanRow).id)
                      ?.specials"
                    :key="line"
                  >
                    {{ line }}
                  </div>
                </template>
              </div>
            </ElPopover>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" align="center" width="90">
          <template #default="{ row }">
            <ElTag :type="Number(row.status) === 1 ? 'success' : 'danger'">
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('created_time')"
          align="center"
          prop="created_at"
          width="160"
        />
        <ElTableColumn
          :label="tl('action')"
          align="center"
          width="150"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              size="small"
              @click="editRef?.open((row as GgrPlanRow).id, 'copy')"
            >
              {{ tl('copy') }}
            </ElButton>
            <ElButton
              size="small"
              type="primary"
              @click="editRef?.open((row as GgrPlanRow).id)"
            >
              {{ tl('edit') }}
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

    <PlanEditDialog ref="editRef" @success="reload" />
  </Page>
</template>
