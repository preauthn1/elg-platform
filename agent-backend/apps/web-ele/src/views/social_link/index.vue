<script lang="ts" setup>
import type { SocialLinkAgentOption, SocialLinkRow } from '#/api';

import { nextTick, onActivated, onBeforeUnmount, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElInput,
  ElInputNumber,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
  ElSwitch,
  ElTable,
  ElTableColumn,
} from 'element-plus';
import Sortable from 'sortablejs';

import {
  getSocialLinkAgentDropdownApi,
  getSocialLinkListApi,
  saveSocialLinkApi,
  SOCIAL_LINK_MAX,
  SOCIAL_LINK_TYPE_FILE,
  SOCIAL_LINK_TYPE_URL,
} from '#/api';
import ImageInput from '#/components/image-input.vue';
import { tl } from '#/locales';

defineOptions({ name: 'SocialLinkIndex' });

const loading = ref(false);
const saving = ref(false);
const rows = ref<SocialLinkRow[]>([]);

/**
 * 是否需要自行選擇代理。
 *
 * true = super admin 及其子帳號；false = 其餘代理，歸屬總代由身分決定，
 * 隱藏下拉直接顯示該總代的設定。
 */
const canSelectAgent = ref(false);
const agents = ref<SocialLinkAgentOption[]>([]);
const selectedAgentId = ref<'' | number>('');

const tableRef = ref<any>(null);
let sortable: null | Sortable = null;

async function loadAgents() {
  const res = await getSocialLinkAgentDropdownApi().catch(() => null);
  agents.value = res && isOk(res.code) ? (res.data ?? []) : [];
}

function isOk(code: number | string) {
  return Number(code) === 200;
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getSocialLinkListApi(selectedAgentId.value || undefined);

    if (!isOk(res.code)) {
      ElMessage.error(res.message || tl('operation_failed'));
      rows.value = [];
      return;
    }

    const data = res.data;
    canSelectAgent.value = data?.can_select_agent ?? false;
    rows.value = data?.items ?? [];

    // 非 super admin 的歸屬總代由後端決定，帶回來供存檔時原樣送回
    if (!canSelectAgent.value && data?.agent_id) {
      selectedAgentId.value = data.agent_id;
    }

    await nextTick();
    initSortable();
  } finally {
    loading.value = false;
  }
}

/**
 * 拖曳排序：調整陣列順序後立刻依索引重寫 sort，
 * 讓畫面上的數字等於存檔會送出的值，兩種改法不會互相蓋掉。
 */
function initSortable() {
  sortable?.destroy();
  sortable = null;

  const el = tableRef.value?.$el?.querySelector('.el-table__body tbody');
  if (!el) return;

  sortable = Sortable.create(el, {
    animation: 150,
    handle: '.drag-handle',
    // 原生 HTML5 拖放不會捲動內層容器，強制自動捲動走 fallback 路徑
    forceAutoScrollFallback: true,
    scroll: true,
    scrollSensitivity: 80,
    scrollSpeed: 15,
    bubbleScroll: true,
    onEnd: ({ newIndex, oldIndex }) => {
      if (oldIndex === undefined || newIndex === undefined) return;
      const arr = [...rows.value];
      const [moved] = arr.splice(oldIndex, 1);
      if (moved) arr.splice(newIndex, 0, moved);
      rows.value = arr.map((r, i) => ({ ...r, sort: i + 1 }));
    },
  });
}

function addRow() {
  if (rows.value.length >= SOCIAL_LINK_MAX) {
    ElMessage.warning(tl('social_link_max_reached'));
    return;
  }

  rows.value.push({
    icon_url: '',
    link_type: SOCIAL_LINK_TYPE_URL,
    link_url: '',
    name: '',
    // 手動改過 sort 之後可能有跳號，用最大值加一才不會跟既有列撞號
    sort: Math.max(0, ...rows.value.map((r) => Number(r.sort) || 0)) + 1,
    status: 1,
  });

  nextTick(initSortable);
}

async function removeRow(index: number) {
  const ok = await ElMessageBox.confirm(tl('are_you_sure')).catch(() => false);
  if (!ok) return;

  rows.value.splice(index, 1);
  nextTick(initSortable);
}

async function handleSave() {
  if (canSelectAgent.value && !selectedAgentId.value) {
    ElMessage.warning(tl('social_link_select_agent_first'));
    return;
  }

  const invalid = rows.value.some((r) => !r.name.trim());
  if (invalid) {
    ElMessage.warning(tl('social_link_name_required'));
    return;
  }

  saving.value = true;
  try {
    // 送出畫面上的 sort：拖曳時已同步重寫，手動輸入的值不再被索引蓋掉
    const items = rows.value.map((r) => ({ ...r }));

    const res = await saveSocialLinkApi({
      ...(selectedAgentId.value ? { agent_id: selectedAgentId.value } : {}),
      items,
    });

    if (isOk(res.code)) {
      ElMessage.success(tl('operation_success'));
      loadData();
    } else {
      ElMessage.error(res.message || tl('operation_failed'));
    }
  } finally {
    saving.value = false;
  }
}

function onAgentChange() {
  rows.value = [];
  loadData();
}

onBeforeUnmount(() => sortable?.destroy());

// 分頁受 KeepAlive 快取，用 onActivated 涵蓋首次進入與每次切回
onActivated(() => {
  loadAgents();
  loadData();
});
</script>

<template>
  <Page
    :title="`${tl('menu_agent_management')} > ${tl('menu_social_link_management')}`"
  >
    <!-- 僅 super admin 需要選代理；其餘代理的歸屬總代由身分決定 -->
    <ElCard v-if="canSelectAgent" class="mb-3" shadow="never">
      <div class="flex items-center gap-3">
        <span class="shrink-0 font-medium">{{ tl('agent_account') }}</span>
        <ElSelect
          v-model="selectedAgentId"
          class="!w-80"
          clearable
          filterable
          :placeholder="tl('please_select')"
          @change="onAgentChange"
        >
          <ElOption
            v-for="a in agents"
            :key="a.id"
            :label="a.username"
            :value="a.id"
          />
        </ElSelect>
      </div>
    </ElCard>

    <ElCard shadow="never">
      <template #header>
        <div class="flex items-center justify-between">
          <div>
            <span class="font-medium">{{ tl('social_link_setting') }}</span>
            <span class="text-muted-foreground ml-3 text-xs">
              {{ tl('social_link_hint') }}
            </span>
          </div>
          <ElButton
            :disabled="canSelectAgent && !selectedAgentId"
            type="success"
            @click="addRow"
          >
            {{ tl('social_link_add_row') }}
          </ElButton>
        </div>
      </template>

      <ElTable
        ref="tableRef"
        v-loading="loading"
        :data="rows"
        border
        row-key="id"
        size="small"
      >
        <!-- 拖曳把手 -->
        <ElTableColumn width="50">
          <template #default>
            <span class="drag-handle cursor-move select-none text-lg">☰</span>
          </template>
        </ElTableColumn>

        <ElTableColumn :label="tl('social_link_name')" min-width="140">
          <template #default="{ row }">
            <ElInput
              v-model="row.name"
              maxlength="50"
              :placeholder="tl('please_enter')"
            />
          </template>
        </ElTableColumn>

        <!-- 兩種都填網址，差別在前台行為：跳轉或觸發下載。檔案由營運自行
             放到可公開存取的位置（雲端硬碟、CDN 等），後台不代管 -->
        <ElTableColumn :label="tl('social_link_url')" min-width="320">
          <template #default="{ row }">
            <ElRadioGroup v-model="row.link_type" class="mb-1">
              <ElRadio :value="SOCIAL_LINK_TYPE_URL">
                {{ tl('social_link_type_url') }}
              </ElRadio>
              <ElRadio :value="SOCIAL_LINK_TYPE_FILE">
                {{ tl('social_link_type_file') }}
              </ElRadio>
            </ElRadioGroup>

            <ElInput
              v-model="row.link_url"
              :placeholder="
                row.link_type === SOCIAL_LINK_TYPE_FILE
                  ? tl('social_link_file_url_placeholder')
                  : tl('please_enter')
              "
            />
          </template>
        </ElTableColumn>

        <ElTableColumn :label="tl('social_link_sort')" width="140">
          <template #default="{ row }">
            <!-- EP 的 InputNumber 預設寬 150px，比欄位寬會把「＋」切掉 -->
            <ElInputNumber
              v-model="row.sort"
              :min="0"
              class="!w-full"
              size="small"
            />
          </template>
        </ElTableColumn>

        <ElTableColumn :label="tl('status')" width="110">
          <template #default="{ row }">
            <ElSwitch
              :active-value="1"
              :inactive-value="0"
              v-model="row.status"
            />
          </template>
        </ElTableColumn>

        <!-- 圖標走既有的 base64 上傳元件；也可直接貼現成網址 -->
        <ElTableColumn :label="tl('social_link_icon')" min-width="260">
          <template #default="{ row }">
            <ImageInput v-model="row.icon_url" />
          </template>
        </ElTableColumn>

        <ElTableColumn :label="tl('operate')" fixed="right" width="90">
          <template #default="{ $index }">
            <ElButton link type="danger" @click="removeRow($index)">
              {{ tl('delete') }}
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <div class="mt-4 flex justify-end">
        <ElButton
          :disabled="canSelectAgent && !selectedAgentId"
          :loading="saving"
          type="primary"
          @click="handleSave"
        >
          {{ tl('btn_update') }}
        </ElButton>
      </div>
    </ElCard>
  </Page>
</template>
