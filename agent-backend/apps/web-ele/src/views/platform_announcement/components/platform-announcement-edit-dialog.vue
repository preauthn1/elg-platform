<script lang="ts" setup>
import { computed, reactive, ref } from 'vue';

import { useUserStore } from '@vben/stores';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElDatePicker,
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
  getPlatformAnnouncementImagesApi,
  getPlatformAnnouncementViewApi,
  savePlatformAnnouncementApi,
  searchAgentDropdownApi,
  uploadBase64ImageApi,
} from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import {
  IMAGE_ACCEPT,
  ImageUploadError,
  imageUploadText,
  readImageAsDataUrl,
} from '#/utils/image-upload';

const emit = defineEmits<{ success: [] }>();

const userStore = useUserStore();

const visible = ref(false);
const paId = ref('');
const isEdit = computed(() => Boolean(paId.value));
const agentId = computed(
  () => (userStore.userInfo as any)?.host_id ?? (userStore.userInfo as any)?.id,
);

const activeStep = ref('step1');
const submitting = ref(false);

const languages = ref<Array<{ id: number | string; name: string }>>([]);
let languagesLoaded = false;

const form = reactive(makeDefaults());

function makeDefaults() {
  return {
    end_date: dayjs().add(1, 'month').format('YYYY-MM-DD 23:59:59'),
    internalPath: '',
    languageIds: [1] as Array<number | string>,
    openWindow: false,
    receiver_type: 2 as number | string,
    sort: 0 as number | string,
    start_date: dayjs().format('YYYY-MM-DD 00:00:00'),
    status: 1 as number | string,
    tag: 0 as number | string,
    type: 0 as number | string,
  };
}

const titles = reactive<Record<string, string>>({});
const popups = reactive<Record<string, string>>({});
const externals = reactive<Record<string, string>>({});
/** 各語系圖片：存檔只送 id；uploading / error 為逐張上傳狀態 */
const images = reactive<
  Record<
    string,
    { error?: string; id: number | string; src: string; uploading?: boolean }
  >
>({});

const imageUploading = computed(() =>
  Object.values(images).some((im) => im.uploading),
);

// 代理 include/exclude 遠端搜尋
const includeIds = ref<Array<number | string>>([]);
const excludeIds = ref<Array<number | string>>([]);
const includeOptions = ref<Array<{ id: number | string; username: string }>>(
  [],
);
const excludeOptions = ref<Array<{ id: number | string; username: string }>>(
  [],
);
const agentLoading = ref(false);

const selectedLangs = computed(() =>
  languages.value.filter((l) => form.languageIds.includes(l.id)),
);

async function searchAgent(q: string, which: 'exclude' | 'include') {
  if (!q) return;
  agentLoading.value = true;
  try {
    const res = await searchAgentDropdownApi(q);
    const list =
      Number(res.code) === 1000
        ? (res.data ?? []).map((a) => ({ id: a.id, username: a.username }))
        : [];
    if (which === 'include') includeOptions.value = list;
    else excludeOptions.value = list;
  } finally {
    agentLoading.value = false;
  }
}

/**
 * 選圖後立刻單獨上傳該語系的圖，成功才換上新圖與 id；
 * 失敗只在該語系顯示錯誤，保留原本的圖（存檔沿用舊 id）。 */
async function onImgFile(e: Event, langId: number | string) {
  const input = e.target as HTMLInputElement;
  const file = input.files?.[0];
  // 清空讓同一檔案可重新選擇
  input.value = '';
  if (!file) return;

  const key = String(langId);
  images[key] ??= { id: '', src: '' };
  // 經 reactive 取回 proxy，直接改 ??= 回傳的原始物件不會觸發畫面更新
  const img = images[key];
  img.error = '';

  img.uploading = true;
  try {
    const b64 = await readImageAsDataUrl(file);
    // type 2：寫入最新消息圖庫並回傳 platform_announcement_image_id
    const res = await uploadBase64ImageApi({
      agent_id: agentId.value,
      base64Image: b64,
      type: 2,
    });
    const newId = res.data?.platform_announcement_image_id;
    if (Number(res.code) === 1000 && newId) {
      img.id = newId;
      img.src = res.data?.path || b64;
    } else {
      img.error = res.message || tl('failure');
    }
  } catch (error: any) {
    if (error instanceof ImageUploadError) {
      ElMessage.error(error.message);
      return;
    }
    img.error = error?.message || tl('failure');
  } finally {
    img.uploading = false;
  }
}

// 圖庫挑選
const galleryVisible = ref(false);
const galleryImages = ref<Array<{ id: number | string; image_url: string }>>(
  [],
);
const galleryPage = ref(1);
const galleryTotal = ref(0);
const galleryLang = ref<number | string>('');
const GALLERY_PER = 6;

async function loadGallery(p: number) {
  galleryPage.value = p;
  const res = await getPlatformAnnouncementImagesApi({
    page: p,
    per_page: GALLERY_PER,
  });
  if (Number(res.code) === 1000) {
    galleryImages.value = res.data ?? [];
    galleryTotal.value = res.pagination?.total ?? 0;
  }
}

function openGallery(langId: number | string) {
  galleryLang.value = langId;
  loadGallery(1);
  galleryVisible.value = true;
}

function pickImage(img: { id: number | string; image_url: string }) {
  images[String(galleryLang.value)] = {
    error: '',
    id: img.id,
    src: img.image_url,
  };
  galleryVisible.value = false;
}

async function handleSave() {
  submitting.value = true;
  try {
    const langs = form.languageIds;
    const titlesOut: Record<string, string> = {};
    for (const id of langs) titlesOut[String(id)] = titles[String(id)] ?? '';

    let contents: Record<string, string> | string = '';
    if (Number(form.type) === 1) {
      contents = {};
      for (const id of langs) contents[String(id)] = popups[String(id)] ?? '';
    } else if (Number(form.type) === 2) {
      contents = {};
      for (const id of langs)
        contents[String(id)] = externals[String(id)] ?? '';
    } else if (Number(form.type) === 3) {
      contents = form.internalPath;
    }

    // 圖片已逐張上傳，存檔只送圖片編號（送圖片內容會超過 500KB 上限）
    const imageIdOut: Record<string, number | string> = {};
    for (const id of langs)
      imageIdOut[String(id)] = images[String(id)]?.id ?? '';

    const res = await savePlatformAnnouncementApi({
      ...(isEdit.value ? { id: paId.value } : {}),
      contents,
      end_time: form.end_date,
      exclude_agent: excludeIds.value,
      image_id: imageIdOut,
      include_agent: includeIds.value,
      language_id: langs,
      open_window: form.openWindow ? 1 : 0,
      receiver_type: form.receiver_type,
      sort: form.sort,
      start_time: form.start_date,
      status: form.status,
      tag: form.tag,
      titles: titlesOut,
      type: form.type,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    submitting.value = false;
  }
}

function resetForm() {
  Object.assign(form, makeDefaults());
  for (const m of [titles, popups, externals, images]) {
    for (const k of Object.keys(m)) delete (m as Record<string, unknown>)[k];
  }
  includeIds.value = [];
  excludeIds.value = [];
  includeOptions.value = [];
  excludeOptions.value = [];
  activeStep.value = 'step1';
}

async function open(id: number | string = '') {
  paId.value = id ? String(id) : '';
  resetForm();
  visible.value = true;
  if (!languagesLoaded) {
    languages.value = ((await getLanguagesApi().catch(() => [])) as any) ?? [];
    languagesLoaded = true;
  }
  if (!isEdit.value) return;

  const res = await getPlatformAnnouncementViewApi(paId.value);
  if (Number(res.code) !== 1000 || !res.data) {
    ElMessage.error(res.message || tl('failure'));
    visible.value = false;
    return;
  }
  const d = res.data;
  form.tag = d.tag ?? 0;
  form.type = Number(d.type ?? 0);
  form.status = Number(d.status) === 1 ? 1 : 0;
  form.sort = d.sort ?? 0;
  form.openWindow = Number(d.open_window) === 1;
  form.receiver_type = Number(d.receiver_type ?? 2);
  if (d.start_time) form.start_date = d.start_time;
  if (d.end_time) form.end_date = d.end_time;
  if (form.type === 3) form.internalPath = d.content ?? '';

  const detail = d.detail ?? {};
  form.languageIds = Object.keys(detail).map(Number);
  if (form.languageIds.length === 0) form.languageIds = [1];
  for (const [langId, v] of Object.entries(detail)) {
    titles[langId] = v.title ?? '';
    if (form.type === 1) popups[langId] = v.content ?? '';
    if (form.type === 2) externals[langId] = v.content ?? '';
    images[langId] = { id: v.image_id ?? '', src: v.image_url ?? '' };
  }

  includeOptions.value = (d.include_agent ?? []).map((a) => ({
    id: a.id,
    username: a.agent_username,
  }));
  includeIds.value = includeOptions.value.map((a) => a.id);
  excludeOptions.value = (d.exclude_agent ?? []).map((a) => ({
    id: a.id,
    username: a.agent_username,
  }));
  excludeIds.value = excludeOptions.value.map((a) => a.id);
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('menu_platform_announcement')} - ${isEdit ? tl('edit') : tl('add')}`"
    top="5vh"
    width="1000"
  >
    <div class="max-h-[74vh] overflow-y-auto pr-1">
      <ElTabs v-model="activeStep">
        <!-- Step 1: 語系/時間/代理 -->
        <ElTabPane
          :label="`1. ${tl('platform_announcement_choose_language_time')}/${tl('agent')}`"
          name="step1"
        >
          <ElForm label-position="top">
            <ElFormItem
              :label="`${tl('platform_announcement_choose_language')}${tl('default_english')}`"
            >
              <ElSelect
                v-model="form.languageIds"
                class="!w-full"
                filterable
                multiple
              >
                <ElOption
                  v-for="l in languages"
                  :key="l.id"
                  :label="l.name"
                  :value="l.id"
                />
              </ElSelect>
            </ElFormItem>
            <div class="grid grid-cols-1 gap-x-6 md:grid-cols-3">
              <ElFormItem :label="tl('agent_name')">
                <ElSelect
                  v-model="includeIds"
                  :debounce="500"
                  :loading="agentLoading"
                  :remote-method="(q: string) => searchAgent(q, 'include')"
                  class="!w-full"
                  filterable
                  multiple
                  remote
                >
                  <ElOption
                    v-for="a in includeOptions"
                    :key="a.id"
                    :label="a.username"
                    :value="a.id"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('exclude_agent')">
                <ElSelect
                  v-model="excludeIds"
                  :debounce="500"
                  :loading="agentLoading"
                  :remote-method="(q: string) => searchAgent(q, 'exclude')"
                  class="!w-full"
                  filterable
                  multiple
                  remote
                >
                  <ElOption
                    v-for="a in excludeOptions"
                    :key="a.id"
                    :label="a.username"
                    :value="a.id"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('type')">
                <ElSelect v-model="form.tag" class="!w-full">
                  <ElOption
                    v-for="n in [0, 1, 2, 3, 4, 5]"
                    :key="n"
                    :label="tl(`platform_announcement_tag_${n}`)"
                    :value="n"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('sendMessage_target')">
                <ElSelect v-model="form.receiver_type" class="!w-full">
                  <ElOption :label="tl('agent')" :value="2" />
                  <ElOption :label="tl('member')" :value="1" />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('label_start_time')">
                <ElDatePicker
                  v-model="form.start_date"
                  class="!w-full"
                  format="YYYY-MM-DD HH:mm:ss"
                  type="datetime"
                  value-format="YYYY-MM-DD HH:mm:ss"
                />
              </ElFormItem>
              <ElFormItem :label="tl('label_end_time')">
                <ElDatePicker
                  v-model="form.end_date"
                  class="!w-full"
                  format="YYYY-MM-DD HH:mm:ss"
                  type="datetime"
                  value-format="YYYY-MM-DD HH:mm:ss"
                />
              </ElFormItem>
              <ElFormItem :label="tl('status')">
                <ElRadioGroup v-model="form.status">
                  <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
                  <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
                </ElRadioGroup>
              </ElFormItem>
              <ElFormItem :label="tl('sorts')">
                <ElInput v-model="form.sort" />
              </ElFormItem>
            </div>
          </ElForm>
        </ElTabPane>

        <!-- Step 2: 內容/圖片 -->
        <ElTabPane
          :label="`2. ${tl('platform_announcement_choose_content_image')}`"
          name="step2"
        >
          <ElForm label-position="top">
            <ElFormItem>
              <ElCheckbox v-model="form.openWindow">
                {{ tl('open_window') }}
              </ElCheckbox>
            </ElFormItem>

            <ElCard class="mb-3" shadow="never">
              <template #header>
                {{ tl('platform_announcement_title') }}
              </template>
              <ElFormItem
                v-for="l in selectedLangs"
                :key="`t-${l.id}`"
                :label="
                  Number(l.id) === 1 ? `${tl('default')}: ${l.name}` : l.name
                "
              >
                <ElInput v-model="titles[String(l.id)]" />
              </ElFormItem>
            </ElCard>

            <ElFormItem :label="tl('content')">
              <ElSelect v-model="form.type" class="!w-full">
                <ElOption
                  :label="tl('platform_announcement_type_not_link')"
                  :value="0"
                />
                <ElOption
                  :label="tl('platform_announcement_type_pop_up')"
                  :value="1"
                />
                <ElOption
                  :label="tl('platform_announcement_type_external')"
                  :value="2"
                />
                <ElOption
                  :label="tl('platform_announcement_type_internal')"
                  :value="3"
                />
              </ElSelect>
            </ElFormItem>

            <!-- type 1 公告彈窗 -->
            <ElCard v-if="Number(form.type) === 1" class="mb-3" shadow="never">
              <ElFormItem
                v-for="l in selectedLangs"
                :key="`p-${l.id}`"
                :label="
                  Number(l.id) === 1 ? `${tl('default')}: ${l.name}` : l.name
                "
              >
                <ElInput
                  v-model="popups[String(l.id)]"
                  :rows="3"
                  type="textarea"
                />
              </ElFormItem>
            </ElCard>
            <!-- type 2 站外連結 -->
            <ElCard v-if="Number(form.type) === 2" class="mb-3" shadow="never">
              <ElFormItem
                v-for="l in selectedLangs"
                :key="`e-${l.id}`"
                :label="
                  Number(l.id) === 1 ? `${tl('default')}: ${l.name}` : l.name
                "
              >
                <ElInput v-model="externals[String(l.id)]" />
              </ElFormItem>
            </ElCard>
            <!-- type 3 站內路徑 -->
            <ElFormItem
              v-if="Number(form.type) === 3"
              :label="tl('platform_announcement_type_internal_placeholder')"
            >
              <ElInput v-model="form.internalPath" />
            </ElFormItem>

            <ElCard shadow="never">
              <template #header>{{ tl('choose_pa_picture') }}</template>
              <div
                v-for="l in selectedLangs"
                :key="`img-${l.id}`"
                class="mb-3 flex items-center gap-4 border-b pb-3"
              >
                <div class="w-32 shrink-0">
                  <b>
                    {{
                      Number(l.id) === 1
                        ? `${tl('default')}: ${l.name}`
                        : l.name
                    }}
                  </b>
                </div>
                <img
                  v-if="images[String(l.id)]?.src"
                  :src="images[String(l.id)]?.src"
                  style="max-width: 200px; max-height: 120px"
                />
                <div class="flex flex-col gap-1">
                  <div class="flex gap-2">
                    <label
                      :class="
                        images[String(l.id)]?.uploading
                          ? 'pointer-events-none opacity-60'
                          : 'cursor-pointer'
                      "
                    >
                      <span class="el-button el-button--small">
                        {{ tl('upload_image') }}
                      </span>
                      <input
                        :disabled="images[String(l.id)]?.uploading"
                        :accept="IMAGE_ACCEPT"
                        class="hidden"
                        type="file"
                        @change="(e) => onImgFile(e, l.id)"
                      />
                    </label>
                    <ElButton
                      :disabled="images[String(l.id)]?.uploading"
                      size="small"
                      @click="openGallery(l.id)"
                    >
                      {{ tl('choose_file') }}
                    </ElButton>
                  </div>
                  <span class="text-xs text-gray-500">
                    {{
                      images[String(l.id)]?.uploading
                        ? tl('image_uploading_wait')
                        : imageUploadText('image_upload_hint')
                    }}
                  </span>
                  <span
                    v-if="images[String(l.id)]?.error"
                    class="text-xs text-red-500"
                  >
                    {{ images[String(l.id)]?.error }}
                  </span>
                </div>
              </div>
            </ElCard>
          </ElForm>
        </ElTabPane>
      </ElTabs>
    </div>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton v-if="activeStep === 'step2'" @click="activeStep = 'step1'">
        {{ tl('previous') }}
      </ElButton>
      <ElButton
        v-if="activeStep === 'step1'"
        type="primary"
        @click="activeStep = 'step2'"
      >
        {{ tl('next') }}
      </ElButton>
      <ElButton
        v-if="activeStep === 'step2'"
        :disabled="imageUploading"
        :loading="submitting"
        type="success"
        @click="handleSave"
      >
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>

  <ElDialog v-model="galleryVisible" :title="tl('upload_image')" width="700">
    <div class="grid grid-cols-3 gap-3">
      <img
        v-for="img in galleryImages"
        :key="img.id"
        :src="img.image_url"
        class="cursor-pointer rounded border hover:opacity-80"
        style="height: 120px; object-fit: contain"
        @click="pickImage(img)"
      />
    </div>
    <TablePagination
      :current-page="galleryPage"
      :page-size="GALLERY_PER"
      :total="galleryTotal"
      layout="prev, pager, next"
      @current-change="loadGallery"
    />
  </ElDialog>
</template>
