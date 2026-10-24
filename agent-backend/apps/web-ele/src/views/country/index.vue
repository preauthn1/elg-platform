<script lang="ts" setup>
import type { CountryManageRow } from '#/api';

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

import { getCountryListApi, toggleCountryStatusApi } from '#/api';
import { tl } from '#/locales';

import CountryEditDialog from './components/country-edit-dialog.vue';

defineOptions({ name: 'CountryIndex' });

const editRef = ref<InstanceType<typeof CountryEditDialog>>();
const loading = ref(false);
const list = ref<CountryManageRow[]>([]);

async function loadData() {
  loading.value = true;
  try {
    list.value = (await getCountryListApi()) ?? [];
  } finally {
    loading.value = false;
  }
}

async function toggleStatus(row: CountryManageRow) {
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
  const res = await toggleCountryStatusApi(row.id, next);
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
    :title="`${tl('system_management')} > ${tl('menu_country_management')}`"
  >
    <ElCard shadow="never">
      <div class="mb-3">
        <ElButton type="success" @click="editRef?.open(0)">
          {{ tl('add') }}
        </ElButton>
      </div>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('name')" min-width="160">
          <template #default="{ row }">
            <div>{{ row.name }}</div>
            <div class="text-muted-foreground text-xs">
              {{ tl('id') }}{{ row.id }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('code')" prop="code" min-width="110" />
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
              @click="editRef?.open((row as CountryManageRow).id)"
            >
              {{ tl('edit') }}
            </ElButton>
            <ElButton
              size="small"
              @click="toggleStatus(row as CountryManageRow)"
            >
              {{ Number(row.status) === 1 ? tl('disable') : tl('enable') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>

    <CountryEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
