<script lang="ts" setup>
import type { VipChangeRecordRow } from '#/api';

import { ref } from 'vue';

import { ElDialog, ElTable, ElTableColumn } from 'element-plus';

import { getVipChangeRecordApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

defineOptions({ name: 'VipChangeRecordDialog' });

const PER_PAGE = 10;

const visible = ref(false);
const memberId = ref<number | string>('');

const { handlePageChange, list, loadData, loading, page, total } =
  useListQuery<VipChangeRecordRow>(
    ({ page, per_page }) =>
      getVipChangeRecordApi({ member_id: memberId.value, page, per_page }),
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

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('vip_change_record')"
    width="1140"
    align-center
  >
    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn
        :label="`${tl('before')}VIP`"
        align="center"
        prop="before_vip_name"
      />
      <ElTableColumn
        :label="`${tl('after')}VIP`"
        align="center"
        prop="vip_name"
      />
      <ElTableColumn
        :label="tl('change_date')"
        align="center"
        prop="created_at"
      />
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
