<script lang="ts" setup>
import type { CostRow } from '#/api';

import { reactive } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getCostListApi, saveCostApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

defineOptions({ name: 'CostIndex' });

const PER_PAGE = 10;

const filters = reactive({ type: 0 as number | string });

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<CostRow>(
    ({ page }) => getCostListApi({ page, type: filters.type }),
    { perPage: PER_PAGE },
  );

/** type != 3 顯示百分比 */
const costText = (row: CostRow) =>
  `${row.cost ?? ''}${Number(row.type) === 3 ? '' : '%'}`;

async function editCost(row: CostRow) {
  let value: string;
  try {
    const r = await ElMessageBox.prompt(
      `${row.oname ?? ''} - ${row.name ?? ''}`,
      `${tl('edit')} - ${tl(`cost_type_${row.type}`)}${tl('cost')}`,
      { inputType: 'number', inputValue: String(row.cost ?? '') },
    );
    value = r.value;
  } catch {
    return;
  }
  const res = await saveCostApi({ cost: value, id: row.id, type: row.type });
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
  <Page :title="`${tl('system_management')} > ${tl('menu_cost_list')}`">
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="flex items-end gap-3">
          <ElFormItem :label="tl('type')" class="!mb-0">
            <ElSelect v-model="filters.type" class="!w-48">
              <ElOption :label="tl('all')" :value="0" />
              <ElOption :label="tl('cost_type_1')" :value="1" />
              <ElOption :label="tl('cost_type_2')" :value="2" />
              <ElOption :label="tl('cost_type_3')" :value="3" />
            </ElSelect>
          </ElFormItem>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('type')" min-width="120">
          <template #default="{ row }">
            {{ tl(`cost_type_${row.type}`) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('name')" min-width="200">
          <template #default="{ row }">
            <div>
              <span>{{ row.oname }} </span><b>{{ row.name }}</b>
            </div>
            <div class="text-muted-foreground text-xs">
              {{ tl('id') }}{{ row.id }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('cost')" min-width="120">
          <template #default="{ row }">{{ costText(row as CostRow) }}</template>
        </ElTableColumn>
        <ElTableColumn align="center" width="110" fixed="right">
          <template #default="{ row }">
            <ElButton
              size="small"
              type="success"
              @click="editCost(row as CostRow)"
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
  </Page>
</template>
