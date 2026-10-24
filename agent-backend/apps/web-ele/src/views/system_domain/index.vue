<script lang="ts" setup>
import type {
  SystemDomainRow,
  SystemDomainSavePayload,
  SystemDomainTypeOption,
} from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElColorPicker,
  ElDialog,
  ElForm,
  ElFormItem,
  ElImage,
  ElInput,
  ElInputNumber,
  ElMessage,
  ElOption,
  ElSelect,
  ElSwitch,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  createSystemDomainApi,
  getSystemDomainListApi,
  getSystemDomainTypeListApi,
  searchAgentOptionsApi,
  updateSystemDomainApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import ImageInput from '#/components/image-input.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

defineOptions({ name: 'SystemDomainIndex' });

const PER_PAGE = 20;

/** 類型下拉（後端回中文名稱） */
const types = ref<SystemDomainTypeOption[]>([]);

const filters = reactive({
  domain: '',
  domain_name: '',
  domain_type_id: '' as '' | number,
  status: '' as '' | number,
});

const { handlePageChange, loadData, loading, list, page, search, total } =
  useListQuery<SystemDomainRow>(
    async ({ page, per_page }) => {
      // apis 分頁在 data.items / data.pagination，攤平成 useListQuery 的 data/pagination
      const res = await getSystemDomainListApi({
        page,
        per_page,
        domain: filters.domain,
        domain_name: filters.domain_name,
        domain_type_id: filters.domain_type_id,
        status: filters.status,
      });

      return {
        code: res.code,
        data: res.data?.items ?? [],
        message: res.message,
        pagination: res.data?.pagination ?? null,
      };
    },
    { immediate: true, perPage: PER_PAGE, showError: true },
  );

function handleClear() {
  filters.domain = '';
  filters.domain_name = '';
  filters.domain_type_id = '';
  filters.status = '';
  search();
}

/* ---- 新增 / 編輯 ---- */
const editVisible = ref(false);
const saving = ref(false);
const editId = ref<null | number>(null);

const form = reactive<SystemDomainSavePayload>({
  domain: '',
  domain_type_id: '',
  agent_id: '',
  domain_name: '',
  logo_lm: '',
  logo_dm: '',
  icon: '',
  theme_color: '',
  sort: 0,
  status: 0,
});

/* ---- 所屬代理（遠端搜尋，後端限縮在登入者的下線） ---- */
const agentOptions = ref<Array<{ id: number; username: string }>>([]);
const agentSearching = ref(false);

async function searchAgents(keyword: string) {
  if (!keyword) {
    return;
  }
  agentSearching.value = true;
  try {
    const res = await searchAgentOptionsApi(keyword);
    agentOptions.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
  } finally {
    agentSearching.value = false;
  }
}

const currentType = computed(() =>
  types.value.find((t) => t.id === Number(form.domain_type_id)),
);
/** 代理後台需淺色 + 深色兩組 LOGO；客服後台僅單一 LOGO（存 logo_lm） */
const needsDarkLogo = computed(
  () => currentType.value?.needs_dark_logo ?? false,
);
/** 色系僅客服後台使用 */
const needsThemeColor = computed(
  () => currentType.value?.needs_theme_color ?? false,
);

function resetForm() {
  form.domain = '';
  form.domain_type_id = '';
  form.agent_id = '';
  agentOptions.value = [];
  form.domain_name = '';
  form.logo_lm = '';
  form.logo_dm = '';
  form.icon = '';
  form.theme_color = '';
  form.sort = 0;
  form.status = 0;
}

function openCreate() {
  editId.value = null;
  resetForm();
  editVisible.value = true;
}

function openEdit(row: SystemDomainRow) {
  editId.value = row.id;
  form.domain = row.domain ?? '';
  form.domain_type_id = row.domain_type_id ?? '';
  form.agent_id = row.agent_id ?? '';
  // 遠端搜尋的下拉需有對應 option 才顯示得出原值
  agentOptions.value =
    row.agent_id && row.agent_username
      ? [{ id: row.agent_id, username: row.agent_username }]
      : [];
  form.domain_name = row.domain_name ?? '';
  form.logo_lm = row.logo_lm ?? '';
  form.logo_dm = row.logo_dm ?? '';
  form.icon = row.icon ?? '';
  form.theme_color = row.theme_color ?? '';
  form.sort = row.sort ?? 0;
  form.status = row.status ?? 0;
  editVisible.value = true;
}

async function handleSave() {
  if (!form.domain.trim()) {
    ElMessage.error(tl('domain'));
    return;
  }
  if (!form.domain_type_id) {
    ElMessage.error(tl('type'));
    return;
  }
  if (!form.agent_id) {
    ElMessage.error(tl('agent'));
    return;
  }

  saving.value = true;
  try {
    const payload: SystemDomainSavePayload = {
      domain: form.domain.trim(),
      domain_type_id: form.domain_type_id,
      agent_id: form.agent_id,
      domain_name: form.domain_name || null,
      logo_lm: form.logo_lm || null,
      // 客服後台無深色 LOGO，一律送 null（後端也會清空）
      logo_dm: needsDarkLogo.value ? form.logo_dm || null : null,
      icon: form.icon || null,
      // 色系僅客服後台使用，代理後台一律送 null（後端也會清空）
      theme_color: needsThemeColor.value ? form.theme_color || null : null,
      sort: form.sort ?? 0,
      status: form.status ?? 0,
    };
    const res =
      editId.value === null
        ? await createSystemDomainApi(payload)
        : await updateSystemDomainApi({ ...payload, id: editId.value });

    if (Number(res.code) === 200) {
      editVisible.value = false;
      loadData();
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}

/** 列表上直接切換狀態（沿用 update，其餘欄位帶原值） */
async function handleStatusToggle(row: SystemDomainRow, value: number) {
  const res = await updateSystemDomainApi({
    id: row.id,
    domain: row.domain ?? '',
    domain_type_id: row.domain_type_id ?? '',
    agent_id: row.agent_id ?? '',
    domain_name: row.domain_name ?? null,
    logo_lm: row.logo_lm ?? null,
    logo_dm: row.logo_dm ?? null,
    icon: row.icon ?? null,
    theme_color: row.theme_color ?? null,
    sort: row.sort ?? 0,
    status: value,
  });

  if (Number(res.code) === 200) {
    row.status = value;
  } else {
    ElMessage.error(res.message);
    loadData();
  }
}

onMounted(async () => {
  const res = await getSystemDomainTypeListApi();
  types.value = Number(res.code) === 200 ? (res.data ?? []) : [];
});
</script>

<template>
  <Page
    :title="`${tl('menu_system_management')} > ${tl('menu_system_domain')}`"
  >
    <!-- 查詢 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2 lg:grid-cols-4">
          <ElFormItem :label="tl('domain')">
            <ElInput v-model="filters.domain" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('type')">
            <ElSelect v-model="filters.domain_type_id" class="w-full" clearable>
              <ElOption
                v-for="t in types"
                :key="t.id"
                :label="t.name"
                :value="t.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('name')">
            <ElInput v-model="filters.domain_name" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="w-full" clearable>
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('disable')" :value="0" />
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

    <!-- 列表 -->
    <ElCard shadow="never">
      <div class="mb-3">
        <ElButton type="success" @click="openCreate">
          {{ tl('add') }}
        </ElButton>
      </div>

      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('id')" align="center" prop="id" width="80" />
        <ElTableColumn :label="tl('domain')" min-width="200" prop="domain">
          <template #default="{ row }">
            <span class="font-semibold text-sky-600">{{ row.domain }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('type')"
          align="center"
          min-width="110"
          prop="domain_type_name"
        />
        <ElTableColumn :label="tl('agent')" align="center" min-width="120">
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('domain_logo_lm')" align="center" width="120">
          <template #default="{ row }">
            <ElImage
              v-if="row.logo_lm"
              :preview-src-list="[row.logo_lm]"
              :src="row.logo_lm"
              class="h-8"
              fit="contain"
              preview-teleported
            />
            <span v-else>-</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('domain_logo_dm')" align="center" width="120">
          <template #default="{ row }">
            <ElImage
              v-if="row.logo_dm"
              :preview-src-list="[row.logo_dm]"
              :src="row.logo_dm"
              class="h-8 bg-slate-700"
              fit="contain"
              preview-teleported
            />
            <span v-else>-</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('domain_icon')" align="center" width="80">
          <template #default="{ row }">
            <ElImage
              v-if="row.icon"
              :preview-src-list="[row.icon]"
              :src="row.icon"
              class="h-6 w-6"
              fit="contain"
              preview-teleported
            />
            <span v-else>-</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('domain_theme_color')"
          align="center"
          width="110"
        >
          <template #default="{ row }">
            <div v-if="row.theme_color" class="flex items-center gap-2">
              <span
                :style="{ backgroundColor: row.theme_color }"
                class="inline-block h-4 w-4 shrink-0 rounded border border-gray-300"
              ></span>
              <span class="font-mono text-xs">{{ row.theme_color }}</span>
            </div>
            <span v-else>-</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('name')" min-width="140">
          <template #default="{ row }">
            {{ row.domain_name || '-' }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('sorts')"
          align="center"
          prop="sort"
          width="80"
        />
        <ElTableColumn :label="tl('status')" align="center" width="100">
          <template #default="{ row }">
            <ElSwitch
              :active-value="1"
              :inactive-value="0"
              :model-value="row.status"
              @update:model-value="
                handleStatusToggle(row as SystemDomainRow, Number($event))
              "
            />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('operate')"
          align="center"
          width="100"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              size="small"
              type="primary"
              @click="openEdit(row as SystemDomainRow)"
            >
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

    <!-- 新增 / 編輯 -->
    <ElDialog
      v-model="editVisible"
      :title="editId === null ? tl('add') : tl('edit')"
      align-center
      width="620"
    >
      <ElForm label-width="auto" @submit.prevent="handleSave">
        <ElFormItem :label="tl('domain')" required>
          <ElInput v-model="form.domain" clearable />
        </ElFormItem>
        <ElFormItem :label="tl('type')" required>
          <ElSelect v-model="form.domain_type_id" class="w-full">
            <ElOption
              v-for="t in types"
              :key="t.id"
              :label="t.name"
              :value="t.id"
            />
          </ElSelect>
        </ElFormItem>
        <!-- 所屬代理：輸入關鍵字遠端搜尋，後端限縮在登入者的下線 -->
        <ElFormItem :label="tl('agent')" required>
          <ElSelect
            v-model="form.agent_id"
            :debounce="500"
            :loading="agentSearching"
            :placeholder="tl('search')"
            :remote-method="searchAgents"
            class="w-full"
            filterable
            remote
            remote-show-suffix
          >
            <ElOption
              v-for="a in agentOptions"
              :key="a.id"
              :label="a.username"
              :value="a.id"
            />
          </ElSelect>
        </ElFormItem>
        <!-- 代理後台：淺色 + 深色兩組；客服後台：單一 LOGO（存 logo_lm） -->
        <ElFormItem
          :label="needsDarkLogo ? tl('domain_logo_lm') : tl('domain_logo')"
        >
          <div class="flex items-center gap-3">
            <ImageInput v-model="form.logo_lm as string" />
            <ElImage
              v-if="form.logo_lm"
              :src="form.logo_lm as string"
              class="h-10"
              fit="contain"
            />
          </div>
        </ElFormItem>
        <ElFormItem v-if="needsDarkLogo" :label="tl('domain_logo_dm')">
          <div class="flex items-center gap-3">
            <ImageInput v-model="form.logo_dm as string" />
            <ElImage
              v-if="form.logo_dm"
              :src="form.logo_dm as string"
              class="h-10 bg-slate-700"
              fit="contain"
            />
          </div>
        </ElFormItem>
        <ElFormItem :label="tl('domain_icon')">
          <div class="flex items-center gap-3">
            <ImageInput v-model="form.icon as string" />
            <ElImage
              v-if="form.icon"
              :src="form.icon as string"
              class="h-8 w-8"
              fit="contain"
            />
          </div>
        </ElFormItem>
        <!-- 色系僅客服後台使用 -->
        <ElFormItem v-if="needsThemeColor" :label="tl('domain_theme_color')">
          <div class="flex items-center gap-3">
            <ElColorPicker v-model="form.theme_color as string" />
            <span class="font-mono text-sm">{{ form.theme_color || '-' }}</span>
          </div>
        </ElFormItem>
        <ElFormItem :label="tl('name')">
          <ElInput v-model="form.domain_name as string" clearable />
        </ElFormItem>
        <ElFormItem :label="tl('sorts')">
          <ElInputNumber v-model="form.sort as number" :min="0" />
        </ElFormItem>
        <ElFormItem :label="tl('status')">
          <ElSwitch
            v-model="form.status as number"
            :active-value="1"
            :inactive-value="0"
          />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="editVisible = false">{{ tl('btn_cancel') }}</ElButton>
        <ElButton :loading="saving" type="primary" @click="handleSave">
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
