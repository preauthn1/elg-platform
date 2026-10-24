<script lang="ts" setup>
import type { GameTypeManageRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElSwitch,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  getGameTypeManageListApi,
  getGameTypeViewApi,
  getLanguagesApi,
  saveGameTypeApi,
} from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const loading = ref(false);
const submitting = ref(false);
const parents = ref<GameTypeManageRow[]>([]);
const languages = ref<Array<{ id: number | string; name: string }>>([]);
const activeLang = ref('default');
const form = reactive({
  id: 0 as number | string,
  name: '',
  names: {} as Record<string, string>,
  parent: 0 as number | string,
  status: 1,
});

async function open(id: number | string) {
  form.id = id || 0;
  form.name = '';
  form.names = {};
  form.parent = 0;
  form.status = 1;
  activeLang.value = 'default';
  visible.value = true;
  if (!id || Number(id) === 0) return;
  loading.value = true;
  try {
    const res = await getGameTypeViewApi(id);
    if (Number(res.code) === 1000 && res.data) {
      const d = res.data;
      form.name = d.name ?? '';
      form.parent = d.parent ?? 0;
      form.status = Number(d.status) === 1 ? 1 : 0;
      for (const n of d.names ?? []) {
        form.names[String(n.language_id)] = n.name ?? '';
      }
    }
  } finally {
    loading.value = false;
  }
}

async function submit() {
  if (!form.name) {
    ElMessage.warning(tl('required_column'));
    return;
  }
  const names: Record<string, string> = {};
  for (const [k, v] of Object.entries(form.names)) {
    if (v && v.trim()) names[k] = v;
  }
  submitting.value = true;
  try {
    const res = await saveGameTypeApi({
      id: form.id,
      name: form.name,
      names,
      parent: form.parent,
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

onMounted(async () => {
  [parents.value, languages.value] = await Promise.all([
    getGameTypeManageListApi().then((v) => v ?? []),
    getLanguagesApi().then((v) => (v as any) ?? []),
  ]);
});

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('menu_game_type_management')"
    width="560"
  >
    <ElTabs v-loading="loading" v-model="activeLang" tab-position="left">
      <ElTabPane :label="tl('default')" name="default">
        <ElForm label-width="auto">
          <ElFormItem :label="tl('upper_category')">
            <ElSelect v-model="form.parent" class="w-full" filterable>
              <ElOption :label="tl('peak')" :value="0" />
              <ElOption
                v-for="p in parents"
                :key="p.id"
                :label="p.name"
                :value="p.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('type_name')" required>
            <ElInput v-model="form.name" />
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
        v-for="lang in languages"
        :key="lang.id"
        :label="lang.name"
        :name="`lang-${lang.id}`"
      >
        <ElForm label-width="auto">
          <ElFormItem :label="tl('type_name')">
            <ElInput v-model="form.names[String(lang.id)]" />
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
