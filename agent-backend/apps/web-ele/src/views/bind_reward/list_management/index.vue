<script lang="ts" setup>
import type { BindRewardRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getBindRewardListApi, switchBindRewardStatusApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

import BindRewardEditDialog from './components/bind-reward-edit-dialog.vue';

defineOptions({ name: 'BindRewardListManagement' });

const PER_PAGE = 30;

const editRef = ref<InstanceType<typeof BindRewardEditDialog>>();

const filters = reactive({
  agent_name: '',
  status: 2 as number | string,
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<BindRewardRow>(
    ({ page, per_page }) =>
      getBindRewardListApi({
        agent_name: filters.agent_name,
        page,
        per_page,
        status: filters.status,
      }),
    { perPage: PER_PAGE, showError: true },
  );

/** 舊版顯示為 UTC+8（後端存 UTC） */
function fmtTime(s?: string) {
  return s ? dayjs(s).add(8, 'hour').format('YYYY-MM-DD HH:mm:ss') : '';
}

function flowLimitMultiple(row: BindRewardRow) {
  try {
    const parsed = JSON.parse(row.flow_limits || '{}');
    return parsed?.flow_limit_multiple || '-';
  } catch {
    return '-';
  }
}

function isExpired(row: BindRewardRow) {
  return dayjs(row.end_time).add(8, 'hour').isBefore(dayjs());
}

function statusInfo(row: BindRewardRow) {
  if (Number(row.status) === 0)
    return { cls: 'text-red-500', text: tl('disable') };
  if (isExpired(row)) return { cls: 'text-red-500', text: tl('expired') };
  return { cls: 'text-green-600', text: tl('enable') };
}

async function onToggle(row: BindRewardRow) {
  const next = Number(row.status) === 1 ? 0 : 1;
  const res = await switchBindRewardStatusApi(row.id, next).catch(() => null);
  if (res && Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    loadData();
  } else {
    ElMessage.error(res?.message || tl('error'));
  }
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('bind_reward')} > ${tl('menu_bind_reward_list_management')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <div class="mb-3">
        <ElButton type="success" @click="editRef?.open()">
          {{ tl('add_bind_reward') }}
        </ElButton>
      </div>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="flex flex-wrap items-center gap-4">
          <ElFormItem :label="`${tl('agent')}${tl('name')}`" class="!mb-0">
            <ElInput v-model="filters.agent_name" class="!w-48" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('status')" class="!mb-0">
            <ElSelect v-model="filters.status" class="!w-40">
              <ElOption :label="tl('all')" :value="2" />
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('disable')" :value="0" />
              <ElOption :label="tl('expired')" :value="-1" />
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
        <ElTableColumn :label="tl('num')" prop="id" width="80" />
        <ElTableColumn
          :label="`${tl('agent')}${tl('name')}`"
          min-width="110"
          prop="agent_name"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_name" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('label_start_time')" min-width="150">
          <template #default="{ row }">{{ fmtTime(row.start_time) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('label_end_time')" min-width="150">
          <template #default="{ row }">{{ fmtTime(row.end_time) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('bind_reward_type')" min-width="100">
          <template #default="{ row }">
            {{
              Number(row.type) === 1 ? tl('platform_point') : tl('rolling_chip')
            }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('bind_reward_name')"
          min-width="120"
          prop="reward_name"
        />
        <ElTableColumn
          :label="tl('bind_reward_point')"
          min-width="100"
          prop="point"
        />
        <ElTableColumn :label="tl('event_flow_limit_multiple')" min-width="110">
          <template #default="{ row }">
            {{ flowLimitMultiple(row as BindRewardRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" min-width="90">
          <template #default="{ row }">
            <b :class="statusInfo(row as BindRewardRow).cls">
              {{ statusInfo(row as BindRewardRow).text }}
            </b>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="80" fixed="right">
          <template #default="{ row }">
            <ElDropdown trigger="click">
              <ElButton size="small">···</ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem
                    @click="editRef?.open((row as BindRewardRow).id)"
                  >
                    {{ tl('edit') }}
                  </ElDropdownItem>
                  <ElDropdownItem @click="onToggle(row as BindRewardRow)">
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

    <BindRewardEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
