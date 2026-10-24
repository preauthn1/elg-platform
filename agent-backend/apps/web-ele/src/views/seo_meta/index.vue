<script lang="ts" setup>
import type {
  SeoMetaAgentOption,
  SeoMetaDomainOption,
  SeoMetaListResult,
  SeoMetaRow,
} from '#/api';

import { onActivated, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { IconifyIcon } from '@vben/icons';

import {
  ElButton,
  ElCard,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElSwitch,
  ElTable,
  ElTableColumn,
  ElTooltip,
} from 'element-plus';

import {
  getSeoMetaAgentDropdownApi,
  getSeoMetaDomainDropdownApi,
  getSeoMetaListApi,
  saveSeoMetaApi,
  SEO_META_PAGES,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'SeoMetaIndex' });

const loading = ref(false);
const saving = ref(false);

/**
 * 是否需要自行選擇代理。
 *
 * true = super admin 及其子帳號，要先選總代才列得出域名；
 * false = 其餘代理，歸屬總代由身分決定，隱藏代理下拉直接列自己總代的網域。
 */
const canSelectAgent = ref(false);
const agents = ref<SeoMetaAgentOption[]>([]);
const selectedAgentId = ref<'' | number>('');

const domains = ref<SeoMetaDomainOption[]>([]);
const selectedDomainId = ref<'' | number>('');

/** 此域名是否為該總代線的預設回退；同線只能有一個，後端存檔時會關掉前一個 */
const isDefault = ref(false);
const rows = ref<SeoMetaRow[]>([]);

function isOk(code: number | string) {
  return Number(code) === 200;
}

/**
 * 依 SEO_META_PAGES 的順序建列：後端只回有存過的頁面，
 * 沒存過的頁面補空白列，讓使用者一律看得到完整的頁面清單。
 */
function buildRows(items: SeoMetaRow[]): SeoMetaRow[] {
  return SEO_META_PAGES.map((page) => {
    const found = items.find((item) => item.page === page);
    if (found) return { ...found };
    return { description: '', keywords: '', page, title: '' };
  });
}

function resetDomainState() {
  domains.value = [];
  selectedDomainId.value = '';
  rows.value = [];
  isDefault.value = false;
}

async function loadDomains() {
  const res = await getSeoMetaDomainDropdownApi(
    selectedAgentId.value || undefined,
  ).catch(() => null);
  domains.value = res && isOk(res.code) ? (res.data ?? []) : [];
}

/**
 * 進頁先取代理下拉：只有 super admin 會拿到非空陣列，
 * 以此判斷要不要先選代理；其餘代理直接載入自己總代的網域。
 *
 * 切回分頁時保留原本的選擇：重新列域名後，選過的域名若仍在清單內就重載其設定，
 * 已不在（例如網域被移除）才清掉。
 */
async function init() {
  const res = await getSeoMetaAgentDropdownApi().catch(() => null);
  agents.value = res && isOk(res.code) ? (res.data ?? []) : [];
  canSelectAgent.value = agents.value.length > 0;

  if (!canSelectAgent.value) selectedAgentId.value = '';
  if (canSelectAgent.value && !selectedAgentId.value) {
    resetDomainState();
    return;
  }

  await loadDomains();
  const stillExists = domains.value.some(
    (d) => d.id === selectedDomainId.value,
  );
  if (!stillExists) {
    selectedDomainId.value = '';
    rows.value = [];
    isDefault.value = false;
    return;
  }

  await loadData();
}

function applyListResult(data?: SeoMetaListResult) {
  canSelectAgent.value = data?.can_select_agent ?? canSelectAgent.value;
  isDefault.value = data?.is_default === 1;
  rows.value = buildRows(data?.items ?? []);
}

async function loadData() {
  if (!selectedDomainId.value) {
    rows.value = [];
    isDefault.value = false;
    return;
  }

  loading.value = true;
  try {
    const res = await getSeoMetaListApi({
      ...(selectedAgentId.value ? { agent_id: selectedAgentId.value } : {}),
      agent_domain_id: selectedDomainId.value,
    });

    if (!isOk(res.code)) {
      ElMessage.error(res.message || tl('operation_failed'));
      rows.value = [];
      return;
    }

    applyListResult(res.data);
  } finally {
    loading.value = false;
  }
}

async function handleSave() {
  if (canSelectAgent.value && !selectedAgentId.value) {
    ElMessage.warning(tl('seo_meta_select_agent_first'));
    return;
  }
  if (!selectedDomainId.value) {
    ElMessage.warning(tl('seo_meta_select_domain_first'));
    return;
  }

  saving.value = true;
  try {
    const res = await saveSeoMetaApi({
      ...(selectedAgentId.value ? { agent_id: selectedAgentId.value } : {}),
      agent_domain_id: selectedDomainId.value,
      is_default: isDefault.value,
      items: rows.value.map((r) => ({ ...r })),
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

/** 換代理後原本的域名不屬於新總代，一併清掉再重新列 */
function onAgentChange() {
  resetDomainState();
  if (selectedAgentId.value) loadDomains();
}

function onDomainChange() {
  loadData();
}

// 分頁受 KeepAlive 快取，用 onActivated 涵蓋首次進入與每次切回
onActivated(() => {
  init();
});
</script>

<template>
  <Page
    :title="`${tl('menu_promotion_management')} > ${tl('menu_seo_meta_management')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <div class="flex flex-wrap items-center gap-3">
        <!-- 僅 super admin 需要選代理；其餘代理的歸屬總代由身分決定 -->
        <template v-if="canSelectAgent">
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
        </template>

        <span class="shrink-0 font-medium">{{ tl('seo_meta_domain') }}</span>
        <ElSelect
          v-model="selectedDomainId"
          class="!w-80"
          :disabled="canSelectAgent && !selectedAgentId"
          filterable
          :placeholder="tl('please_select')"
          @change="onDomainChange"
        >
          <ElOption
            v-for="d in domains"
            :key="d.id"
            :label="d.domain"
            :value="d.id"
          />
        </ElSelect>
      </div>
    </ElCard>

    <ElCard shadow="never">
      <template #header>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <span class="font-medium">
              {{ tl('menu_seo_meta_management') }}
            </span>
            <span class="text-muted-foreground ml-3 text-xs">
              {{ tl('seo_meta_hint') }}
            </span>
          </div>
          <div class="flex items-center gap-3">
            <div class="flex items-center gap-2">
              <span>{{ tl('seo_meta_is_default') }}</span>
              <ElTooltip :content="tl('seo_meta_is_default_hint')">
                <IconifyIcon class="cursor-help" icon="lucide:info" />
              </ElTooltip>
              <ElSwitch v-model="isDefault" />
            </div>
            <ElButton
              :disabled="!selectedDomainId"
              :loading="saving"
              type="primary"
              @click="handleSave"
            >
              {{ tl('btn_update') }}
            </ElButton>
          </div>
        </div>
      </template>

      <ElTable v-loading="loading" :data="rows" border size="small">
        <ElTableColumn :label="tl('seo_meta_page')" width="160">
          <template #default="{ row }">
            {{ tl(`seo_meta_page_${row.page}`) }}
          </template>
        </ElTableColumn>

        <ElTableColumn :label="tl('seo_meta_title')" min-width="220">
          <template #default="{ row }">
            <ElInput v-model="row.title" maxlength="255" show-word-limit />
          </template>
        </ElTableColumn>

        <ElTableColumn :label="tl('seo_meta_description')" min-width="320">
          <template #default="{ row }">
            <ElInput
              v-model="row.description"
              :autosize="{ minRows: 2, maxRows: 6 }"
              maxlength="500"
              show-word-limit
              type="textarea"
            />
          </template>
        </ElTableColumn>

        <ElTableColumn :label="tl('seo_meta_keywords')" min-width="260">
          <template #default="{ row }">
            <ElInput
              v-model="row.keywords"
              maxlength="500"
              :placeholder="tl('seo_meta_keywords_placeholder')"
            />
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>
  </Page>
</template>
