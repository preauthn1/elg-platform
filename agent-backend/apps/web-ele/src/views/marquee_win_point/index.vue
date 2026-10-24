<script lang="ts" setup>
import type { MarqueeWinPointRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElForm,
  ElFormItem,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getMarqueeWinPointListApi, toggleMarqueeWinPointApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

import MarqueeWinPointEditDialog from './components/marquee-win-point-edit-dialog.vue';

defineOptions({ name: 'MarqueeWinPointIndex' });

const PER_PAGE = 20;

const editRef = ref<InstanceType<typeof MarqueeWinPointEditDialog>>();

const filters = reactive({
  status: 0 as number | string,
});
const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<MarqueeWinPointRow>(
    ({ page, per_page }) =>
      getMarqueeWinPointListApi({
        page,
        per_page,
        status: filters.status,
        type: 0,
      }),
    { perPage: PER_PAGE, showError: true },
  );

async function onToggle(row: MarqueeWinPointRow) {
  const next = Number(row.status) === 1 ? 0 : 1;
  const res = await toggleMarqueeWinPointApi(row.id, next).catch(() => null);
  if (res && Number(res.code) === 1000) {
    ElMessage.success(res.message);
    loadData();
  } else {
    ElMessage.error(res?.message);
  }
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('menu_system_management')} > ${tl('menu_marquee_win_point')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <div class="mb-3">
        <ElButton type="success" @click="editRef?.open()">
          {{ tl('add') }}
        </ElButton>
      </div>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="flex flex-wrap items-center gap-4">
          <ElFormItem :label="tl('status')" class="!mb-0">
            <ElSelect v-model="filters.status" class="!w-40">
              <ElOption :label="tl('all')" :value="0" />
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('disable')" :value="2" />
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
        <ElTableColumn :label="tl('num')" min-width="70" prop="id" />
        <ElTableColumn
          :label="tl('agent_name')"
          min-width="120"
          prop="agent_name"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_name" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('exclude_agent')"
          min-width="120"
          prop="exclude_agent_name"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.exclude_agent_name" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('game_names')"
          min-width="120"
          prop="vendor_name"
        />
        <ElTableColumn
          :label="tl('trigger_amount')"
          min-width="110"
          prop="trigger_amount"
        />
        <ElTableColumn :label="tl('status')" align="right" min-width="90">
          <template #default="{ row }">
            <span :class="Number(row.status) === 1 ? '' : 'text-red-500'">
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="80" fixed="right">
          <template #default="{ row }">
            <ElDropdown trigger="click">
              <ElButton size="small">···</ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem
                    @click="editRef?.open((row as MarqueeWinPointRow).id)"
                  >
                    {{ tl('edit') }}
                  </ElDropdownItem>
                  <ElDropdownItem @click="onToggle(row as MarqueeWinPointRow)">
                    {{
                      Number(row.status) === 1 ? tl('disable') : tl('enable')
                    }}
                  </ElDropdownItem>
                </ElDropdownMenu>
              </template>
            </ElDropdown>
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

    <MarqueeWinPointEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
