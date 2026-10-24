<script lang="ts" setup>
import type { TransferLimitRow } from '#/api';

import { reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getTransferLimitListApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

import AddLimitDialog from './components/add-limit-dialog.vue';
import EditLimitDialog from './components/edit-limit-dialog.vue';

defineOptions({ name: 'TransferLimitIndex' });

const PER_PAGE = 20;

const addRef = ref<InstanceType<typeof AddLimitDialog>>();
const editRef = ref<InstanceType<typeof EditLimitDialog>>();

const filters = reactive({ agent_group_name: '' });

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<TransferLimitRow>(
    ({ page }) =>
      getTransferLimitListApi({
        agent_group_name: filters.agent_group_name,
        page,
      }),
    { perPage: PER_PAGE },
  );

loadData();
</script>

<template>
  <Page
    :title="`${tl('menu_agent_transfer_limit')} > ${tl('menu_agent_transfer_limit_management')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <div class="mb-3">
        <ElButton type="success" @click="addRef?.open()">
          {{ tl('add') }}
        </ElButton>
      </div>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('group_name')">
            <ElInput v-model="filters.agent_group_name" clearable />
          </ElFormItem>
          <ElFormItem>
            <ElButton :loading="loading" native-type="submit" type="primary">
              {{ tl('search') }}
            </ElButton>
          </ElFormItem>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('num')" prop="id" width="90" />
        <ElTableColumn :label="tl('group_name')" prop="name" min-width="160" />
        <ElTableColumn
          :label="tl('limit_amount')"
          align="right"
          min-width="160"
        >
          <template #default="{ row }">
            {{ amountThousands(row.limit, 2) }}
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="110" fixed="right">
          <template #default="{ row }">
            <ElButton
              size="small"
              type="warning"
              @click="editRef?.open((row as TransferLimitRow).id)"
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

    <AddLimitDialog ref="addRef" @success="loadData" />
    <EditLimitDialog ref="editRef" @success="loadData" />
  </Page>
</template>
