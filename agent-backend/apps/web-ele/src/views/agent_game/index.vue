<script lang="ts" setup>
import type { AgentGameListRow } from '#/api';

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
  ElInput,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getAgentVendorAgentListApi, getVendorDropdownByTypeApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

import AgentGameEditDialog from './components/agent-game-edit-dialog.vue';

defineOptions({ name: 'AgentGameIndex' });

const PER_PAGE = 20;

const editRef = ref<InstanceType<typeof AgentGameEditDialog>>();

const filters = reactive({
  agent_username: '',
  vendor_id: ' ' as number | string,
});

const searched = ref(false);

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<AgentGameListRow>(
    ({ page, per_page }) => {
      searched.value = true;
      return getAgentVendorAgentListApi({
        agent_username: filters.agent_username.trim(),
        page,
        per_page,
        vendor_id: filters.vendor_id,
      });
    },
    { perPage: PER_PAGE, showError: true },
  );
const vendors = ref<Array<{ id: number; name: string }>>([]);

function handleClear() {
  filters.agent_username = '';
  filters.vendor_id = ' ';
}

onMounted(async () => {
  vendors.value = (await getVendorDropdownByTypeApi(1)) ?? [];
});
</script>

<template>
  <Page
    :title="`${tl('menu_new_vendor')} > ${tl('menu_agent_game_management')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('agent')">
            <ElInput v-model="filters.agent_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('label_vendor')">
            <ElSelect v-model="filters.vendor_id" class="w-full" filterable>
              <ElOption :label="tl('all')" value=" " />
              <ElOption
                v-for="v in vendors"
                :key="v.id"
                :label="v.name"
                :value="v.id"
              />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('vendor_info_name')"
          align="center"
          min-width="150"
        >
          <template #default="{ row }">
            <div class="font-medium">{{ row.vendor_info_name }}</div>
            <div class="text-xs text-gray-400">
              {{ tl('id') }}: {{ row.id }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('label_vendor')"
          align="center"
          min-width="130"
        >
          <template #default="{ row }">
            <div class="font-medium">{{ row.vendor_name }}</div>
            <div class="text-xs text-gray-400">
              vendor_id: {{ row.vendor_id }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('agent')" align="center" min-width="130">
          <template #default="{ row }">
            <div class="font-medium">
              <AgentAccount :username="row.agent_username" />
            </div>
            <div class="text-xs text-gray-400">
              agent_id: {{ row.agent_id }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('total_games')"
          align="center"
          prop="total_count"
          width="110"
        />
        <ElTableColumn
          :label="tl('features')"
          align="center"
          width="90"
          fixed="right"
        >
          <template #default="{ row }">
            <ElDropdown>
              <ElButton size="small">{{ tl('features') }}</ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem
                    @click="editRef?.open(row as AgentGameListRow)"
                  >
                    {{ tl('edit') }}
                  </ElDropdownItem>
                </ElDropdownMenu>
              </template>
            </ElDropdown>
          </template>
        </ElTableColumn>
        <template #empty>
          {{ searched ? tl('no_record') : '' }}
        </template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>

    <AgentGameEditDialog ref="editRef" @saved="loadData" />
  </Page>
</template>
