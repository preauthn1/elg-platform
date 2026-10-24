<script lang="ts" setup>
import { reactive, ref } from 'vue';

import {
  ElButton,
  ElCheckbox,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  getLanguagesApi,
  getVipViewApi,
  saveVipContentApi,
  uploadVipImageApi,
} from '#/api';
import { requestClient } from '#/api/request';
import { tl } from '#/locales';
import {
  IMAGE_ACCEPT,
  ImageUploadError,
  imageUploadText,
  readImageAsDataUrl,
} from '#/utils/image-upload';

defineOptions({ name: 'VipEditDialog' });

const emit = defineEmits<{ saved: [] }>();

interface ImageState {
  base64: string;
  imageId: number | string;
  preview: string;
}

const visible = ref(false);
const loading = ref(false);
const saving = ref(false);
const editId = ref<null | number | string>(null);
const activeTab = ref('name');

const languages = ref<Array<{ code?: string; id: number; name: string }>>([]);
const hostId = ref<number | string>('');

const form = reactive({
  exclusive_service: false,
  image_language: [] as number[],
  is_downgrade: '0' as number | string,
  keep_amount: '',
  level: '',
  name: '',
  names: {} as Record<number, string>,
  target_amount: '',
  vip_channel: false,
  withdrawal_amount_limit: '0',
  withdrawal_times_limit: '',
  /** 隱藏欄位，編輯時保留既有值 */
  withdrawal_types: [] as number[],
});

/** 各語系圖片狀態 */
const images = reactive<Record<number, ImageState>>({});

function ensureImage(langId: number) {
  if (!images[langId]) {
    images[langId] = { base64: '', imageId: 0, preview: '' };
  }
  return images[langId];
}

async function loadHostId() {
  if (hostId.value) return;
  const me = await requestClient
    .get<Record<string, any>>('/me')
    .catch(() => null);
  hostId.value = me?.host_id ?? me?.id ?? '';
}

function resetForm() {
  form.name = '';
  form.names = {};
  form.level = '';
  form.target_amount = '';
  form.keep_amount = '';
  form.withdrawal_times_limit = '';
  form.withdrawal_amount_limit = '0';
  form.is_downgrade = '0';
  form.vip_channel = false;
  form.exclusive_service = false;
  form.withdrawal_types = [];
  form.image_language = [1];
  for (const k of Object.keys(images)) delete images[Number(k)];
  activeTab.value = 'name';
}

async function open(id?: number | string) {
  visible.value = true;
  editId.value = id ?? null;
  resetForm();

  loading.value = true;
  try {
    await Promise.all([
      languages.value.length === 0
        ? getLanguagesApi()
            .then((d) => (languages.value = d ?? []))
            .catch(() => (languages.value = []))
        : Promise.resolve(),
      loadHostId(),
    ]);

    if (id) {
      const res = await getVipViewApi(id);
      if (Number(res.code) === 1000 && res.data) {
        const d = res.data;
        form.name = d.name ?? '';
        form.level = String(d.level ?? '');
        form.target_amount = String(d.target_amount ?? '');
        form.keep_amount = String(d.keep_amount ?? '');
        form.withdrawal_times_limit = String(d.withdrawal_times_limit ?? '');
        form.is_downgrade = Number(d.is_downgrade) === 1 ? '1' : '0';
        form.vip_channel = Number(d.vip_channel) === 1;
        form.exclusive_service = Number(d.exclusive_service) === 1;
        form.withdrawal_types = (d.withdrawal_types ?? []) as number[];

        const withImage: number[] = [];
        for (const row of d.vip_names ?? []) {
          if (row.name) form.names[row.language_id] = row.name;
          if (row.image) {
            const im = ensureImage(row.language_id);
            im.preview = row.image;
            im.imageId = 0;
            withImage.push(row.language_id);
          }
        }
        if (d.image && !withImage.includes(1)) {
          ensureImage(1).preview = d.image;
          withImage.push(1);
        }
        form.image_language = withImage.length > 0 ? withImage : [1];
      }
    }
  } finally {
    loading.value = false;
  }
}

function pickFile(langId: number) {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = IMAGE_ACCEPT;
  input.addEventListener('change', async () => {
    const file = input.files?.[0];
    if (!file) return;
    let base64: string;
    try {
      base64 = await readImageAsDataUrl(file);
    } catch (error) {
      if (error instanceof ImageUploadError) {
        ElMessage.error(error.message);
        return;
      }
      throw error;
    }
    const im = ensureImage(langId);
    im.preview = base64;
    im.base64 = base64;
    const res = await uploadVipImageApi({
      agent_id: hostId.value,
      base64Image: base64,
      type: 1,
    });
    if (Number(res.code) === 1000) {
      im.imageId = res.data?.advertise_image_id ?? 0;
    } else {
      ElMessage.error(
        Number(res.code) === 2002 ? String(res.data) : res.message,
      );
    }
  });
  input.click();
}

async function handleSave() {
  saving.value = true;
  try {
    const nameLanguage: Array<{ language_id: number; name: string }> = [];
    for (const lang of languages.value) {
      const v = form.names[lang.id];
      if (v) nameLanguage.push({ language_id: lang.id, name: v });
    }

    const data: Record<string, any> = {
      exclusive_service: form.exclusive_service ? 1 : 0,
      image_language: form.image_language,
      is_downgrade: form.is_downgrade,
      keep_amount: form.keep_amount,
      level: form.level,
      name: form.name,
      preset_image: ensureImage(1).imageId,
      target_amount: form.target_amount,
      tax: 0,
      vip_channel: form.vip_channel ? 1 : 0,
      withdrawal_amount_limit: form.withdrawal_amount_limit,
      withdrawal_times_limit: form.withdrawal_times_limit,
      withdrawal_type: form.withdrawal_types,
    };
    if (nameLanguage.length > 0) data.name_language = nameLanguage;

    const image: Record<number, string> = {};
    const imageId: Record<number, number | string> = {};
    for (const langId of form.image_language) {
      const im = ensureImage(langId);
      if (im.base64) image[langId] = im.base64;
      imageId[langId] = im.imageId;
    }
    data.image = image;
    data.image_id = imageId;

    if (editId.value) data.id = editId.value;

    const res = await saveVipContentApi(data, Boolean(editId.value));
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('saved');
    } else {
      ElMessage.error(
        Number(res.code) === 2002 ? String(res.data) : res.message,
      );
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
    :title="`${tl('menu_vip_setting')} > ${editId ? tl('edit') : tl('add')}`"
    width="820"
    align-center
  >
    <div class="max-h-[74vh] overflow-y-auto pr-1">
      <ElForm v-loading="loading" :model="form" label-position="top">
        <ElTabs v-model="activeTab">
          <!-- 步驟 1：VIP 名稱 -->
          <ElTabPane :label="`VIP${tl('name')}`" name="name">
            <ElFormItem :label="`VIP${tl('name')}`" required>
              <ElInput v-model="form.name" />
            </ElFormItem>
            <ElFormItem
              v-for="lang in languages"
              :key="lang.id"
              :label="`${lang.name} - VIP${tl('name')}`"
            >
              <ElInput v-model="form.names[lang.id]" />
            </ElFormItem>
          </ElTabPane>

          <!-- 步驟 2：其他 -->
          <ElTabPane :label="tl('other')" name="other">
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
              <ElFormItem :label="tl('sorts')" required>
                <div class="w-full">
                  <ElInput v-model="form.level" />
                  <span class="text-xs text-red-500">
                    {{ tl('vip_setting_text') }}
                  </span>
                </div>
              </ElFormItem>
              <ElFormItem :label="`VIP${tl('need_deposit_amount')}`">
                <ElInput v-model="form.target_amount" />
              </ElFormItem>
              <ElFormItem :label="`VIP${tl('keep_amount')}`">
                <ElInput v-model="form.keep_amount" />
              </ElFormItem>
              <ElFormItem :label="`VIP${tl('withdrawal_times_limit')}`">
                <ElInput v-model="form.withdrawal_times_limit" />
              </ElFormItem>
              <ElFormItem :label="tl('daily_withdrawal_amount_limit')">
                <div class="w-full">
                  <ElInput v-model="form.withdrawal_amount_limit" />
                  <span class="text-xs text-red-500">
                    {{ tl('unlimited_value').replace('{value}', '-1') }}
                  </span>
                </div>
              </ElFormItem>
              <ElFormItem :label="tl('is_downgrade')">
                <ElRadioGroup v-model="form.is_downgrade">
                  <ElRadio value="1">{{ tl('option_yes') }}</ElRadio>
                  <ElRadio value="0">{{ tl('option_no') }}</ElRadio>
                </ElRadioGroup>
              </ElFormItem>
            </div>
            <ElFormItem>
              <ElCheckbox v-model="form.vip_channel">
                {{ tl('show_vip_text') }}
              </ElCheckbox>
            </ElFormItem>
            <ElFormItem>
              <ElCheckbox v-model="form.exclusive_service">
                {{ tl('show_vip_exclusive_service') }}
              </ElCheckbox>
            </ElFormItem>
          </ElTabPane>

          <!-- 步驟 3：上傳圖片 -->
          <ElTabPane :label="tl('upload_image')" name="image">
            <ElFormItem
              :label="`${tl('choose_vip_image_language')}${tl('default_english')}`"
            >
              <ElSelect v-model="form.image_language" class="w-full" multiple>
                <ElOption
                  v-for="lang in languages"
                  :key="lang.id"
                  :label="lang.name"
                  :value="lang.id"
                />
              </ElSelect>
            </ElFormItem>
            <div class="text-center font-bold">{{ tl('picture') }}</div>
            <div
              v-for="langId in form.image_language"
              :key="langId"
              class="flex items-center gap-4 border-t py-3"
            >
              <div class="w-32 text-center font-bold">
                {{ languages.find((l) => l.id === langId)?.name ?? langId }}
              </div>
              <div class="flex-1 text-center">
                <img
                  v-if="images[langId]?.preview"
                  :src="images[langId]?.preview"
                  class="mx-auto max-h-[200px] max-w-[200px]"
                />
                <div>
                  <ElButton size="small" @click="pickFile(langId)">
                    {{ tl('upload_image') }}
                  </ElButton>
                </div>
                <div class="text-muted-foreground mt-1 text-xs">
                  {{ imageUploadText('image_upload_hint') }}
                </div>
              </div>
            </div>
          </ElTabPane>
        </ElTabs>
      </ElForm>
    </div>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
