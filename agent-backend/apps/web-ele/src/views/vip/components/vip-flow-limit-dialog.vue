<script lang="ts" setup>
import type { VipFlowLimitRow } from '#/api';

import { ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElMessage,
  ElMessageBox,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { cancelVipFlowLimitApi, getVipFlowLimitApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

defineOptions({ name: 'VipFlowLimitDialog' });

const PER_PAGE = 10;

const visible = ref(false);
const memberId = ref<number | string>('');

const { handlePageChange, list, loadData, loading, page, total } =
  useListQuery<VipFlowLimitRow>(
    ({ page, per_page }) =>
      getVipFlowLimitApi({ member_id: memberId.value, page, per_page }),
    { perPage: PER_PAGE },
  );

const STATUS_CLASS: Record<number, string> = {
  0: 'text-[#ff3c7e]',
  1: 'text-[#2d8c27]',
  9: 'text-[#a326cc]',
};

function statusClass(s?: number) {
  return STATUS_CLASS[Number(s)] ?? '';
}

function open(id: number | string) {
  memberId.value = id;
  page.value = 1;
  list.value = [];
  total.value = 0;
  visible.value = true;
  loadData();
}

async function cancelFlowLimit(row: VipFlowLimitRow) {
  let remark: string;
  try {
    const { value } = await ElMessageBox.prompt(
      '',
      tl('please_write_memo_info'),
      {
        confirmButtonText: tl('submit'),
        cancelButtonText: tl('cancel'),
        inputType: 'text',
      },
    );
    remark = value ?? '';
  } catch {
    return;
  }
  const res = await cancelVipFlowLimitApi({ id: row.id, remark });
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    page.value = 1;
    loadData();
  } else {
    ElMessage.error(`${res.message}.${res.data ?? ''}`);
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('flow_limit')"
    width="1140"
    align-center
  >
    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn :label="tl('deposit_type')" min-width="140">
        <template #default="{ row }">
          {{ tl(`vip_deposit_type.${row.type}`) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('unlimit_bonus')" align="right" min-width="120">
        <template #default="{ row }">
          {{ amountThousands(row.amount) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('flow_limit')" align="right" min-width="120">
        <template #default="{ row }">
          {{ amountThousands(row.flow_limit) }}
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('completed_amount')"
        align="right"
        min-width="120"
      >
        <template #default="{ row }">
          {{
            Number(row.status) === 9
              ? '-'
              : amountThousands(row.valid_flow_limit)
          }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('status')" align="center" width="120">
        <template #default="{ row }">
          <span :class="statusClass(row.status)">
            {{ tl(`vip_flow_limit_status.${row.status}`) }}
          </span>
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="`${tl('created_time')}／${tl('operating_time')}`"
        align="center"
        width="170"
      >
        <template #default="{ row }">
          <div>{{ row.created_at }}</div>
          <div>{{ row.updated_at }}</div>
        </template>
      </ElTableColumn>
      <ElTableColumn align="center" width="100">
        <template #default="{ row }">
          <ElButton
            v-if="Number(row.status) === 0"
            size="small"
            type="primary"
            @click="cancelFlowLimit(row as VipFlowLimitRow)"
          >
            {{ tl('delete') }}
          </ElButton>
        </template>
      </ElTableColumn>
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>
    <TablePagination
      :current-page="page"
      :page-size="PER_PAGE"
      :total="total"
      layout="total, prev, pager, next"
      @current-change="handlePageChange"
    />
  </ElDialog>
</template>
