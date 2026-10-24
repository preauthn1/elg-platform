<script lang="ts" setup>
import type { VendorDetailForm } from '../form-model';

import type { GgrVendorOptions } from '#/api';

import { computed, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElSwitch,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  getGgrVendorOptionsApi,
  getGgrVendorSettingViewApi,
  saveGgrVendorSettingApi,
} from '#/api';
import { tl } from '#/locales';

import {
  detailToForm,
  formToPayload,
  makeDefaultTier,
  rowFirstError,
} from '../form-model';
import VendorSettingDetailTable from './vendor-setting-detail-table.vue';

/**
 * 4123 建立/編輯彈窗：名稱/狀態 + 遊戲類型 tabs（資料驅動自 vendor-options）。
 *
 * 列組成 = 總代支援的全部 vendor_info × game_type 組合（未設定過的帶預設值），
 * 儲存時全量送出（後端明細全量替換）。copy 模式回填後清 id 走 create。
 */

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const editId = ref<number | string>('');
const mode = ref<'copy' | 'edit'>('edit');
const isEdit = computed(() => mode.value === 'edit' && Boolean(editId.value));
const saving = ref(false);
const loading = ref(false);

const name = ref('');
const status = ref(true);
const gameTypes = ref<GgrVendorOptions['game_types']>([]);
const detailRows = ref<VendorDetailForm[]>([]);
const activeTab = ref('');

function rowsOfType(typeId: number) {
  return detailRows.value.filter((row) => row.gameTypeId === typeId);
}

/** 以支援組合為骨架建列；已存明細（編輯/複製）依 vendor_info×game_type 合併回填。
 * 已不在支援範圍的舊組合不再顯示（後端寫入複驗也會拒絕它們）。
 *
 * markNew：既有設置（編輯/複製/更新遊戲商）中首次出現的組合＝新開通的
 * 遊戲商——標「新增」徽章且加權預設 0（=不計算），與後端「查無設定不計算」
 * 一致，順手儲存不會把新遊戲商誤全開（工單需求 13）。 */
function buildRows(
  options: GgrVendorOptions,
  saved: Map<string, VendorDetailForm>,
  markNew: boolean,
) {
  const typeNames = new Map(options.game_types.map((t) => [t.id, t.name]));
  detailRows.value = options.items.map((item) => {
    const merged = saved.get(`${item.vendor_info_id}-${item.game_type_id}`);
    if (merged) {
      merged.vendorInfoName = item.vendor_info_name;
      merged.gameTypeName = typeNames.get(item.game_type_id) ?? '';
      return merged;
    }
    return {
      excludedGameIds: [],
      gameTypeId: item.game_type_id,
      gameTypeName: typeNames.get(item.game_type_id) ?? '',
      isNew: markNew,
      status: true,
      tiers: [makeDefaultTier(markNew ? '0' : '100')],
      vendorId: item.vendor_id,
      vendorInfoId: item.vendor_info_id,
      vendorInfoName: item.vendor_info_name,
    };
  });
}

/** 更新遊戲商：重載支援組合，保留已編輯值；新出現的組合標「新增」＋加權 0。 */
const refreshing = ref(false);
async function refreshVendors() {
  refreshing.value = true;
  try {
    const res = await getGgrVendorOptionsApi().catch(() => null);
    const options = res && Number(res.code) === 200 ? res.data : null;
    if (!options) {
      ElMessage.error(res?.message ?? tl('operation_failed'));
      return;
    }
    const saved = new Map<string, VendorDetailForm>();
    for (const row of detailRows.value) {
      saved.set(`${row.vendorInfoId}-${row.gameTypeId}`, row);
    }
    gameTypes.value = options.game_types;
    if (!options.game_types.some((t) => String(t.id) === activeTab.value)) {
      activeTab.value = String(options.game_types[0]?.id ?? '');
    }
    buildRows(options, saved, true);
  } finally {
    refreshing.value = false;
  }
}

async function open(
  id: number | string = '',
  openMode: 'copy' | 'edit' = 'edit',
) {
  editId.value = id || '';
  mode.value = openMode;
  name.value = '';
  status.value = true;
  gameTypes.value = [];
  detailRows.value = [];
  visible.value = true;
  loading.value = true;

  try {
    const [optionsRes, viewRes] = await Promise.all([
      getGgrVendorOptionsApi().catch(() => null),
      id ? getGgrVendorSettingViewApi(id).catch(() => null) : null,
    ]);

    const options =
      optionsRes && Number(optionsRes.code) === 200 ? optionsRes.data : null;
    if (!options) {
      ElMessage.error(optionsRes?.message ?? tl('operation_failed'));
      visible.value = false;
      return;
    }

    const saved = new Map<string, VendorDetailForm>();
    if (id) {
      const detail =
        viewRes && Number(viewRes.code) === 200 ? viewRes.data : null;
      if (!detail) {
        ElMessage.error(viewRes?.message ?? tl('operation_failed'));
        visible.value = false;
        return;
      }
      // copy 模式沿用內容但走 create（名稱留白讓使用者重新命名）
      name.value = openMode === 'copy' ? '' : detail.name;
      status.value = Number(detail.status) === 1;
      for (const item of detail.details ?? []) {
        saved.set(
          `${item.vendor_info_id}-${item.game_type_id}`,
          detailToForm(item),
        );
      }
    }

    gameTypes.value = options.game_types;
    activeTab.value = String(options.game_types[0]?.id ?? '');
    // 編輯/複製既有設置時，清單裡多出來的組合＝後來新開通的遊戲商
    buildRows(options, saved, Boolean(id));
  } finally {
    loading.value = false;
  }
}

async function handleSave() {
  if (!name.value.trim()) {
    ElMessage.error(`${tl('please_enter')} ${tl('ggr_setting_name')}`);
    return;
  }
  if (detailRows.value.length === 0) {
    ElMessage.error(tl('operation_failed'));
    return;
  }
  // 欄位級紅字已即時顯示，送出前整體再擋一次；報出第一個真正的錯誤原因，
  // 並把 tab 切到出錯的類型——明細列可達數百列，只給一句訊息找不到人。
  for (const row of detailRows.value) {
    const message = rowFirstError(row);
    if (message) {
      activeTab.value = String(row.gameTypeId);
      ElMessage.error(
        `${row.vendorInfoName} - ${row.gameTypeName}: ${message}`,
      );
      return;
    }
  }

  saving.value = true;
  try {
    const payload: Record<string, unknown> = {
      details: formToPayload(detailRows.value),
      name: name.value.trim(),
      status: status.value ? 1 : 0,
    };
    if (isEdit.value) {
      payload.id = editId.value;
    }

    const res = await saveGgrVendorSettingApi(payload, isEdit.value);
    if (Number(res.code) === 200) {
      ElMessage.success(tl('operation_success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${isEdit ? tl('edit') : tl('add')}${tl('menu_ggr_vendor_setting')}`"
    top="4vh"
    width="1200"
  >
    <div v-loading="loading">
      <!-- 版位對齊參考站：名稱一列、狀態次列（左上直排），再接「遊戲商設定」區塊 -->
      <ElForm label-position="top">
        <div class="grid grid-cols-1 gap-x-6 md:grid-cols-2">
          <ElFormItem :label="tl('ggr_setting_name')" required>
            <ElInput v-model="name" :placeholder="tl('please_enter')" />
          </ElFormItem>
        </div>
        <ElFormItem :label="tl('status')">
          <ElSwitch
            v-model="status"
            :active-text="tl('enable')"
            :inactive-text="tl('disable')"
          />
        </ElFormItem>
      </ElForm>

      <div class="mb-1 flex items-center justify-between">
        <span class="text-sm font-medium">
          {{ tl('ggr_provider_setting_section') }}
        </span>
        <ElButton :loading="refreshing" size="small" @click="refreshVendors">
          {{ tl('ggr_update_vendors') }}
        </ElButton>
      </div>

      <!-- 遊戲類型 tabs 資料驅動（限總代支援範圍），示意圖的 5 個 tab 只是示意 -->
      <ElTabs v-model="activeTab">
        <ElTabPane
          v-for="gameType in gameTypes"
          :key="gameType.id"
          :label="gameType.name"
          :name="String(gameType.id)"
        >
          <VendorSettingDetailTable :rows="rowsOfType(gameType.id)" />
        </ElTabPane>
      </ElTabs>
    </div>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('save') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
