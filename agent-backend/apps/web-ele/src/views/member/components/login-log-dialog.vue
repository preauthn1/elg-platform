<script lang="ts" setup>
import type { LoginLogRow } from '#/api';

import { ref } from 'vue';

import { ElDialog, ElTable, ElTableColumn, ElTag } from 'element-plus';

import { getMemberLoginLogApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';

defineOptions({ name: 'MemberLoginLogDialog' });

const PER_PAGE = 10;

const visible = ref(false);
const loading = ref(false);
const memberId = ref(0);
const list = ref<LoginLogRow[]>([]);
const page = ref(1);
const total = ref(0);

async function load() {
  loading.value = true;
  try {
    const res = await getMemberLoginLogApi({
      id: memberId.value,
      page: page.value,
      per_page: PER_PAGE,
    });
    list.value = res.code === 1000 ? (res.data ?? []) : [];
    total.value = res.pagination?.total ?? 0;
  } finally {
    loading.value = false;
  }
}

function open(id: number) {
  memberId.value = id;
  page.value = 1;
  visible.value = true;
  load();
}

function handlePage(p: number) {
  page.value = p;
  load();
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('menu_ip_inquire_permissiom')"
    width="560"
    align-center
  >
    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn :label="tl('date')" prop="created_at" width="180" />
      <ElTableColumn :label="tl('ip')" prop="ip" min-width="140" />
      <ElTableColumn :label="tl('status')" width="100" align="center">
        <template #default="{ row }">
          <ElTag :type="row.status === 1 ? 'success' : 'danger'" size="small">
            {{ row.status === 1 ? tl('success') : tl('failure') }}
          </ElTag>
        </template>
      </ElTableColumn>
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>
    <TablePagination
      :current-page="page"
      :page-size="PER_PAGE"
      :total="total"
      layout="total, prev, pager, next"
      @current-change="handlePage"
    />
  </ElDialog>
</template>
