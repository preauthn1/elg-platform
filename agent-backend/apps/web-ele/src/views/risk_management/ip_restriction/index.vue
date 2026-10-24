<script lang="ts" setup>
import type { RiskIpRestrictionBlockItem, RiskIpRestrictionRow } from '#/api';

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

import { getRiskIpRestrictionListApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';

import IpRestrictionEditDialog from './components/ip-restriction-edit-dialog.vue';

defineOptions({ name: 'RiskIpRestriction' });

const PER_PAGE = 20;

const editRef = ref<InstanceType<typeof IpRestrictionEditDialog>>();

const loading = ref(false);
const list = ref<RiskIpRestrictionRow[]>([]);
const page = ref(1);
const total = ref(0);

/** 阻擋項目文字化：後端回傳的 value 陣列 → 語系標籤，以逗號串接（順序由後端固定） */
function blockItemsText(items?: RiskIpRestrictionBlockItem[]) {
  if (!items || items.length === 0) return '-';

  return items.map((item) => tl(`ip_restriction_block_${item}`)).join('、');
}

const filters = reactive({
  ip: '',
  status: '' as number | string,
});

async function loadData() {
  loading.value = true;
  try {
    const res = await getRiskIpRestrictionListApi({
      page: page.value,
      per_page: PER_PAGE,
      ...(filters.ip.trim() ? { ip: filters.ip.trim() } : {}),
      ...(filters.status === '' ? {} : { status: filters.status }),
    });
    const ok = Number(res.code) === 200;
    list.value = ok ? (res.data?.items ?? []) : [];
    total.value = ok ? (res.data?.pagination?.total ?? 0) : 0;
  } finally {
    loading.value = false;
  }
}

function search() {
  page.value = 1;
  loadData();
}

function clear() {
  filters.ip = '';
  filters.status = '';
  search();
}

function handlePageChange(next: number) {
  page.value = next;
  loadData();
}

loadData();
</script>

<template>
  <Page
    :title="`${tl('menu_risk_management')} > ${tl('menu_risk_ip_restriction')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-4">
          <ElFormItem :label="tl('ip_restriction_ip')">
            <ElInput v-model="filters.ip" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="!w-full">
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('close')" :value="0" />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="clear">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
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
          :label="tl('number')"
          align="center"
          prop="id"
          width="80"
        />
        <ElTableColumn
          :label="tl('ip_restriction_ip')"
          align="center"
          min-width="150"
          prop="ip"
        />
        <ElTableColumn
          :label="tl('ip_restriction_block_items')"
          align="center"
          min-width="160"
        >
          <template #default="{ row }">
            {{ blockItemsText((row as RiskIpRestrictionRow).block_items) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('ip_restriction_repeat_count')"
          align="center"
          prop="repeat_count"
          width="110"
        />
        <ElTableColumn
          :label="tl('memo')"
          align="center"
          min-width="140"
          prop="remark"
          show-overflow-tooltip
        />
        <ElTableColumn :label="tl('status')" align="center" width="90">
          <template #default="{ row }">
            <ElTag :type="Number(row.status) === 1 ? 'success' : 'danger'">
              {{ Number(row.status) === 1 ? tl('enable') : tl('close') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('operate_agent_username')"
          align="center"
          min-width="120"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.updated_by" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('updated_at')"
          align="center"
          prop="updated_at"
          width="160"
        />
        <ElTableColumn
          :label="tl('action')"
          align="center"
          width="100"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              size="small"
              @click="editRef?.open((row as RiskIpRestrictionRow).id)"
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

    <IpRestrictionEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
