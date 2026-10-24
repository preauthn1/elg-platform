<script lang="ts" setup>
import type { GameMaintenanceRow, VendorMaintenanceRow } from '#/api';

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
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTabPane,
  ElTabs,
  ElTag,
} from 'element-plus';

import {
  getGameMaintenanceListApi,
  getVendorListApi,
  getVendorMaintenancesListApi,
  vendorNameWithId,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

import GameMaintenanceEditDialog from './components/game-maintenance-edit-dialog.vue';
import VendorMaintenanceEditDialog from './components/vendor-maintenance-edit-dialog.vue';

defineOptions({ name: 'VendorMaintenances' });

const PER_PAGE = 20;

const editRef = ref<InstanceType<typeof VendorMaintenanceEditDialog>>();
const gameEditRef = ref<InstanceType<typeof GameMaintenanceEditDialog>>();

/** 兩種維護分頁呈現：vendor=整商關閉、game=單一遊戲（工單4120） */
const activeTab = ref<'game' | 'vendor'>('vendor');

const filters = reactive({
  status: 1 as number | string,
  vendor_id: 0 as number | string,
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<VendorMaintenanceRow>(
    ({ page, per_page }) =>
      getVendorMaintenancesListApi({
        page,
        per_page,
        status: filters.status,
        vendor_id: filters.vendor_id,
      }),
    { perPage: PER_PAGE, showError: true },
  );

/**
 * 遊戲維護（4120）：獨立查詢條件與分頁，與整商維護互不干擾。
 *
 * 不做遊戲商篩選——一張單可跨商，「查某商」的結果會讓人誤以為該單只涉及該商，
 * 對判讀沒有幫助；實際涵蓋的商在列表的「遊戲商名稱」欄可直接看到。
 */
const gameFilters = reactive({
  status: '' as number | string,
  vendor_id: 0,
});

const {
  handlePageChange: handleGamePageChange,
  list: gameList,
  loadData: loadGameData,
  loading: gameLoading,
  page: gamePage,
  search: gameSearch,
  total: gameTotal,
} = useListQuery<GameMaintenanceRow>(
  ({ page, per_page }) =>
    getGameMaintenanceListApi({
      page,
      per_page,
      status: gameFilters.status === '' ? undefined : gameFilters.status,
      // 0 代表全部，不帶入查詢條件
      vendor_id: gameFilters.vendor_id || undefined,
    }),
  { perPage: PER_PAGE, showError: true },
);

const vendors = ref<Array<{ id: number; name: string }>>([]);

function plus8(v?: string) {
  return v ? dayjs(v).add(8, 'hour').format('YYYY-MM-DD HH:mm:ss') : '';
}

function isExpired(row: VendorMaintenanceRow) {
  return row.end_time
    ? dayjs(row.end_time).add(8, 'hour').isBefore(dayjs())
    : false;
}

/** 執行狀態的顏色：維護中最顯眼，已結束／停用淡化 */
function runStateTagType(state: GameMaintenanceRow['run_state']) {
  switch (state) {
    case 'pending': {
      return 'warning';
    }
    case 'running': {
      return 'danger';
    }
    default: {
      return 'info';
    }
  }
}

/** 切到遊戲維護分頁時才載入，避免進頁就打兩支列表 */
function onTabChange(name: number | string) {
  if (name === 'game' && gameList.value.length === 0) loadGameData();
}

onMounted(() => {
  // 下拉與列表各自載入：遊戲商下拉失敗不擋列表（否則進頁會一直沒資料）
  getVendorListApi()
    .then((v) => (vendors.value = v ?? []))
    .catch(() => {});
  loadData();
});
</script>

<template>
  <Page
    :title="`${tl('menu_system_management')} > ${tl('menu_vendor_maintenances')}`"
  >
    <ElTabs v-model="activeTab" @tab-change="onTabChange">
      <!-- 遊戲商維護：整商關閉（既有功能） -->
      <ElTabPane :label="tl('menu_vendor_maintenances')" name="vendor">
        <ElCard class="mb-3" shadow="never">
          <div class="mb-3">
            <ElButton type="success" @click="editRef?.open()">
              {{ `${tl('add')}${tl('menu_vendor_maintenances')}` }}
            </ElButton>
          </div>
          <ElForm label-width="auto" @submit.prevent="search">
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
              <ElFormItem :label="tl('status')">
                <ElSelect v-model="filters.status" class="w-full">
                  <ElOption :label="tl('enable')" :value="1" />
                  <ElOption :label="tl('disable')" :value="0" />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('platform')">
                <ElSelect v-model="filters.vendor_id" class="w-full" filterable>
                  <ElOption :label="tl('all')" :value="0" />
                  <ElOption
                    v-for="v in vendors"
                    :key="v.id"
                    :label="v.name"
                    :value="v.id"
                  />
                </ElSelect>
              </ElFormItem>
            </div>
            <div class="flex justify-end">
              <ElButton :loading="loading" native-type="submit" type="primary">
                {{ tl('search') }}
              </ElButton>
            </div>
          </ElForm>
        </ElCard>

        <ElCard shadow="never">
          <ElTable v-loading="loading" :data="list" border size="small" stripe>
            <ElTableColumn :label="tl('createdate')" align="center" width="160">
              <template #default="{ row }">
                {{ plus8(row.created_at) }}
              </template>
            </ElTableColumn>
            <ElTableColumn
              :label="`${tl('label_vendor')}${tl('name')}`"
              align="center"
              min-width="130"
            >
              <template #default="{ row }">
                {{
                  vendorNameWithId({ id: row.vendor_id, name: row.vendor_name })
                }}
              </template>
            </ElTableColumn>
            <ElTableColumn :label="tl('type')" align="center" width="120">
              <template #default="{ row }">
                <b>{{ tl(`vendor_maintenances_type_${row.type}`) }}</b>
              </template>
            </ElTableColumn>
            <ElTableColumn :label="tl('time')" align="center" width="170">
              <template #default="{ row }">
                <div
                  :class="
                    isExpired(row as VendorMaintenanceRow)
                      ? 'text-gray-400'
                      : 'text-sky-600'
                  "
                >
                  {{ plus8(row.start_time) }}<br />~<br />{{
                    plus8(row.end_time)
                  }}
                </div>
              </template>
            </ElTableColumn>
            <ElTableColumn :label="tl('memo')" align="center" min-width="160">
              <template #default="{ row }">
                <span
                  :class="
                    isExpired(row as VendorMaintenanceRow)
                      ? 'text-gray-400'
                      : 'text-sky-600'
                  "
                  class="break-all"
                >
                  {{ row.memo }}
                </span>
              </template>
            </ElTableColumn>
            <ElTableColumn
              :label="tl('maintenance_agent')"
              align="center"
              prop="agent_username"
              min-width="110"
            >
              <template #default="{ row }">
                <AgentAccount :username="row.agent_username" />
              </template>
            </ElTableColumn>
            <ElTableColumn
              :label="tl('exclude_agent')"
              align="center"
              prop="exclude_agent_username"
              min-width="110"
            >
              <template #default="{ row }">
                <AgentAccount :username="row.exclude_agent_username" />
              </template>
            </ElTableColumn>
            <ElTableColumn
              :label="tl('enable_status')"
              align="center"
              width="90"
            >
              <template #default="{ row }">
                <b :class="{ 'text-red-500': Number(row.status) !== 1 }">
                  {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
                </b>
              </template>
            </ElTableColumn>
            <ElTableColumn
              :label="tl('last_operator')"
              align="center"
              prop="username"
              min-width="100"
            >
              <template #default="{ row }">
                <AgentAccount :username="row.username" />
              </template>
            </ElTableColumn>
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
                      <ElDropdownItem @click="editRef?.open(row.id)">
                        {{ tl('edit') }}
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
      </ElTabPane>

      <!--
        遊戲維護（4120）：粒度到單一遊戲、一張單可跨遊戲商。
        與整商維護獨立，兩者可同時生效。
      -->
      <ElTabPane :label="tl('game_maintenance')" name="game">
        <ElCard class="mb-3" shadow="never">
          <div class="mb-3">
            <ElButton type="success" @click="gameEditRef?.open()">
              {{ `${tl('add')}${tl('game_maintenance')}` }}
            </ElButton>
          </div>
          <ElForm label-width="auto" @submit.prevent="gameSearch">
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
              <ElFormItem :label="tl('status')">
                <ElSelect v-model="gameFilters.status" class="w-full" clearable>
                  <ElOption :label="tl('all')" value="" />
                  <ElOption :label="tl('enable')" :value="1" />
                  <ElOption :label="tl('disable')" :value="0" />
                </ElSelect>
              </ElFormItem>
              <!-- 一張單可跨商，此處為「涵蓋即列出」 -->
              <ElFormItem :label="tl('platform')">
                <ElSelect
                  v-model="gameFilters.vendor_id"
                  class="w-full"
                  filterable
                >
                  <ElOption :label="tl('all')" :value="0" />
                  <ElOption
                    v-for="v in vendors"
                    :key="v.id"
                    :label="v.name"
                    :value="v.id"
                  />
                </ElSelect>
              </ElFormItem>
            </div>
            <div class="flex justify-end">
              <ElButton
                :loading="gameLoading"
                native-type="submit"
                type="primary"
              >
                {{ tl('search') }}
              </ElButton>
            </div>
          </ElForm>
        </ElCard>

        <ElCard shadow="never">
          <ElTable
            v-loading="gameLoading"
            :data="gameList"
            border
            size="small"
            stripe
          >
            <ElTableColumn :label="tl('createdate')" align="center" width="160">
              <template #default="{ row }">
                {{ plus8(row.created_at) }}
              </template>
            </ElTableColumn>
            <ElTableColumn
              :label="`${tl('label_vendor')}${tl('name')}`"
              align="center"
              min-width="150"
            >
              <template #default="{ row }">
                <span class="break-all">
                  {{ (row.vendor_names ?? []).join('、') || '-' }}
                </span>
              </template>
            </ElTableColumn>
            <ElTableColumn
              :label="tl('game_maintenance_games')"
              align="center"
              min-width="200"
            >
              <template #default="{ row }">
                <div class="break-all text-xs">
                  {{
                    (row.games ?? [])
                      .slice(0, 5)
                      .map((g: any) => g.name)
                      .join('、')
                  }}
                  <span v-if="row.game_count > 5">
                    …（{{ row.game_count }}）
                  </span>
                </div>
              </template>
            </ElTableColumn>
            <ElTableColumn :label="tl('time')" align="center" width="170">
              <template #default="{ row }">
                <div class="text-sky-600">
                  {{ plus8(row.start_time) }}<br />~<br />{{
                    plus8(row.end_time)
                  }}
                </div>
              </template>
            </ElTableColumn>
            <!-- 執行狀態由後端依時間推導，不存 DB -->
            <ElTableColumn :label="tl('run_state')" align="center" width="100">
              <template #default="{ row }">
                <ElTag :type="runStateTagType(row.run_state)" size="small">
                  {{ tl(`game_maintenance_state_${row.run_state}`) }}
                </ElTag>
              </template>
            </ElTableColumn>
            <ElTableColumn
              :label="tl('memo')"
              align="center"
              min-width="120"
              prop="memo"
            />
            <ElTableColumn
              :label="tl('enable_status')"
              align="center"
              width="90"
            >
              <template #default="{ row }">
                <b :class="{ 'text-red-500': Number(row.status) !== 1 }">
                  {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
                </b>
              </template>
            </ElTableColumn>
            <ElTableColumn
              :label="tl('features')"
              align="center"
              width="90"
              fixed="right"
            >
              <template #default="{ row }">
                <ElButton size="small" @click="gameEditRef?.open(row.id)">
                  {{ tl('edit') }}
                </ElButton>
              </template>
            </ElTableColumn>
            <template #empty>{{ tl('no_record') }}</template>
          </ElTable>
          <TablePagination
            :current-page="gamePage"
            :page-size="PER_PAGE"
            :total="gameTotal"
            @current-change="handleGamePageChange"
          />
        </ElCard>
      </ElTabPane>
    </ElTabs>

    <VendorMaintenanceEditDialog ref="editRef" @saved="loadData" />
    <GameMaintenanceEditDialog ref="gameEditRef" @saved="loadGameData" />
  </Page>
</template>
