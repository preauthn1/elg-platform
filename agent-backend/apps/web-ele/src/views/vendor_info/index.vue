<script lang="ts" setup>
import type { VendorInfoRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
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

import {
  getVendorDropdownByTypeApi,
  getVendorInfoListApi,
  vendorInfoToggleStatusApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

import AgentVendorInfoDialog from '../agent_game/components/agent-vendor-info-dialog.vue';
import VendorInfoEditDialog from './components/vendor-info-edit-dialog.vue';

defineOptions({ name: 'VendorInfo' });

const PER_PAGE = 20;

const editRef = ref<InstanceType<typeof VendorInfoEditDialog>>();
const queryRef = ref<InstanceType<typeof AgentVendorInfoDialog>>();

const filters = reactive({
  agent_username: '',
  is_single_wallet: ' ' as number | string,
  rolling_chip_status: ' ' as number | string,
  status: ' ' as number | string,
  vendor_id: ' ' as number | string,
  vendor_info_id: '',
});

const searched = ref(false);

/**
 * 「全部」選項值是空白字元，後端未 trim 會把 " " 當成過濾值（status/is_single_wallet
 * 等被 coerce 成 0，只回停用資料）。送出前把純空白轉成空字串，讓後端略過該條件。
 */
function omitAll(v: number | string) {
  return typeof v === 'string' && v.trim() === '' ? '' : v;
}

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<VendorInfoRow>(
    ({ page, per_page }) => {
      searched.value = true;
      return getVendorInfoListApi({
        agent_username: filters.agent_username.trim(),
        is_single_wallet: omitAll(filters.is_single_wallet),
        page,
        per_page,
        rolling_chip_status: omitAll(filters.rolling_chip_status),
        status: omitAll(filters.status),
        vendor_id: omitAll(filters.vendor_id),
        vendor_info_id: filters.vendor_info_id.trim(),
      });
    },
    { perPage: PER_PAGE, showError: true },
  );
const vendors = ref<Array<{ id: number; name: string }>>([]);

/** 相關資訊彈窗 */
const infoVisible = ref(false);
const infoPairs = ref<Array<{ key: string; value: string }>>([]);

function typeName(type?: number) {
  switch (String(type)) {
    case '0': {
      return tl('testing');
    }
    case '1': {
      return tl('production');
    }
    case '2': {
      return tl('event');
    }
    default: {
      return 'unknown';
    }
  }
}

function statusText(s?: number) {
  if (Number(s) === 1) return tl('enable');
  return Number(s) === 2 ? tl('in_development') : tl('disable');
}

function showInfo(raw?: string) {
  infoPairs.value = [];
  try {
    const parsed = JSON.parse(raw ?? '{}');
    infoPairs.value = Object.entries(parsed).map(([key, value]) => ({
      key,
      value: typeof value === 'object' ? JSON.stringify(value) : String(value),
    }));
  } catch {
    infoPairs.value = [];
  }
  infoVisible.value = true;
}

function handleClear() {
  filters.vendor_id = ' ';
  filters.status = ' ';
  filters.vendor_info_id = '';
  filters.rolling_chip_status = ' ';
  filters.is_single_wallet = ' ';
  filters.agent_username = '';
}

async function toggleStatus(row: VendorInfoRow) {
  const res = await vendorInfoToggleStatusApi({
    id: row.id,
    status: Number(row.status) === 1 ? 0 : 1,
  });
  if (Number(res.code) === 1000) {
    await loadData();
  } else {
    ElMessage.error(res.message);
  }
}

const hasInfo = computed(() => list.value.length > 0);

onMounted(async () => {
  vendors.value = (await getVendorDropdownByTypeApi(1)) ?? [];
});
</script>

<template>
  <Page
    :title="`${tl('menu_new_vendor')} > ${tl('menu_vendor_info_management')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <div class="mb-3 flex gap-2">
        <ElButton type="primary" @click="queryRef?.open()">
          {{ tl('query_agent_vendor_info') }}
        </ElButton>
        <ElButton type="danger" @click="editRef?.open()">
          {{ tl('add_vendor_info') }}
        </ElButton>
      </div>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
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
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="w-full">
              <ElOption :label="tl('all')" value=" " />
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('disable')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem label="ID">
            <ElInput v-model="filters.vendor_info_id" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('label_rolling_chip')">
            <ElSelect v-model="filters.rolling_chip_status" class="w-full">
              <ElOption :label="tl('all')" value=" " />
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('disable')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('label_wallet_type')">
            <ElSelect v-model="filters.is_single_wallet" class="w-full">
              <ElOption :label="tl('all')" value=" " />
              <ElOption :label="tl('single_wallet')" :value="1" />
              <ElOption :label="tl('transfer_wallet')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('label_agent_account')">
            <ElInput v-model="filters.agent_username" clearable />
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
        <ElTableColumn :label="tl('id')" align="center" prop="id" width="80" />
        <ElTableColumn
          :label="tl('name')"
          align="center"
          prop="name"
          min-width="120"
        />
        <ElTableColumn
          :label="tl('label_vendor')"
          align="center"
          min-width="130"
        >
          <template #default="{ row }">
            {{ row.vendor_name }}
            <span class="text-xs text-gray-400">({{ row.vendor_id }})</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('type')" align="center" width="100">
          <template #default="{ row }">{{ typeName(row.type) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('currency_code')"
          align="center"
          prop="currency"
          width="100"
        />
        <ElTableColumn
          :label="tl('cost')"
          align="center"
          prop="cost"
          width="90"
        />
        <ElTableColumn
          :label="tl('deposit_vendor_info')"
          align="center"
          width="100"
        >
          <template #default="{ row }">
            <ElButton size="small" type="info" @click="showInfo(example.invalid)">
              {{ tl('detail') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('prepared_account')"
          align="center"
          width="110"
        >
          <template #default="{ row }">
            <span class="text-sky-600">{{ row.prepared_account_number }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('account_prefix')"
          align="center"
          prop="account_prefix"
          min-width="120"
        />
        <ElTableColumn
          :label="tl('label_agent_account')"
          align="center"
          min-width="130"
        >
          <template #default="{ row }">
            <div
              v-if="(row.agent_accounts ?? []).length > 0"
              class="flex flex-wrap justify-center gap-x-2"
            >
              <AgentAccount
                v-for="account in row.agent_accounts ?? []"
                :key="account"
                :username="account"
              />
            </div>
            <template v-else>-</template>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('label_rolling_chip')"
          align="center"
          width="90"
        >
          <template #default="{ row }">
            {{
              Number(row.rolling_chip_status) === 1
                ? tl('enable')
                : tl('disable')
            }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('label_wallet_type')"
          align="center"
          width="110"
        >
          <template #default="{ row }">
            {{
              Number(row.is_single_wallet) === 1
                ? tl('single_wallet')
                : tl('transfer_wallet')
            }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" align="center" width="100">
          <template #default="{ row }">
            <span :class="{ 'text-red-500': Number(row.status) !== 1 }">
              {{ statusText(row.status) }}
            </span>
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
                  <ElDropdownItem @click="toggleStatus(row as VendorInfoRow)">
                    {{
                      Number(row.status) === 1 ? tl('disable') : tl('enable')
                    }}
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
        v-if="hasInfo"
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>

    <!-- 相關資訊 -->
    <ElDialog
      v-model="infoVisible"
      :title="tl('relevant_information')"
      align-center
      width="600"
    >
      <ElTable :data="infoPairs" border size="small">
        <ElTableColumn label="Key" prop="key" width="200" />
        <ElTableColumn label="Value" prop="value">
          <template #default="{ row }">
            <span class="break-all">{{ row.value }}</span>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElDialog>

    <VendorInfoEditDialog ref="editRef" @saved="loadData" />
    <AgentVendorInfoDialog ref="queryRef" />
  </Page>
</template>
