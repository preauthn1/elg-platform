<script lang="ts" setup>
import type { ShareholderV3CurrencyRateRow, ShareholderV3SiteRow } from '#/api';

import { computed, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElDialog,
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
  createShareholderV3SiteApi,
  getGeneralAgentDropdownApi,
  getShareholderV3CurrencyRateListApi,
  getShareholderV3SiteDetailApi,
  getShareholderV3SiteListApi,
  updateShareholderV3SiteApi,
} from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';
import { numberFormat } from '#/utils/format';

defineOptions({ name: 'ShareholderV3SiteSetting' });

const PER_PAGE = 20;
/** 來源：0=內部系統、1=外部系統 */
const SOURCE_INTERNAL = 0;

const userStore = useUserStore();
/** 子帳號要用主帳號的 id（登入 API 已把 host_id 正規化成 0 時等於自己） */
const selfId = Number(
  (userStore.userInfo as any)?.host_id ?? (userStore.userInfo as any)?.id ?? 0,
);

// site/list 一次回全部、沒有 pagination，分頁在前端做
const loading = ref(false);
const rows = ref<ShareholderV3SiteRow[]>([]);
const { handlePageChange, page, pagedRows, total } = useClientTable(rows, {
  perPage: PER_PAGE,
});

async function loadData() {
  loading.value = true;
  try {
    const res = await getShareholderV3SiteListApi({ agent_id: selfId });
    if (Number(res.code) === 1000) {
      rows.value = res.data ?? [];
    } else {
      rows.value = [];
      ElMessage.error(res.message);
    }
  } finally {
    loading.value = false;
  }
}

const title = computed(
  () => `${tl('menu_shareholder')} > ${tl('menu_shareholder_site_settingV3')}`,
);

const generalAgents = ref<Array<Record<string, any>>>([]);
const currencyRates = ref<ShareholderV3CurrencyRateRow[]>([]);

const dialogVisible = ref(false);
const saving = ref(false);
const editing = ref(false);
const form = reactive({
  agentId: '' as number | string,
  apiUrl: '',
  currencyId: '' as number | string,
  foreignAgentId: '' as number | string,
  id: '' as number | string,
  memo: '',
  siteName: '',
  source: SOURCE_INTERNAL as number | string,
  status: 1 as number | string,
  /** 後端 update 會把沒送的 whitelist 覆寫成空字串，原值要帶回去 */
  whitelist: '',
});

const isInternal = computed(() => Number(form.source) === SOURCE_INTERNAL);
/** 只有明確等於 1 才是外部系統，缺值視為內部系統 */
const isExternal = (source?: number | string) => Number(source) === 1;
const sourceLabel = (source?: number | string) =>
  isExternal(source) ? tl('source_external') : tl('source_internal');

const apiKeyVisible = ref(false);
const apiKeyLoading = ref(false);
const apiKeyInfo = ref<{ api_key?: string; site_name?: string }>({});

async function loadOptions() {
  const [agents, rates] = await Promise.all([
    getGeneralAgentDropdownApi().catch(() => []),
    getShareholderV3CurrencyRateListApi({ per_page: 999 }).catch(() => null),
  ]);
  generalAgents.value = agents ?? [];
  currencyRates.value =
    rates && Number(rates.code) === 1000 ? (rates.data ?? []) : [];
}

function openCreate() {
  editing.value = false;
  Object.assign(form, {
    agentId: '',
    apiUrl: '',
    currencyId: '',
    foreignAgentId: '',
    id: '',
    memo: '',
    siteName: '',
    source: SOURCE_INTERNAL,
    status: 1,
    whitelist: '',
  });
  dialogVisible.value = true;
}

function openEdit(row: ShareholderV3SiteRow) {
  editing.value = true;
  Object.assign(form, {
    agentId: selfId,
    apiUrl: row.api_url ?? '',
    currencyId: row.currency_id ?? '',
    foreignAgentId: row.foreign_agent_id ?? '',
    id: row.id,
    memo: row.memo ?? '',
    siteName: row.site_name ?? '',
    source: Number(row.source ?? SOURCE_INTERNAL),
    status: Number(row.status),
    whitelist: row.whitelist ?? '',
  });
  dialogVisible.value = true;
}

async function save() {
  const siteName = form.siteName.trim();
  if (!siteName) {
    ElMessage.warning(`${tl('site_name')} ${tl('required')}`);
    return;
  }
  if (!editing.value && !form.agentId) {
    ElMessage.warning(`${tl('general_agent')} ${tl('required')}`);
    return;
  }
  if (!editing.value && !form.currencyId) {
    ElMessage.warning(`${tl('currency')} ${tl('required')}`);
    return;
  }
  if (isInternal.value && !form.foreignAgentId) {
    ElMessage.warning(`${tl('foreign_agent')} ID ${tl('required')}`);
    return;
  }
  saving.value = true;
  try {
    const payload = {
      agent_id: editing.value ? selfId : form.agentId,
      api_url: form.apiUrl.trim(),
      currency_id: form.currencyId,
      memo: form.memo.trim(),
      site_name: siteName,
      status: form.status,
      whitelist: form.whitelist,
      ...(isInternal.value ? { foreign_agent_id: form.foreignAgentId } : {}),
    };
    const res = await (editing.value
      ? updateShareholderV3SiteApi({ ...payload, id: form.id })
      : createShareholderV3SiteApi({ ...payload, source: form.source }));
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      dialogVisible.value = false;
      await loadData();
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}

async function showApiKey(row: ShareholderV3SiteRow) {
  apiKeyInfo.value = { site_name: row.site_name };
  apiKeyVisible.value = true;
  apiKeyLoading.value = true;
  try {
    const res = await getShareholderV3SiteDetailApi(row.id);
    if (Number(res.code) === 1000) {
      apiKeyInfo.value = res.data ?? {};
    } else {
      ElMessage.error(res.message);
      apiKeyVisible.value = false;
    }
  } finally {
    apiKeyLoading.value = false;
  }
}

loadOptions();
loadData();
</script>

<template>
  <Page :title="title">
    <ElCard shadow="never">
      <div class="mb-3">
        <ElButton type="success" @click="openCreate">
          {{ tl('add') }}
        </ElButton>
      </div>
      <ElTable v-loading="loading" :data="pagedRows" border size="small" stripe>
        <ElTableColumn label="ID" prop="id" align="center" width="80" />
        <ElTableColumn
          :label="tl('site_name')"
          prop="site_name"
          min-width="140"
        />
        <ElTableColumn :label="tl('source')" min-width="110">
          <template #default="{ row }">
            <span
              :class="
                isExternal(row.source)
                  ? 'text-blue-500'
                  : 'text-muted-foreground'
              "
            >
              {{ sourceLabel(row.source) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="API URL" min-width="200">
          <template #default="{ row }">
            <span class="break-all text-xs">{{ row.api_url }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('currency')" min-width="100">
          <template #default="{ row }">{{ row.currency_name || '-' }}</template>
        </ElTableColumn>
        <ElTableColumn :label="`USD${tl('rate')}`" min-width="150">
          <template #default="{ row }">
            <span v-if="!Number(row.exchange_rate_to_usd)" class="text-red-500">
              {{ tl('rate_not_set') }}
            </span>
            <span v-else>
              {{ numberFormat(row.exchange_rate_to_usd, 8) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" prop="memo" min-width="120" />
        <ElTableColumn :label="tl('status')" align="center" min-width="100">
          <template #default="{ row }">
            <span
              :class="
                Number(row.status) === 1
                  ? 'text-green-600'
                  : 'text-muted-foreground'
              "
            >
              {{ Number(row.status) === 1 ? tl('enabled') : tl('disabled') }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('features')" align="center" min-width="180">
          <template #default="{ row }">
            <div class="flex flex-wrap justify-center gap-2">
              <ElButton
                size="small"
                @click="showApiKey(row as ShareholderV3SiteRow)"
              >
                API Key
              </ElButton>
              <ElButton
                size="small"
                type="primary"
                @click="openEdit(row as ShareholderV3SiteRow)"
              >
                {{ tl('edit') }}
              </ElButton>
            </div>
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

    <ElDialog
      v-model="dialogVisible"
      :title="`${editing ? tl('edit') : tl('add')} ${tl('site_name')}`"
      width="520px"
    >
      <ElForm label-width="auto" @submit.prevent="save">
        <ElFormItem v-if="!editing" :label="tl('general_agent')" required>
          <ElSelect
            v-model="form.agentId"
            :placeholder="tl('please_select')"
            class="!w-64"
            clearable
            filterable
          >
            <ElOption
              v-for="ga in generalAgents"
              :key="ga.id"
              :label="ga.username || ga.name || ga.id"
              :value="ga.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('site_name')" required>
          <ElInput v-model="form.siteName" maxlength="100" />
        </ElFormItem>
        <ElFormItem label="API URL">
          <ElInput v-model="form.apiUrl" maxlength="255" />
        </ElFormItem>
        <ElFormItem :label="tl('source')">
          <ElSelect v-if="!editing" v-model="form.source" class="!w-48">
            <ElOption :label="tl('source_internal')" :value="0" />
            <ElOption :label="tl('source_external')" :value="1" />
          </ElSelect>
          <span v-else>{{ sourceLabel(form.source) }}</span>
        </ElFormItem>
        <ElFormItem v-if="isInternal" :label="`${tl('foreign_agent')} ID`">
          <ElInput v-model="form.foreignAgentId" min="1" type="number" />
        </ElFormItem>
        <ElFormItem :label="tl('currency')" :required="!editing">
          <div class="w-full">
            <ElSelect
              v-model="form.currencyId"
              :disabled="editing"
              :placeholder="tl('please_select')"
              class="!w-64"
              clearable
            >
              <ElOption
                v-for="c in currencyRates"
                :key="c.currency_id"
                :label="c.currency_name || c.currency"
                :value="c.currency_id"
              />
            </ElSelect>
            <div class="text-muted-foreground mt-1 text-xs">
              {{ tl('currency_hint') }}
            </div>
          </div>
        </ElFormItem>
        <ElFormItem :label="tl('memo')">
          <ElInput v-model="form.memo" maxlength="200" />
        </ElFormItem>
        <ElFormItem :label="tl('status')">
          <ElSelect v-model="form.status" class="!w-48">
            <ElOption :label="tl('enabled')" :value="1" />
            <ElOption :label="tl('disabled')" :value="0" />
          </ElSelect>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="dialogVisible = false">{{ tl('cancel') }}</ElButton>
        <ElButton :loading="saving" type="primary" @click="save">
          {{ tl('save') }}
        </ElButton>
      </template>
    </ElDialog>

    <ElDialog
      v-model="apiKeyVisible"
      :title="apiKeyInfo.site_name || 'API Key'"
      width="480px"
    >
      <div v-loading="apiKeyLoading" class="min-h-12">
        <div class="mb-1 font-medium">API Key:</div>
        <code class="break-all text-xs">{{ apiKeyInfo.api_key || '-' }}</code>
      </div>
      <template #footer>
        <ElButton type="primary" @click="apiKeyVisible = false">
          {{ tl('confirm') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
