<script lang="ts" setup>
import type { VipExpLogRow } from '#/api';

import { ref } from 'vue';

import { ElDialog, ElTable, ElTableColumn } from 'element-plus';

import { getVipExpLogApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

defineOptions({ name: 'VipExpLogDialog' });

const PER_PAGE = 10;

const visible = ref(false);
const memberId = ref<number | string>('');

const { handlePageChange, list, loadData, loading, page, total } =
  useListQuery<VipExpLogRow>(
    ({ page, per_page }) =>
      getVipExpLogApi({ member_id: memberId.value, page, per_page }),
    { perPage: PER_PAGE },
  );

function open(id: number | string) {
  memberId.value = id;
  page.value = 1;
  list.value = [];
  total.value = 0;
  visible.value = true;
  loadData();
}

const fmt = (v?: null | number | string) => amountThousands(v, 2);

const beforeExp = tl('before');
const afterExp = tl('after');
const accExp = tl('accumulated_experience');
const keepExp = tl('keep_experience');

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('vip_exp_log')"
    width="1140"
    align-center
  >
    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn
        :label="tl('change_date')"
        align="center"
        prop="created_at"
      />
      <ElTableColumn :label="tl('change_experience_value')" align="center">
        <template #default="{ row }">
          <span
            :class="
              Number(row.exp) > 0
                ? 'text-green-600'
                : Number(row.exp) < 0
                  ? 'text-red-500'
                  : ''
            "
          >
            {{ fmt(row.exp) }}
          </span>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="`${beforeExp}${accExp}`" align="center">
        <template #default="{ row }">
          {{ fmt(row.before_deposit_amount) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="`${afterExp}${accExp}`" align="center">
        <template #default="{ row }">
          {{ fmt(row.after_deposit_amount) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="`${beforeExp}${keepExp}`" align="center">
        <template #default="{ row }">
          {{ fmt(row.before_keep_amount) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="`${afterExp}${keepExp}`" align="center">
        <template #default="{ row }">
          {{ fmt(row.after_keep_amount) }}
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
