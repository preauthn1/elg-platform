<script lang="ts" setup>
import { ref } from 'vue';

import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElImage,
  ElInput,
  ElInputNumber,
  ElMessage,
  ElOption,
  ElSelect,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  getEventV2DetailApi,
  getEventViewApi,
  getLanguagesApi,
  saveEventTitleApi,
  updateEventV2InfoApi,
  uploadAdvertiseImageApi,
} from '#/api';
import ImageListDialog from '#/components/image-list-dialog.vue';
import { tl } from '#/locales';
import {
  IMAGE_ACCEPT,
  ImageUploadError,
  imageUploadText,
  readImageAsDataUrl,
} from '#/utils/image-upload';

defineOptions({ name: 'EventImageTextEditDialog' });

const emit = defineEmits<{ saved: [] }>();

interface LangRow {
  content: string;
  id: number;
  mobileId: number | string;
  mobileUrl: string;
  name: string;
  pcId: number | string;
  pcUrl: string;
  title: string;
}

const userStore = useUserStore();
const hostId = Number((userStore.userInfo as any)?.host_id ?? 0);

const visible = ref(false);
const loading = ref(false);
const saving = ref(false);

const source = ref<'v1' | 'v2'>('v1');
const eventId = ref<number | string>('');
const showPcImage = ref(false);
const activeStep = ref('1');
const contentTab = ref('default');

const sort = ref<number>(0);
const defaultTitle = ref('');
const defaultContent = ref('');
const langRows = ref<LangRow[]>([]);
const imageLanguages = ref<number[]>([]);

// 圖庫挑選（共用組件，回填目標留在本地 pickerTarget）
const picker = ref<InstanceType<typeof ImageListDialog>>();
const pickerTarget = ref<null | { kind: 'mobile' | 'pc'; row: LangRow }>(null);

function rowById(id: number | string) {
  return langRows.value.find((r) => r.id === Number(id));
}

async function open(id: number | string, src: 'v1' | 'v2') {
  visible.value = true;
  loading.value = true;
  source.value = src;
  eventId.value = id;
  showPcImage.value = false;
  activeStep.value = '1';
  sort.value = 0;
  defaultTitle.value = '';
  defaultContent.value = '';
  imageLanguages.value = [];
  contentTab.value = src === 'v2' ? '' : 'default';
  try {
    const languages = (await getLanguagesApi()) ?? [];
    langRows.value = languages.map((l) => ({
      content: '',
      id: l.id,
      mobileId: 0,
      mobileUrl: '',
      name: l.name,
      pcId: 0,
      pcUrl: '',
      title: '',
    }));
    if (contentTab.value === '' && langRows.value[0]) {
      contentTab.value = String(langRows.value[0].id);
    }

    if (src === 'v2') {
      const res = await getEventV2DetailApi(id);
      if (Number(res.code) !== 200) {
        ElMessage.error(res.message);
        visible.value = false;
        return;
      }
      const d = res.data ?? {};
      sort.value = Number(d.sort ?? 0);
      for (const dl of d.detail_lang ?? []) {
        const row = rowById(dl.language_id);
        if (!row) continue;
        row.title = dl.name ?? '';
        row.content = dl.content ?? '';
        if (dl.image) {
          row.mobileUrl = dl.image;
          if (!imageLanguages.value.includes(row.id)) {
            imageLanguages.value.push(row.id);
          }
        }
      }
    } else {
      const res = await getEventViewApi(id);
      if (Number(res.code) !== 1000) {
        ElMessage.error(res.message);
        visible.value = false;
        return;
      }
      const ev = res.data ?? {};
      showPcImage.value = Number(ev.type_id) !== 3;
      sort.value = Number(ev.sort ?? 0);
      defaultTitle.value = ev.title ?? '';
      defaultContent.value = ev.content ?? '';
      for (const lang of ev.languages ?? []) {
        const row = rowById(lang.language_id);
        if (!row) continue;
        row.title = lang.title ?? '';
        row.content = lang.content ?? '';
      }
      const imgs = (ev.image?.length ? ev.image : ev.banner) ?? [];
      for (const img of imgs) {
        const row = rowById(img.language_id);
        if (!row) continue;
        row.mobileUrl = img.image ?? '';
        if (img.image_pc) row.pcUrl = img.image_pc;
        if (!imageLanguages.value.includes(row.id)) {
          imageLanguages.value.push(row.id);
        }
      }
    }
    if (imageLanguages.value.length === 0) imageLanguages.value = [1];
  } finally {
    loading.value = false;
  }
}

async function onPickFile(e: Event, kind: 'mobile' | 'pc', row: LangRow) {
  const file = (e.target as HTMLInputElement).files?.[0];
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
  if (kind === 'mobile') row.mobileUrl = base64;
  else row.pcUrl = base64;
  const res = await uploadAdvertiseImageApi({
    agent_id: hostId,
    base64Image: base64,
  });
  if (Number(res.code) === 1000) {
    const id = res.data?.advertise_image_id ?? 0;
    // V2 用回傳 path，V1 沿用 base64
    const url = source.value === 'v2' ? (res.data?.path ?? base64) : base64;
    if (kind === 'mobile') {
      row.mobileId = id;
      row.mobileUrl = url;
    } else {
      row.pcId = id;
      row.pcUrl = url;
    }
  } else {
    ElMessage.error(res.message);
  }
}

function openPicker(kind: 'mobile' | 'pc', row: LangRow) {
  pickerTarget.value = { kind, row };
  picker.value?.open();
}

function onImageSelected(p: {
  id: number | string;
  target: string;
  url: string;
}) {
  const t = pickerTarget.value;
  if (!t) return;
  if (t.kind === 'mobile') {
    t.row.mobileId = Number(p.id);
    t.row.mobileUrl = p.url;
  } else {
    t.row.pcId = Number(p.id);
    t.row.pcUrl = p.url;
  }
}

async function handleSave() {
  saving.value = true;
  try {
    let res;
    if (source.value === 'v2') {
      const detailLang: any[] = [];
      for (const row of langRows.value) {
        if (row.title || row.content || row.mobileUrl) {
          detailLang.push({
            content: row.content,
            image: row.mobileUrl,
            language_id: row.id,
            name: row.title,
          });
        }
      }
      res = await updateEventV2InfoApi({
        detail_lang: detailLang,
        event_detail_id: eventId.value,
        sort: sort.value,
      });
    } else {
      const langs: any[] = [];
      const image: Record<number, string> = {};
      const imageId: Record<number, number | string> = {};
      const imagePc: Record<number, string> = {};
      const imagePcId: Record<number, number | string> = {};
      for (const row of langRows.value) {
        if (row.title !== '') {
          langs.push({
            content: row.content,
            language_id: row.id,
            title: row.title,
          });
        }
      }
      for (const lid of imageLanguages.value) {
        const row = rowById(lid);
        if (!row) continue;
        if (row.mobileUrl) image[lid] = row.mobileUrl;
        imageId[lid] = row.mobileId;
        if (row.pcUrl) imagePc[lid] = row.pcUrl;
        imagePcId[lid] = row.pcId;
      }
      const payload: Record<string, any> = {
        agent_id: hostId,
        content: defaultContent.value === '' ? ' ' : defaultContent.value,
        id: eventId.value,
        image,
        image_id: imageId,
        image_language: imageLanguages.value,
        image_pc: imagePc,
        image_pc_id: imagePcId,
        sort: sort.value,
        title: defaultTitle.value,
      };
      if (langs.length > 0) payload.languages = langs;
      res = await saveEventTitleApi(payload);
    }
    if (Number(res.code) === 1000 || Number(res.code) === 200) {
      ElMessage.success(res.message);
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
    :title="`${tl('menu_event_image_text')} > ${tl('edit')}`"
    align-center
    width="900"
  >
    <div v-loading="loading">
      <ElTabs v-model="activeStep" type="border-card">
        <!-- 第一步：名稱和內容 -->
        <ElTabPane :label="tl('event_and_content')" name="1">
          <ElForm label-width="auto">
            <ElFormItem :label="tl('sorts')">
              <ElInputNumber
                v-model="sort"
                :min="0"
                controls-position="right"
              />
            </ElFormItem>
          </ElForm>
          <ElTabs v-model="contentTab">
            <ElTabPane
              v-if="source !== 'v2'"
              :label="tl('default')"
              name="default"
            >
              <ElFormItem :label="tl('event_name')" required>
                <ElInput v-model="defaultTitle" />
              </ElFormItem>
              <ElFormItem :label="tl('event_content')">
                <ElInput v-model="defaultContent" :rows="5" type="textarea" />
              </ElFormItem>
            </ElTabPane>
            <ElTabPane
              v-for="row in langRows"
              :key="row.id"
              :label="row.name"
              :name="String(row.id)"
            >
              <ElFormItem :label="tl('event_name')">
                <ElInput v-model="row.title" />
              </ElFormItem>
              <ElFormItem :label="tl('event_content')">
                <ElInput v-model="row.content" :rows="5" type="textarea" />
              </ElFormItem>
            </ElTabPane>
          </ElTabs>
        </ElTabPane>

        <!-- 第二步：圖片 -->
        <ElTabPane :label="tl('add_image')" name="2">
          <ElFormItem
            :label="`${tl('choose_language')}${tl('default_english')}`"
            label-width="auto"
          >
            <ElSelect v-model="imageLanguages" class="w-full" multiple>
              <ElOption
                v-for="row in langRows"
                :key="row.id"
                :label="row.name"
                :value="row.id"
              />
            </ElSelect>
          </ElFormItem>

          <div
            v-for="row in langRows"
            v-show="imageLanguages.includes(row.id)"
            :key="row.id"
            class="mb-4 border-t pt-3"
          >
            <div class="mb-2 text-center font-bold">{{ row.name }}</div>
            <div
              class="grid grid-cols-1 gap-4"
              :class="showPcImage ? 'md:grid-cols-2' : ''"
            >
              <div class="text-center">
                <div class="mb-1 font-medium">
                  {{
                    showPcImage ? tl('choose_mobile_ad_picture') : tl('image')
                  }}
                </div>
                <ElImage
                  v-if="row.mobileUrl"
                  :src="row.mobileUrl"
                  class="mx-auto max-h-[200px]"
                  fit="contain"
                />
                <div class="mt-2 flex justify-center gap-2">
                  <label class="cursor-pointer">
                    <span class="el-button el-button--small">
                      {{ tl('upload_image') }}
                    </span>
                    <input
                      :accept="IMAGE_ACCEPT"
                      class="hidden"
                      type="file"
                      @change="(e) => onPickFile(e, 'mobile', row)"
                    />
                  </label>
                  <ElButton size="small" @click="openPicker('mobile', row)">
                    {{ tl('choose_file') }}
                  </ElButton>
                </div>
                <div class="text-muted-foreground mt-1 text-xs">
                  {{ imageUploadText('image_upload_hint') }}
                </div>
              </div>
              <div v-if="showPcImage" class="text-center">
                <div class="mb-1 font-medium">
                  {{ tl('choose_pc_ad_picture') }}
                </div>
                <ElImage
                  v-if="row.pcUrl"
                  :src="row.pcUrl"
                  class="mx-auto max-h-[200px]"
                  fit="contain"
                />
                <div class="mt-2 flex justify-center gap-2">
                  <label class="cursor-pointer">
                    <span class="el-button el-button--small">
                      {{ tl('upload_image') }}
                    </span>
                    <input
                      :accept="IMAGE_ACCEPT"
                      class="hidden"
                      type="file"
                      @change="(e) => onPickFile(e, 'pc', row)"
                    />
                  </label>
                  <ElButton size="small" @click="openPicker('pc', row)">
                    {{ tl('choose_file') }}
                  </ElButton>
                </div>
                <div class="text-muted-foreground mt-1 text-xs">
                  {{ imageUploadText('image_upload_hint') }}
                </div>
              </div>
            </div>
          </div>
        </ElTabPane>
      </ElTabs>
    </div>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>

    <ImageListDialog ref="picker" @select="onImageSelected" />
  </ElDialog>
</template>
