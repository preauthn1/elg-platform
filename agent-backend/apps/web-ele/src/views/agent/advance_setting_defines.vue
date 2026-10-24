<script lang="ts" setup>
import type { SettingDefineGroup, SettingDefineRow } from '#/api';

import { reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElInputNumber,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import {
  autoAssignDefaultValueApi,
  createSettingDefineApi,
  getLanguageTextApi,
  getSettingDefinesApi,
  getSettingInsertSqlApi,
  updateLanguageTextApi,
} from '#/api';
import ImageInput from '#/components/image-input.vue';
import { tl } from '#/locales';

defineOptions({ name: 'AgentAdvanceSettingDefines' });

const DATA_TYPES = [
  { labelKey: 'string', value: 'string' },
  { labelKey: 'boolean', value: 'boolean' },
  { labelKey: 'time_date', value: 'datetime' },
  { labelKey: 'multilingual_copywriting', value: 'language_text' },
  { labelKey: 'picture', value: 'image' },
  { labelKey: 'textarea', value: 'textarea' },
  { labelKey: 'language_textarea', value: 'language_textarea' },
];

const loading = ref(false);
const groups = ref<SettingDefineGroup[]>([]);

async function loadData() {
  loading.value = true;
  try {
    const res = await getSettingDefinesApi();
    groups.value = Number(res.code) === 1000 ? (res.data?.groups ?? []) : [];
  } finally {
    loading.value = false;
  }
}

// 新增主類別 dialog
const catDialog = reactive({
  form: {
    language_text_code: '',
    memo: '',
    setting_id: undefined as number | undefined,
  },
  submitting: false,
  visible: false,
});
function openAddCategory() {
  catDialog.form = {
    language_text_code: '',
    memo: '',
    setting_id: undefined,
  };
  catDialog.visible = true;
}
async function submitCategory() {
  const settingId = catDialog.form.setting_id;
  if (!settingId || !catDialog.form.language_text_code) {
    ElMessage.warning(tl('required_column'));
    return;
  }
  catDialog.submitting = true;
  try {
    const res = await createSettingDefineApi({
      data_type: '',
      default_value: '',
      language_text_code: catDialog.form.language_text_code,
      memo: catDialog.form.memo,
      parent: 0,
      setting_id: settingId,
      type: 1,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      catDialog.visible = false;
      loadData();
    } else {
      ElMessage.error(`${res.message}.${res.data ?? ''}`);
    }
  } finally {
    catDialog.submitting = false;
  }
}

// 新增設定項 dialog
const itemDialog = reactive({
  form: {
    agent_edit: false,
    data_type: '',
    default_value: '',
    language_text_code: '',
    memo: '',
    parent: '' as number | string,
    parentName: '',
    setting_id: undefined as number | undefined,
    type: '' as number | string,
  },
  submitting: false,
  visible: false,
});
function openAddSetting(g: SettingDefineGroup) {
  itemDialog.form = {
    agent_edit: false,
    data_type: '',
    default_value: '',
    language_text_code: '',
    memo: '',
    parent: g.setting_category_id,
    parentName: `${g.setting_category_name}(${g.setting_category_id})`,
    setting_id: undefined,
    type: '',
  };
  itemDialog.visible = true;
}
async function submitItem() {
  const f = itemDialog.form;
  if (!f.setting_id || !f.language_text_code || !f.type || !f.data_type) {
    ElMessage.warning(tl('required_column'));
    return;
  }
  const settingId = f.setting_id;
  itemDialog.submitting = true;
  try {
    const res = await createSettingDefineApi({
      agent_edit: f.agent_edit ? 1 : 0,
      data_type: f.data_type,
      default_value: f.default_value,
      language_text_code: f.language_text_code,
      memo: f.memo,
      parent: f.parent,
      setting_id: settingId,
      type: f.type,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      itemDialog.visible = false;
      loadData();
    } else {
      ElMessage.error(`${res.message}.${res.data ?? ''}`);
    }
  } finally {
    itemDialog.submitting = false;
  }
}

/** 複製 INSERT SQL 到剪貼簿 */
async function copySql(settingId: number | string) {
  const res = await getSettingInsertSqlApi(settingId);
  if (Number(res.code) === 1000) {
    const sql = (res.data?.sql_list ?? []).join(' \n');
    try {
      await navigator.clipboard.writeText(sql);
      ElMessage.success(tl('success'));
    } catch {
      ElMessage.warning(sql);
    }
  } else {
    ElMessage.error(res.message);
  }
}

// 多語文字編輯 dialog
const textDialog = reactive({
  code: '',
  loading: false,
  availableLanguages: [] as string[],
  settings: [] as Array<{
    language_id: number | string;
    language_name: string;
    value: string;
  }>,
  submitting: false,
  visible: false,
});
async function openEditText(code?: string) {
  if (!code) return;
  textDialog.code = code;
  textDialog.settings = [];
  textDialog.availableLanguages = [];
  textDialog.visible = true;
  textDialog.loading = true;
  try {
    const res = await getLanguageTextApi(code);
    if (Number(res.code) === 1000) {
      textDialog.settings = (res.data?.text_settings ?? []).map((s) => ({
        language_id: s.language_id,
        language_name: s.language_name ?? String(s.language_id),
        value: s.value ?? '',
      }));
      textDialog.availableLanguages = (res.data?.available_languages ?? [])
        .map((l) => l.name ?? '')
        .filter(Boolean);
    }
  } finally {
    textDialog.loading = false;
  }
}
async function submitEditText() {
  const payload: Record<string, any> = { language_code: textDialog.code };
  for (const s of textDialog.settings)
    payload[`language_text_${s.language_id}`] = s.value;
  textDialog.submitting = true;
  try {
    const res = await updateLanguageTextApi(payload);
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      textDialog.visible = false;
      loadData();
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    textDialog.submitting = false;
  }
}

/** 自動指派預設值（type 3 且非 language_text） */
function canAutoAssign(row: SettingDefineRow) {
  return Number(row.type) === 3 && row.data_type !== 'language_text';
}
async function autoAssign(settingId: number | string) {
  const res = await autoAssignDefaultValueApi(settingId);
  ElMessage[Number(res.code) === 1000 ? 'success' : 'error'](
    String(res.data ?? res.message),
  );
}

/** is_visible=0 的設定整列淡化 */
function hiddenRowClass({ row }: { row: SettingDefineRow }) {
  return Number(row.is_visible) === 0 ? 'opacity-50' : '';
}

loadData();
</script>

<template>
  <Page
    :title="`${tl('menu_system_management')} > ${tl('menu_agent_setting_define')}`"
  >
    <div class="mb-3">
      <ElButton type="success" @click="openAddCategory">
        {{ tl('add') }} {{ tl('main_categoty') }}
      </ElButton>
    </div>
    <div v-loading="loading" class="space-y-4">
      <ElCard v-for="g in groups" :key="g.setting_category_id" shadow="never">
        <template #header>
          <div class="flex items-center gap-2">
            <span
              :class="{ 'opacity-50': Number(g.is_visible) === 0 }"
              class="text-muted-foreground"
            >
              {{ g.setting_category_id }}
            </span>
            <ElButton
              :class="{ 'opacity-50': Number(g.is_visible) === 0 }"
              class="!font-medium"
              link
              type="primary"
              @click="openEditText(g.setting_category_language_text_code)"
            >
              {{ g.setting_category_name }}
            </ElButton>
            <ElTag v-if="Number(g.is_visible) === 0" size="small" type="info">
              {{ tl('setting_is_hidden') }}
            </ElTag>
            <ElButton class="ml-auto" size="small" @click="openAddSetting(g)">
              {{ tl('add') }}
            </ElButton>
          </div>
        </template>
        <ElTable
          :data="g.settings ?? []"
          :row-class-name="hiddenRowClass"
          border
          size="small"
          stripe
        >
          <ElTableColumn :label="tl('id')" prop="setting_id" width="90" />
          <ElTableColumn :label="tl('name')" min-width="220">
            <template #default="{ row }">
              <ElButton
                link
                type="primary"
                @click="
                  openEditText((row as SettingDefineRow).language_text_code)
                "
              >
                {{ row.text }}
              </ElButton>
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('type')" min-width="130">
            <template #default="{ row }">
              {{ tl(`agent_setting_define_type_${row.type}`) }}
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('agent_edit')" align="center" width="90">
            <template #default="{ row }">
              {{
                Number((row as SettingDefineRow).agent_edit) === 1 ? '✓' : ''
              }}
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('data_type')"
            prop="data_type"
            min-width="120"
          />
          <ElTableColumn
            :label="tl('default_value')"
            prop="default_value"
            min-width="110"
          />
          <ElTableColumn
            :label="tl('action')"
            align="center"
            width="190"
            fixed="right"
          >
            <template #default="{ row }">
              <ElButton
                size="small"
                @click="copySql((row as SettingDefineRow).setting_id)"
              >
                {{ tl('copy_sql_syntax') }}
              </ElButton>
              <ElButton
                v-if="canAutoAssign(row as SettingDefineRow)"
                size="small"
                type="success"
                @click="autoAssign((row as SettingDefineRow).setting_id)"
              >
                {{ tl('preset_not_set') }}
              </ElButton>
            </template>
          </ElTableColumn>
        </ElTable>
      </ElCard>
    </div>

    <!-- 新增主類別 -->
    <ElDialog
      v-model="catDialog.visible"
      :title="`${tl('add')} ${tl('main_categoty')}`"
      width="560"
    >
      <ElForm label-width="auto">
        <ElFormItem :label="tl('id')" required>
          <ElInputNumber
            v-model="catDialog.form.setting_id"
            :max="99_999_999"
            :min="0"
            controls-position="right"
          />
        </ElFormItem>
        <ElFormItem :label="tl('language_text_code')" required>
          <ElInput
            v-model="catDialog.form.language_text_code"
            maxlength="100"
            placeholder="agent_setting_code"
          />
        </ElFormItem>
        <ElFormItem :label="tl('memo')">
          <ElInput v-model="catDialog.form.memo" />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="catDialog.visible = false">
          {{ tl('close') }}
        </ElButton>
        <ElButton
          :loading="catDialog.submitting"
          type="primary"
          @click="submitCategory"
        >
          {{ tl('add') }}
        </ElButton>
      </template>
    </ElDialog>

    <!-- 新增設定項 -->
    <ElDialog v-model="itemDialog.visible" :title="tl('add')" width="600">
      <ElForm label-width="auto">
        <ElFormItem :label="tl('main_categoty')">
          <ElInput :model-value="itemDialog.form.parentName" disabled />
        </ElFormItem>
        <ElFormItem :label="tl('id')" required>
          <ElInputNumber
            v-model="itemDialog.form.setting_id"
            :max="99_999_999"
            :min="0"
            controls-position="right"
          />
        </ElFormItem>
        <ElFormItem :label="tl('language_text_code')" required>
          <ElInput
            v-model="itemDialog.form.language_text_code"
            maxlength="100"
            placeholder="agent_setting_code"
          />
        </ElFormItem>
        <ElFormItem :label="tl('allow_agent_editing')">
          <ElCheckbox v-model="itemDialog.form.agent_edit" />
        </ElFormItem>
        <ElFormItem :label="tl('memo')">
          <ElInput v-model="itemDialog.form.memo" />
        </ElFormItem>
        <ElFormItem :label="tl('type')" required>
          <ElSelect v-model="itemDialog.form.type" class="w-full">
            <ElOption :label="tl('agent_setting_define_type_2')" :value="2" />
            <ElOption :label="tl('agent_setting_define_type_3')" :value="3" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('data_type')" required>
          <ElSelect v-model="itemDialog.form.data_type" class="w-full">
            <ElOption
              v-for="d in DATA_TYPES"
              :key="d.value"
              :label="tl(d.labelKey)"
              :value="d.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('default_value')">
          <div class="w-full">
            <ImageInput
              v-if="itemDialog.form.data_type === 'image'"
              v-model="itemDialog.form.default_value"
            />
            <ElInput v-else v-model="itemDialog.form.default_value" />
          </div>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="itemDialog.visible = false">
          {{ tl('close') }}
        </ElButton>
        <ElButton
          :loading="itemDialog.submitting"
          type="primary"
          @click="submitItem"
        >
          {{ tl('add') }}
        </ElButton>
      </template>
    </ElDialog>

    <!-- 多語文字編輯 -->
    <ElDialog
      v-model="textDialog.visible"
      :title="`${tl('edit')} ${tl('multi_language_text')}`"
      width="600"
    >
      <div class="text-muted-foreground mb-3 text-sm">
        {{ tl('language_text_code') }}：{{ textDialog.code }}
      </div>
      <div
        v-if="textDialog.availableLanguages.length > 0"
        class="mb-3 flex flex-wrap items-center gap-1.5"
      >
        <span class="text-muted-foreground text-sm">
          {{ tl('agent_backend_only_support') }}
        </span>
        <ElTag
          v-for="name in textDialog.availableLanguages"
          :key="name"
          size="small"
          type="info"
        >
          {{ name }}
        </ElTag>
      </div>
      <ElForm v-loading="textDialog.loading" label-width="auto">
        <ElFormItem
          v-for="s in textDialog.settings"
          :key="s.language_id"
          :label="s.language_name"
        >
          <ElInput v-model="s.value" />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="textDialog.visible = false">
          {{ tl('close') }}
        </ElButton>
        <ElButton
          :loading="textDialog.submitting"
          type="primary"
          @click="submitEditText"
        >
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
