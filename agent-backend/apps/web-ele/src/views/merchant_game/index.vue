<script lang="ts" setup>
import type { MerchantGameQuerySnapshot } from './customized-hint';

import type {
  MerchantGameCustomizedItem,
  MerchantGameCustomizedItems,
  MerchantGameListBody,
  MerchantGameRow,
} from '#/api/merchant-game';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElSwitch,
  ElTable,
  ElTableColumn,
  ElTag,
  ElTooltip,
} from 'element-plus';

import { getAgentGroupDropdownApi } from '#/api/agent';
import {
  batchUpdateMerchantGamesApi,
  getMerchantGameListApi,
  toggleMerchantGameFieldApi,
} from '#/api/merchant-game';
import { getVendorDropdownByTypeApi, vendorNameWithId } from '#/api/vendor';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

import MerchantGameEditDialog from './components/merchant-game-edit-dialog.vue';
import {
  customizedStatusLocaleKey,
  customizedStatusTagType,
  shouldShowCustomizedHint,
} from './customized-hint';

defineOptions({ name: 'MerchantGameIndex' });

const PER_PAGE = 50;

const editRef = ref<InstanceType<typeof MerchantGameEditDialog>>();

const filters = reactive({
  bettable: '' as number | string,
  game_code: '',
  group_id: '' as number | string,
  name: '',
  status: '' as number | string,
  vendor_id: '' as number | string,
});

/** 送出當下的查詢條件快照：查完又改篩選時判定才不會漂移 */
const lastQuery = ref<MerchantGameQuerySnapshot>({ ...filters });
/** 查無資料時後端附上的個性化設置版位（target/tickets#4373） */
const customizedItems = ref<MerchantGameCustomizedItems | null>(null);

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<MerchantGameRow>(
    ({ page, per_page }) => {
      lastQuery.value = { ...filters };
      // useListQuery 的例外路徑不會呼叫 onLoaded，這裡先清掉避免殘留上一輪的值
      customizedItems.value = null;
      return getMerchantGameListApi({
        bettable: filters.bettable === '' ? undefined : filters.bettable,
        game_code: filters.game_code || undefined,
        group_id: filters.group_id || undefined,
        name: filters.name || undefined,
        page,
        per_page,
        status: filters.status === '' ? undefined : filters.status,
        vendor_id: filters.vendor_id || undefined,
      });
    },
    {
      // 成功／非 1000 都會回呼；沒有 customized_items 時收回 null，區塊自然收起
      onLoaded: (res) => {
        customizedItems.value =
          (res as MerchantGameListBody).customized_items ?? null;
      },
      perPage: PER_PAGE,
      showError: true,
    },
  );

/** 是否以個性化設置版位取代清單（工單四條件＋後端有回版位） */
const showHint = computed(() =>
  shouldShowCustomizedHint(lastQuery.value, total.value, customizedItems.value),
);

/** 版位狀態顯示文字（後端只會給 0/1/2） */
function customizedStatusText(status: number) {
  const key = customizedStatusLocaleKey[status];
  if (!key) return '-';
  return tl(key);
}

/** 版位狀態的 ElTag 顏色 */
function customizedStatusType(status: number) {
  return customizedStatusTagType[status] ?? 'danger';
}

const vendorGroups = ref<Array<{ id: number; name: string }>>([]);
const vendors = ref<Array<{ id: number; name: string }>>([]);

/** 提示標題要帶的組別名稱；下拉找不到就退回顯示 id */
const hintGroupName = computed(() => {
  const id = lastQuery.value.group_id;
  const group = vendorGroups.value.find((g) => String(g.id) === String(id));
  return group?.name ?? String(id);
});

/** 「此遊戲商在「XXX」的個性化設置狀態」 */
const hintTitle = computed(() =>
  tl('merchant_game_customized_hint_title').replaceAll(
    '{group}',
    hintGroupName.value,
  ),
);

function handleReset() {
  filters.group_id = '';
  filters.vendor_id = '';
  filters.name = '';
  filters.game_code = '';
  filters.status = '';
  filters.bettable = '';
  search();
}

// ---------------------------------------------------------------------------
// 本頁勾選（key = vig_id）
// ---------------------------------------------------------------------------

const selectedIds = ref<Set<number>>(new Set());

const pageIds = computed(() => list.value.map((row) => row.vig_id));

const allChecked = computed(
  () =>
    pageIds.value.length > 0 &&
    pageIds.value.every((id) => selectedIds.value.has(id)),
);

const partiallyChecked = computed(
  () =>
    !allChecked.value && pageIds.value.some((id) => selectedIds.value.has(id)),
);

function toggleAll(checked: boolean) {
  const next = new Set(selectedIds.value);
  for (const id of pageIds.value) {
    if (checked) {
      next.add(id);
    } else {
      next.delete(id);
    }
  }
  selectedIds.value = next;
}

function toggleRow(id: number, checked: boolean) {
  const next = new Set(selectedIds.value);
  if (checked) {
    next.add(id);
  } else {
    next.delete(id);
  }
  selectedIds.value = next;
}

// ---------------------------------------------------------------------------
// 單筆切換 / 批次操作
// ---------------------------------------------------------------------------

async function toggleField(row: MerchantGameRow, field: 'bettable' | 'status') {
  const next = Number(row[field]) === 1 ? 0 : 1;
  const res = await toggleMerchantGameFieldApi({
    field,
    group_id: filters.group_id || undefined,
    value: next,
    vig_id: row.vig_id,
  });
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    row[field] = next;
  } else {
    ElMessage.error(res.message || tl('failure'));
  }
}

async function batchUpdate(field: 'bettable' | 'status', value: 0 | 1) {
  if (selectedIds.value.size === 0) {
    ElMessage.warning(tl('no_game_selected'));
    return;
  }
  const res = await batchUpdateMerchantGamesApi({
    field,
    group_id: filters.group_id || undefined,
    value,
    vig_ids: [...selectedIds.value],
  });
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    for (const row of list.value) {
      if (selectedIds.value.has(row.vig_id)) row[field] = value;
    }
    selectedIds.value = new Set();
  } else {
    ElMessage.error(res.message || tl('failure'));
  }
}

function goEdit(row: MerchantGameRow) {
  editRef.value?.open(row.vig_id, filters.group_id);
}

onMounted(async () => {
  const [groups, vendorList] = await Promise.all([
    getAgentGroupDropdownApi().then((v) => v ?? []),
    getVendorDropdownByTypeApi(1).then((v) => v ?? []),
  ]);
  vendorGroups.value = groups.filter((g) => Number(g.agent_group_type) === 5);
  vendors.value = vendorList ?? [];
  loadData();
});
</script>

<template>
  <Page
    :title="`${tl('vendor_management')} > ${tl('menu_merchant_game_management')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('vendor_group')">
            <ElSelect
              v-model="filters.group_id"
              class="w-full"
              clearable
              filterable
            >
              <ElOption
                v-for="g in vendorGroups"
                :key="g.id"
                :label="g.name"
                :value="g.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('label_vendor')">
            <ElSelect
              v-model="filters.vendor_id"
              class="w-full"
              clearable
              filterable
            >
              <ElOption
                v-for="v in vendors"
                :key="v.id"
                :label="vendorNameWithId({ id: v.id, name: v.name })"
                :value="v.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('game_name')">
            <ElInput v-model="filters.name" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('game_code')">
            <ElInput v-model="filters.game_code" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="w-full" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('disable')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('bettable')">
            <ElSelect v-model="filters.bettable" class="w-full" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('yes')" :value="1" />
              <ElOption :label="tl('no')" :value="0" />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="handleReset">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <!-- 查無遊戲時解釋原因：列出該廠商在本組別的個性化設置版位（target/tickets#4373） -->
    <ElCard v-if="showHint" class="mb-3 !border-dashed" shadow="never">
      <div class="mb-3 font-medium">{{ hintTitle }}</div>
      <ElTable :data="customizedItems?.items ?? []" border size="small" stripe>
        <ElTableColumn
          :label="tl('vendor_name')"
          align="center"
          min-width="140"
        >
          <template #default>
            <div class="font-bold">
              {{ customizedItems?.vendor.name }}
            </div>
            <div class="text-xs text-gray-400">
              {{ tl('id') }} {{ customizedItems?.vendor.id }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('merchant_name')"
          align="center"
          min-width="180"
        >
          <template #default>
            <template v-if="(customizedItems?.vendor_infos.length ?? 0) > 0">
              <span
                v-for="(info, idx) in customizedItems?.vendor_infos"
                :key="info.id"
              >
                <span v-if="idx > 0">, </span>
                <span class="font-bold">{{ info.name }}</span>
                <span class="ml-1 text-xs text-gray-400">
                  {{ tl('id') }} {{ info.id }}
                </span>
                <span
                  v-if="Number(info.status) === 0"
                  class="ml-1 text-xs text-gray-400"
                >
                  （{{ tl('disable') }}）
                </span>
              </span>
            </template>
            <span v-else class="text-gray-400">-</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('slot_name')" align="center" min-width="160">
          <template #default="{ row }">
            <div class="font-bold">
              {{ (row as MerchantGameCustomizedItem).name }}
            </div>
            <div class="text-xs text-gray-400">
              {{
                tl(
                  `game_vendor_entrance_type_${(row as MerchantGameCustomizedItem).item_type}`,
                )
              }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" align="center" min-width="110">
          <template #default="{ row }">
            <ElTag
              :type="
                customizedStatusType((row as MerchantGameCustomizedItem).status)
              "
            >
              {{
                customizedStatusText((row as MerchantGameCustomizedItem).status)
              }}
            </ElTag>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-3 flex flex-wrap justify-end gap-2">
        <ElButton type="danger" @click="batchUpdate('bettable', 0)">
          {{ tl('batch_bettable_off') }}
        </ElButton>
        <ElButton type="success" @click="batchUpdate('bettable', 1)">
          {{ tl('batch_bettable_on') }}
        </ElButton>
        <ElButton type="danger" @click="batchUpdate('status', 0)">
          {{ tl('batch_disable') }}
        </ElButton>
        <ElButton type="success" @click="batchUpdate('status', 1)">
          {{ tl('batch_enable') }}
        </ElButton>
      </div>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn align="center" width="50">
          <template #header>
            <ElTooltip :content="tl('select_all_page')" placement="top">
              <ElCheckbox
                :indeterminate="partiallyChecked"
                :model-value="allChecked"
                @change="(val: any) => toggleAll(Boolean(val))"
              />
            </ElTooltip>
          </template>
          <template #default="{ row }">
            <ElCheckbox
              :model-value="selectedIds.has(row.vig_id)"
              @change="(val: any) => toggleRow(row.vig_id, Boolean(val))"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('vendor_name')"
          align="center"
          min-width="140"
        >
          <template #default="{ row }">
            <div class="font-bold">{{ row.vendor_name }}</div>
            <div class="text-xs text-gray-400">
              {{ tl('id') }} {{ row.vendor_id }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('merchant_name')"
          align="center"
          min-width="140"
        >
          <template #default="{ row }">
            <div class="font-bold">{{ row.vendor_info_name }}</div>
            <div class="text-xs text-gray-400">
              {{ tl('id') }} {{ row.vendor_info_id }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('game_code')"
          align="center"
          min-width="140"
          prop="game_code"
        />
        <ElTableColumn :label="tl('game_name')" align="center" min-width="160">
          <template #default="{ row }">
            <div class="font-bold">{{ row.game_name }}</div>
            <div class="text-xs text-gray-400">
              {{ tl('id') }} {{ row.game_id }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('picture')" align="center" width="160">
          <template #default="{ row }">
            <img
              v-if="row.game_image"
              :src="row.game_image"
              style="width: 100%; height: 80px; object-fit: contain"
            />
            <span v-else class="text-gray-400">{{ tl('not_uploaded') }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" align="center" width="90">
          <template #default="{ row }">
            <ElSwitch
              :model-value="Number(row.status) === 1"
              @change="toggleField(row as MerchantGameRow, 'status')"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('bettable')" align="center" width="90">
          <template #default="{ row }">
            <ElSwitch
              :model-value="Number(row.bettable) === 1"
              @change="toggleField(row as MerchantGameRow, 'bettable')"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('features')"
          align="center"
          width="90"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton size="small" @click="goEdit(row as MerchantGameRow)">
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

    <MerchantGameEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
