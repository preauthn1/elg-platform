<script lang="ts" setup>
import { reactive, ref } from 'vue';

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

import { getCountryViewApi, getLanguagesApi, saveCountryApi } from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const loading = ref(false);
const submitting = ref(false);
const activeTab = ref('default');
const form = reactive({
  code: '',
  country_code: '' as number | string,
  id: 0 as number | string,
  name: '',
  phone_rule: '',
  status: 1,
});
/** 全語系清單（左側頁籤），值取自 view 的 names */
const languages = ref<Array<{ id: number | string; name: string }>>([]);
const langNames = reactive<Record<string, string>>({});
let languagesLoaded = false;

async function open(id: number | string) {
  form.id = id || 0;
  form.name = '';
  form.code = '';
  form.country_code = '';
  form.phone_rule = '';
  form.status = 1;
  activeTab.value = 'default';
  for (const k of Object.keys(langNames)) delete langNames[k];
  visible.value = true;
  loading.value = true;
  try {
    if (!languagesLoaded) {
      languages.value = ((await getLanguagesApi().catch(() => [])) ??
        []) as any;
      languagesLoaded = true;
    }
    if (!id || Number(id) === 0) return;
    const res = await getCountryViewApi(id);
    if (Number(res.code) === 1000 && res.data) {
      const d = res.data;
      form.name = d.name ?? '';
      form.code = d.code ?? '';
      form.country_code = d.country_code ?? '';
      form.phone_rule = d.phone_rule ?? '';
      form.status = Number(d.status) === 1 ? 1 : 0;
      for (const n of d.names ?? []) {
        langNames[String(n.language_id)] = n.name ?? '';
      }
    }
  } finally {
    loading.value = false;
  }
}

async function submit() {
  if (!form.name || !form.code) {
    ElMessage.warning(tl('required_column'));
    return;
  }
  const names: Record<string, string> = {};
  for (const l of languages.value) names[String(l.id)] = langNames[l.id] ?? '';
  submitting.value = true;
  try {
    const res = await saveCountryApi({
      code: form.code,
      country_code: form.country_code,
      id: form.id,
      name: form.name,
      names,
      phone_rule: form.phone_rule,
      status: form.status,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    submitting.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('menu_country_management')"
    width="720"
  >
    <ElTabs v-loading="loading" v-model="activeTab" tab-position="left">
      <ElTabPane :label="tl('default')" name="default">
        <ElForm label-width="auto">
          <ElFormItem :label="tl('country_name')" required>
            <ElInput v-model="form.name" placeholder="Taiwan" />
          </ElFormItem>
          <ElFormItem :label="tl('country_code')" required>
            <ElInput v-model="form.code" placeholder="TW" />
          </ElFormItem>
          <ElFormItem :label="tl('country_code_number')">
            <ElInput v-model="form.country_code" placeholder="886" />
          </ElFormItem>
          <ElFormItem :label="tl('regular_expression')">
            <ElInput v-model="form.phone_rule" placeholder="^[1-9]\d{7}$" />
          </ElFormItem>
          <ElFormItem :label="tl('enable_status')">
            <ElSwitch
              v-model="form.status"
              :active-value="1"
              :inactive-value="0"
            />
          </ElFormItem>
        </ElForm>
      </ElTabPane>
      <ElTabPane
        v-for="l in languages"
        :key="l.id"
        :label="l.name"
        :name="String(l.id)"
      >
        <ElForm label-width="auto">
          <ElFormItem :label="tl('country_name')">
            <ElInput v-model="langNames[l.id]" />
          </ElFormItem>
        </ElForm>
      </ElTabPane>
    </ElTabs>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="submitting" type="primary" @click="submit">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
