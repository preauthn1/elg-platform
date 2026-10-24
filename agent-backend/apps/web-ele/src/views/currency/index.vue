<script lang="ts" setup>
import type { CurrencyManageRow } from '#/api';

import { ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElMessage,
  ElMessageBox,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getCurrencyManageListApi, toggleCurrencyStatusApi } from '#/api';
import { tl } from '#/locales';

import CurrencyEditDialog from './components/currency-edit-dialog.vue';

defineOptions({ name: 'CurrencyIndex' });

const editRef = ref<InstanceType<typeof CurrencyEditDialog>>();
const loading = ref(false);
const list = ref<CurrencyManageRow[]>([]);

async function loadData() {
  loading.value = true;
  try {
    list.value = (await getCurrencyManageListApi()) ?? [];
  } finally {
    loading.value = false;
  }
}

async function toggleStatus(row: CurrencyManageRow) {
  const next = Number(row.status) === 1 ? 0 : 1;
  try {
    await ElMessageBox.confirm(
      next === 1 ? tl('enable') : tl('disable'),
      tl('status'),
      { type: 'warning' },
    );
  } catch {
    return;
  }
  const res = await toggleCurrencyStatusApi(row.id, next);
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    loadData();
  } else {
    ElMessage.error(res.message);
  }
}

loadData();
</script>

<template>
  <Page
    :title="`${tl('system_management')} > ${tl('menu_currency_management')}`"
  >
    <ElCard shadow="never">
      <div class="mb-3">
        <ElButton type="success" @click="editRef?.open(0)">
          {{ tl('add') }}
        </ElButton>
      </div>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('currency_sort')" prop="sort" width="100" />
        <ElTableColumn :label="tl('currency_name')" min-width="160">
          <template #default="{ row }">
            <div>{{ row.name }}</div>
            <div class="text-muted-foreground text-xs">
              {{ tl('id') }} {{ row.id }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('currency_code')"
          prop="code"
          min-width="100"
        />
        <ElTableColumn :label="tl('rate')" prop="rate" min-width="110" />
        <ElTableColumn :label="tl('status')" min-width="100">
          <template #default="{ row }">
            <span
              :class="
                Number(row.status) === 1 ? 'text-green-600' : 'text-red-500'
              "
              class="font-semibold"
            >
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="160" fixed="right">
          <template #default="{ row }">
            <ElButton
              size="small"
              type="warning"
              @click="editRef?.open((row as CurrencyManageRow).id)"
            >
              {{ tl('edit') }}
            </ElButton>
            <ElButton
              size="small"
              @click="toggleStatus(row as CurrencyManageRow)"
            >
              {{ Number(row.status) === 1 ? tl('disable') : tl('enable') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>

    <CurrencyEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
