<script lang="ts" setup>
import type { BackendAnnouncementRow } from '#/api';

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

import {
  getBackendAnnouncementListApi,
  toggleBackendAnnouncementApi,
} from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

import BackendAnnouncementEditDialog from './components/backend-announcement-edit-dialog.vue';

defineOptions({ name: 'BackendAnnouncementIndex' });

const PER_PAGE = 20;

const editRef = ref<InstanceType<typeof BackendAnnouncementEditDialog>>();

const TYPE_LABELS: Record<string, string> = {
  1: 'general_announcement',
  2: 'vendor_announcement',
  3: 'emergency_announcement',
  4: 'important_announcement',
  5: 'system_announcement',
};

function typeLabel(t: number | string): string {
  const k = TYPE_LABELS[String(t)];
  return k ? tl(k) : String(t);
}

const filters = reactive({ type: 0 as number | string });
const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<BackendAnnouncementRow>(
    ({ page, per_page }) =>
      getBackendAnnouncementListApi({
        page,
        per_page,
        type: filters.type,
      }),
    { perPage: PER_PAGE, showError: true },
  );

async function onToggle(row: BackendAnnouncementRow) {
  const next = Number(row.status) === 1 ? 0 : 1;
  const res = await toggleBackendAnnouncementApi(row.id, next).catch(
    () => null,
  );
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
    :title="`${tl('menu_system_management')} > ${tl('menu_backend_announcement')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <div class="mb-3">
        <ElButton type="success" @click="editRef?.open()">
          {{ tl('add') }}
        </ElButton>
      </div>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="flex flex-wrap items-center gap-4">
          <ElFormItem :label="tl('type')" class="!mb-0">
            <ElSelect v-model="filters.type" class="!w-48">
              <ElOption :label="tl('all')" :value="0" />
              <ElOption :label="tl('general_announcement')" :value="1" />
              <ElOption :label="tl('vendor_announcement')" :value="2" />
              <ElOption :label="tl('emergency_announcement')" :value="3" />
              <ElOption :label="tl('important_announcement')" :value="4" />
              <ElOption :label="tl('system_announcement')" :value="5" />
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
        <ElTableColumn :label="tl('num')" min-width="80" prop="id" />
        <ElTableColumn :label="tl('type')" min-width="120">
          <template #default="{ row }">{{ typeLabel(row.type) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('content')" min-width="280">
          <template #default="{ row }">
            <div class="truncate">{{ row.content }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" align="right" min-width="100">
          <template #default="{ row }">
            <span :class="Number(row.status) === 1 ? '' : 'text-red-500'">
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="90" fixed="right">
          <template #default="{ row }">
            <ElDropdown trigger="click">
              <ElButton size="small">···</ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem
                    @click="editRef?.open((row as BackendAnnouncementRow).id)"
                  >
                    {{ tl('edit') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    @click="onToggle(row as BackendAnnouncementRow)"
                  >
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

    <BackendAnnouncementEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
