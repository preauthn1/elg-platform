<script lang="ts" setup>
import type { VendorManagementItem } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElForm,
  ElFormItem,
  ElImage,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  fetchVendorManagementListApi,
  vendorManagementToggleStatusApi,
} from '#/api';
import CopyIcon from '#/components/copy-icon.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';

import VendorManagementEditDialog from './components/vendor-management-edit-dialog.vue';
import {
  callbackUrlText,
  channelText,
  gameListText,
  walletModeText,
} from './vendor-management-text';

defineOptions({ name: 'VendorManagement' });

const PER_PAGE = 20;

const router = useRouter();
const editRef = ref<InstanceType<typeof VendorManagementEditDialog>>();

const loading = ref(false);
const exporting = ref(false);
const allRows = ref<VendorManagementItem[]>([]);
/** apis 回傳的通道清單（含 direct） */
const channels = ref<string[]>([]);
/** 回調網址主機，給編輯彈窗預覽完整網址 */
const callbackBaseUrl = ref('');
/** 能否看到與修改回調路徑：以後端 callback_visible 為準（僅 admin 角色為 true） */
const callbackVisible = ref(false);

/**
 * 下拉「全部」選項的值。不用空字串：ElSelect 會把 '' 當成未選，改顯示 placeholder
 * 「請選擇」而不是「全部」。
 */
const FILTER_ALL = 'all';

/** 篩選條件（三個下拉為 FILTER_ALL＝全部；keyword 空字串＝不搜尋） */
interface VendorManagementFilters {
  channel: string;
  keyword: string;
  /** aggregated = 集成（走通道），direct = 直連 */
  type: string;
  wallet_mode: string;
}

function emptyFilters(): VendorManagementFilters {
  return {
    channel: FILTER_ALL,
    keyword: '',
    type: FILTER_ALL,
    wallet_mode: FILTER_ALL,
  };
}

/** 輸入中的條件 */
const filters = reactive<VendorManagementFilters>(emptyFilters());
/** 按下搜尋時的快照，列表與匯出都以此為準 */
const applied = ref<VendorManagementFilters>(emptyFilters());

/** 通道下拉：直連固定第一個，其餘照 apis 回傳順序 */
const channelOptions = computed(() =>
  channels.value.filter((c) => c !== 'direct'),
);

/** 依錢包模式／類型／通道過濾；channel 為 null（沒掛 driver）的列在後兩者非全部時不命中 */
const source = computed(() => {
  const { channel, type, wallet_mode: walletMode } = applied.value;
  return allRows.value.filter((r) => {
    if (walletMode !== FILTER_ALL && r.wallet_mode !== walletMode) return false;
    if (type === 'aggregated' && (r.channel === null || r.channel === 'direct'))
      return false;
    if (type === 'direct' && r.channel !== 'direct') return false;
    if (channel !== FILTER_ALL && r.channel !== channel) return false;
    return true;
  });
});

const {
  handlePageChange,
  handleSearch: resetPage,
  handleSortChange,
  keyword,
  page,
  pagedRows,
  sortedRows,
  total,
} = useClientTable(source, {
  perPage: PER_PAGE,
  searchFields: (r) => [r.id, r.name, r.remark],
});

function statusText(s?: number | string) {
  if (Number(s) === 1) return tl('enable');
  return Number(s) === 2 ? tl('in_development') : tl('disable');
}

function handleSearch() {
  applied.value = { ...filters };
  keyword.value = filters.keyword;
  resetPage();
}

function handleClear() {
  Object.assign(filters, emptyFilters());
  applied.value = emptyFilters();
  keyword.value = '';
  resetPage();
}

async function loadData() {
  loading.value = true;
  try {
    const res = await fetchVendorManagementListApi();
    if (Number(res.code) === 200) {
      allRows.value = res.data?.items ?? [];
      channels.value = res.data?.channels ?? [];
      callbackBaseUrl.value = res.data?.callback_base_url ?? '';
      callbackVisible.value = res.data?.callback_visible === true;
    } else {
      allRows.value = [];
      channels.value = [];
      callbackBaseUrl.value = '';
      callbackVisible.value = false;
      ElMessage.error(res.message);
    }
  } finally {
    loading.value = false;
  }
}

async function toggleStatus(row: VendorManagementItem) {
  const res = await vendorManagementToggleStatusApi({
    id: row.id,
    status: Number(row.status) === 1 ? 0 : 1,
  });
  if (Number(res.code) === 1000) {
    await loadData();
  } else {
    ElMessage.error(Number(res.code) === 2002 ? String(res.data) : res.message);
  }
}

function goGameManagement(row: VendorManagementItem) {
  router.push({ path: '/game/index', query: { vid: row.id } });
}

/**
 * 匯出 Excel：篩選＋關鍵字＋排序後、未分頁的整份，順序與各格文字都與畫面相同。
 * 回調路徑欄與列表一致，只有 callbackVisible 時才輸出（位於無限代轉換比與備註之間）
 */
async function handleExport() {
  exporting.value = true;
  try {
    const rows = sortedRows.value;
    const showCallback = callbackVisible.value;
    await exportExcel({
      audit: {
        conditions: { ...applied.value },
        recordCount: rows.length,
      },
      columns: [
        tl('num'),
        tl('name'),
        tl('wallet_mode'),
        tl('vendor_channel'),
        tl('gamelist_api'),
        tl('turnover_exchange_rate'),
        tl('debt_ratio'),
        tl('agent_unlimit_bet_rate'),
        ...(showCallback ? [tl('callback_path')] : []),
        tl('memo'),
        tl('status'),
      ],
      rows: rows.map((r) => [
        r.id,
        r.name ?? '',
        walletModeText(r.wallet_mode),
        channelText(r.channel),
        gameListText(r.has_game_list),
        `1:${r.rate ?? ''}`,
        r.percentage ?? '',
        r.valid_bet_rate ?? '',
        ...(showCallback ? [callbackUrlText(r)] : []),
        r.remark ?? '',
        statusText(r.status),
      ]),
      title: tl('menu_vendor_management'),
    });
  } finally {
    exporting.value = false;
  }
}

onMounted(loadData);
</script>

<template>
  <Page :title="`${tl('menu_new_vendor')} > ${tl('menu_vendor_management')}`">
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-4">
          <ElFormItem :label="tl('wallet_mode')">
            <ElSelect v-model="filters.wallet_mode">
              <ElOption :label="tl('all')" :value="FILTER_ALL" />
              <ElOption :label="tl('single_wallet')" value="single" />
              <ElOption :label="tl('transfer_wallet')" value="transfer" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('type')">
            <ElSelect v-model="filters.type">
              <ElOption :label="tl('all')" :value="FILTER_ALL" />
              <ElOption
                :label="tl('vendor_type_aggregated')"
                value="aggregated"
              />
              <ElOption :label="tl('vendor_channel_direct')" value="direct" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('vendor_channel')">
            <ElSelect v-model="filters.channel">
              <ElOption :label="tl('all')" :value="FILTER_ALL" />
              <ElOption :label="tl('vendor_channel_direct')" value="direct" />
              <ElOption
                v-for="c in channelOptions"
                :key="c"
                :label="c"
                :value="c"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem>
            <ElInput
              v-model="filters.keyword"
              :placeholder="tl('search')"
              clearable
            />
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('export_excel') }}
          </ElButton>
          <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
          <ElButton native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard v-loading="loading" shadow="never">
      <ElTable
        :data="pagedRows"
        border
        size="small"
        stripe
        @sort-change="handleSortChange"
      >
        <ElTableColumn
          :label="tl('num')"
          align="center"
          prop="id"
          sortable="custom"
          width="90"
        />
        <ElTableColumn
          :label="tl('game_provider_picture')"
          align="center"
          width="110"
        >
          <template #default="{ row }">
            <ElImage
              v-if="row.image"
              :src="row.image"
              fit="contain"
              style="height: 60px"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('name')"
          align="center"
          prop="name"
          min-width="140"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('wallet_mode')"
          align="center"
          prop="wallet_mode"
          sortable="custom"
          width="120"
        >
          <template #default="{ row }">
            {{ walletModeText(row.wallet_mode) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('vendor_channel')"
          align="center"
          prop="channel"
          sortable="custom"
          width="120"
        >
          <template #default="{ row }">{{ channelText(row.channel) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('gamelist_api')"
          align="center"
          prop="has_game_list"
          sortable="custom"
          width="130"
        >
          <template #default="{ row }">
            {{ gameListText(row.has_game_list) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('turnover_exchange_rate')"
          align="center"
          prop="rate"
          sortable="custom"
          width="150"
        >
          <template #default="{ row }">1:{{ row.rate }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('debt_ratio')"
          align="center"
          prop="percentage"
          sortable="custom"
          width="120"
        >
          <template #default="{ row }">
            <span class="text-sky-600">{{ row.percentage }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_unlimit_bet_rate')"
          align="center"
          prop="valid_bet_rate"
          sortable="custom"
          width="150"
        >
          <template #default="{ row }">
            <span class="text-sky-600">{{ row.valid_bet_rate }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          v-if="callbackVisible"
          :label="tl('callback_path')"
          align="center"
          prop="callback_url"
          min-width="260"
          sortable="custom"
        >
          <template #default="{ row }">
            <span class="break-all">{{
              callbackUrlText(row as VendorManagementItem)
            }}</span>
            <CopyIcon
              v-if="row.callback_applicable !== false && row.callback_url"
              :text="row.callback_url"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('memo')"
          align="center"
          prop="remark"
          min-width="120"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('status')"
          align="center"
          prop="status"
          sortable="custom"
          width="120"
        >
          <template #default="{ row }">
            <span :class="{ 'text-red-500': Number(row.status) !== 1 }">
              {{ statusText(row.status) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="90" fixed="right">
          <template #default="{ row }">
            <ElDropdown>
              <ElButton size="small">{{ tl('features') }}</ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem
                    @click="editRef?.open(row as VendorManagementItem)"
                  >
                    {{ tl('edit') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    @click="goGameManagement(row as VendorManagementItem)"
                  >
                    {{ tl('menu_game_management') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    divided
                    @click="toggleStatus(row as VendorManagementItem)"
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

    <VendorManagementEditDialog
      ref="editRef"
      :callback-base-url="callbackBaseUrl"
      :callback-visible="callbackVisible"
      @saved="loadData"
    />
  </Page>
</template>
