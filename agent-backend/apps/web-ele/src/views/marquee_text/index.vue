<script lang="ts" setup>
import type { MarqueeTextRow } from '#/api';

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

import { getMarqueeTextListApi, toggleMarqueeTextApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

import MarqueeTextEditDialog from './components/marquee-text-edit-dialog.vue';

defineOptions({ name: 'MarqueeTextIndex' });

const PER_PAGE = 20;

const editRef = ref<InstanceType<typeof MarqueeTextEditDialog>>();

function typeLabel(t: number | string): string {
  if (Number(t) === 1) return tl('general_marquee');
  if (Number(t) === 2) return tl('important_marquee');
  return String(t);
}

function locationLabel(l: number | string): string {
  if (Number(l) === 1) return tl('maquee_location_lobby');
  if (Number(l) === 2) return tl('maquee_location_chatroom');
  if (Number(l) === 3) return tl('maquee_location_agent_backend');
  return String(l);
}

/** 列表只顯示日期（去掉時間） */
function dateOnly(s: string): string {
  return s ? s.slice(0, 10) : '';
}

const filters = reactive({
  type: 0 as number | string,
  status: 0 as number | string,
  location: 0 as number | string,
});
const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<MarqueeTextRow>(
    ({ page, per_page }) =>
      getMarqueeTextListApi({
        location: filters.location,
        page,
        per_page,
        status: filters.status,
        type: filters.type,
      }),
    { perPage: PER_PAGE, showError: true },
  );

async function onToggle(row: MarqueeTextRow) {
  const next = Number(row.status) === 1 ? 0 : 1;
  const res = await toggleMarqueeTextApi(row.id, next).catch(() => null);
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
  <Page :title="`${tl('menu_system_management')} > ${tl('menu_marquee_text')}`">
    <ElCard class="mb-3" shadow="never">
      <div class="mb-3">
        <ElButton type="success" @click="editRef?.open()">
          {{ tl('add') }}
        </ElButton>
      </div>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="flex flex-wrap items-center gap-4">
          <ElFormItem :label="tl('type')" class="!mb-0">
            <ElSelect v-model="filters.type" class="!w-40">
              <ElOption :label="tl('all')" :value="0" />
              <ElOption :label="tl('general_marquee')" :value="1" />
              <ElOption :label="tl('important_marquee')" :value="2" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('status')" class="!mb-0">
            <ElSelect v-model="filters.status" class="!w-40">
              <ElOption :label="tl('all')" :value="0" />
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('disable')" :value="2" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('maquee_location')" class="!mb-0">
            <ElSelect v-model="filters.location" class="!w-40">
              <ElOption :label="tl('all')" :value="0" />
              <ElOption :label="tl('maquee_location_lobby')" :value="1" />
              <ElOption :label="tl('maquee_location_chatroom')" :value="2" />
              <ElOption
                :label="tl('maquee_location_agent_backend')"
                :value="3"
              />
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
        <ElTableColumn :label="tl('maquee_location')" min-width="110">
          <template #default="{ row }">
            {{ locationLabel(row.location) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('type')" min-width="100">
          <template #default="{ row }">{{ typeLabel(row.type) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('content')" min-width="240">
          <template #default="{ row }">
            <div class="truncate">{{ row.content }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('label_start_time')" min-width="110">
          <template #default="{ row }">{{ dateOnly(row.start_time) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('label_end_time')" min-width="110">
          <template #default="{ row }">{{ dateOnly(row.end_time) }}</template>
        </ElTableColumn>
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
                    @click="editRef?.open((row as MarqueeTextRow).id)"
                  >
                    {{ tl('edit') }}
                  </ElDropdownItem>
                  <ElDropdownItem @click="onToggle(row as MarqueeTextRow)">
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

    <MarqueeTextEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
