<script lang="ts" setup>
import { computed, reactive, ref } from 'vue';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElInputNumber,
  ElMessage,
  ElOption,
  ElRadioButton,
  ElRadioGroup,
  ElSelect,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  getAdvertiseGameListApi,
  getAdvertiseInternalTypesApi,
  getGameTypeListApi,
  getGameViewApi,
  getGeneralAgentDropdownApi,
  getInformationViewApi,
  getLanguagesApi,
  getVendorListApi,
  saveInformationApi,
} from '#/api';
import ImageListDialog from '#/components/image-list-dialog.vue';
import { tl } from '#/locales';
import {
  IMAGE_ACCEPT,
  ImageUploadError,
  imageUploadText,
  readImageAsDataUrl,
} from '#/utils/image-upload';

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const editId = ref<number | string>('');
const isEdit = computed(() => Boolean(editId.value));
const activeStep = ref('step1');
const loading = ref(false);
const submitting = ref(false);

const languages = ref<Array<{ id: number | string; name: string }>>([]);
const agents = ref<Array<{ id: number | string; username: string }>>([]);
const gameTypes = ref<Array<{ id: number | string; name: string }>>([]);
const games = ref<
  Array<{ id: number | string; name: string; vendor_id: number | string }>
>([]);
const vendors = ref<Array<{ id: number; name: string }>>([]);
const internalTypes = ref<Array<{ parameter: string }>>([]);
let dropdownsLoaded = false;

const form = reactive<Record<string, any>>(makeDefaults());
function makeDefaults() {
  return {
    agent_id: '' as number | string,
    agents: [] as Array<number | string>,
    descriptions: {} as Record<string, string>,
    external_url: {} as Record<string, string>,
    game_id: '' as number | string,
    game_type_id: '' as number | string,
    internal_type: '' as number | string,
    language: [1] as Array<number | string>,
    open_window: 0,
    sort: 0,
    status: 1,
    type: 0 as number | string,
    vendor_id: '' as number | string,
  };
}

/** 每語系圖片；dirty 代表本次有重新選圖，未 dirty 的編輯不送圖以保留原圖 */
const images = reactive<
  Record<
    string,
    {
      image: string;
      image_dirty: boolean;
      image_id: string;
      image_pc: string;
      image_pc_dirty: boolean;
      image_pc_id: string;
    }
  >
>({});
function ensureImageSlot(lang: number | string) {
  if (!images[lang])
    images[lang] = {
      image: '',
      image_dirty: false,
      image_id: '',
      image_pc: '',
      image_pc_dirty: false,
      image_pc_id: '',
    };
  return images[lang];
}
function resetForm() {
  Object.assign(form, makeDefaults());
  for (const k of Object.keys(images)) delete images[k];
  gameVendorId.value = '';
  games.value = [];
  activeStep.value = 'step1';
}

/**
 * 連結至遊戲的選擇（target/tickets#4167）：先選遊戲商 → 遠端搜尋該商遊戲。
 * 與廣告編輯同根因：原本開彈窗即載入全站遊戲並一次渲染上萬個選項，凍結分頁。
 */
const gameVendorId = ref<number | string>('');
const gameSearching = ref(false);
async function remoteSearchGames(query: string) {
  if (!gameVendorId.value) return;
  gameSearching.value = true;
  try {
    games.value =
      (await getAdvertiseGameListApi({
        name: query || undefined,
        vendor_id: gameVendorId.value,
      }).catch(() => [])) ?? [];
  } finally {
    gameSearching.value = false;
  }
}
function onGameVendorChange() {
  form.game_id = '';
  games.value = [];
  remoteSearchGames('');
}
/** 編輯回填：以 game_id 反查名稱與所屬遊戲商，塞入下拉當前選項 */
async function seedSelectedGame(gameId: number | string) {
  if (!gameId) return;
  try {
    const res = await getGameViewApi(gameId);
    const g = (res as any)?.data;
    if (g?.id) {
      gameVendorId.value = g.vendor_id ?? '';
      games.value = [{ id: g.id, name: g.name, vendor_id: g.vendor_id }];
    }
  } catch {
    // 反查失敗僅影響名稱顯示，重新選擇即可
  }
}

// 圖庫挑選（共用組件，target 帶「slot:lang」）
const picker = ref<InstanceType<typeof ImageListDialog>>();
function openPicker(lang: number | string, slot: 'image' | 'image_pc') {
  picker.value?.open(`${slot}:${lang}`);
}
function onImageSelected(p: {
  id: number | string;
  target: string;
  url: string;
}) {
  const [slot, lang = ''] = p.target.split(':');
  const s = ensureImageSlot(lang);
  if (slot === 'image') {
    s.image = p.url;
    s.image_id = String(p.id);
    s.image_dirty = true;
  } else {
    s.image_pc = p.url;
    s.image_pc_id = String(p.id);
    s.image_pc_dirty = true;
  }
}

/** 上傳本地檔：base64 存進 image、image_id 留空，後端對 image_id=0 會自行上傳 */
function uploadFile(lang: number | string, slot: 'image' | 'image_pc') {
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
    const s = ensureImageSlot(lang);
    if (slot === 'image') {
      s.image = base64;
      s.image_id = '';
      s.image_dirty = true;
    } else {
      s.image_pc = base64;
      s.image_pc_id = '';
      s.image_pc_dirty = true;
    }
  });
  input.click();
}

async function loadDropdowns() {
  if (dropdownsLoaded) return;
  // 遊戲清單不在此載入（target/tickets#4167）：僅 type=2 用得到，改由選定遊戲商後遠端搜尋
  const [lg, gt, vd, it, ag] = await Promise.all([
    getLanguagesApi().catch(() => []),
    getGameTypeListApi().catch(() => []),
    getVendorListApi().catch(() => []),
    getAdvertiseInternalTypesApi().catch(() => []),
    getGeneralAgentDropdownApi().catch(() => []),
  ]);
  languages.value = (lg as any) ?? [];
  gameTypes.value = (gt as any) ?? [];
  vendors.value = (vd as any) ?? [];
  internalTypes.value = (it as any) ?? [];
  agents.value = (ag as any) ?? [];
  dropdownsLoaded = true;
}

async function loadEdit() {
  loading.value = true;
  try {
    const res = await getInformationViewApi(editId.value);
    if (Number(res.code) === 1000 && res.data) {
      const d = res.data;
      form.agent_id = d.agent_id ?? '';
      form.status = Number(d.status) === 1 ? 1 : 0;
      form.sort = Number(d.sort ?? 0);
      form.type = d.type ?? 0;
      form.open_window = Number(d.open_window) === 1 ? 1 : 0;
      form.language = (d.detail ?? []).map((x) => x.language) as Array<
        number | string
      >;
      try {
        form.descriptions = JSON.parse(d.description || '{}') ?? {};
      } catch {
        form.descriptions = {};
      }
      const t = Number(d.type);
      if (t === 1) form.game_type_id = d.content ?? '';
      if (t === 2) {
        form.game_id = d.content ?? '';
        // 回填已選遊戲（target/tickets#4167）：下拉不再載全站清單，需反查塞入當前選項
        void seedSelectedGame(d.content ?? '');
      }
      if (t === 3) form.vendor_id = d.content ?? '';
      if (t === 5) form.internal_type = d.content ?? '';
      if (t === 4) {
        try {
          form.external_url = JSON.parse(d.content ?? '{}');
        } catch {
          form.external_url = {};
        }
      }
      for (const dt of d.detail ?? []) {
        const lid = String(dt.language);
        images[lid] = {
          image: dt.image ?? '',
          image_dirty: false,
          image_id: '',
          image_pc: dt.image_pc ?? '',
          image_pc_dirty: false,
          image_pc_id: '',
        };
      }
    }
  } finally {
    loading.value = false;
  }
}

function buildPayload() {
  const payload: Record<string, any> = {
    descriptions: JSON.stringify(form.descriptions),
    language_id: form.language,
    sort: form.sort,
    status: form.status,
    type: form.type,
  };
  if (isEdit.value) {
    payload.id = editId.value;
    payload.agent_id = form.agent_id;
  } else {
    payload.agents = form.agents;
  }
  const t = Number(form.type);
  switch (t) {
    case 1: {
      payload.contents = form.game_type_id;
      break;
    }
    case 2: {
      payload.contents = form.game_id;
      break;
    }
    case 3: {
      payload.contents = form.vendor_id;
      break;
    }
    case 4: {
      payload.contents = form.external_url;
      payload.open_window = form.open_window;
      break;
    }
    case 5: {
      payload.contents = form.internal_type;
      break;
    }
    default: {
      payload.contents = '';
    }
  }
  // 只送本次有重新選/上傳的圖（dirty）；未動的編輯不送，後端保留原圖。
  // 空物件不可送，後端 image/image_pc 規則為 array|min:1，會擋下新增。
  const image: Record<string, string> = {};
  const imageId: Record<string, string> = {};
  const imagePc: Record<string, string> = {};
  const imagePcId: Record<string, string> = {};
  for (const lang of form.language) {
    const s = images[lang];
    if (s?.image_dirty && s.image) {
      image[lang] = s.image;
      imageId[lang] = s.image_id ?? '';
    }
    if (s?.image_pc_dirty && s.image_pc) {
      imagePc[lang] = s.image_pc;
      imagePcId[lang] = s.image_pc_id ?? '';
    }
  }
  if (Object.keys(image).length > 0) {
    payload.image = image;
    payload.image_id = imageId;
  }
  if (Object.keys(imagePc).length > 0) {
    payload.image_pc = imagePc;
    payload.image_pc_id = imagePcId;
  }
  return payload;
}

async function submit() {
  submitting.value = true;
  try {
    const res = await saveInformationApi(buildPayload(), isEdit.value);
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

const selectedLangs = computed(() =>
  languages.value.filter((l) => form.language.includes(l.id)),
);

async function open(id: number | string = '') {
  editId.value = id || '';
  resetForm();
  visible.value = true;
  loading.value = true;
  try {
    await loadDropdowns();
    if (isEdit.value) await loadEdit();
  } finally {
    loading.value = false;
  }
}
defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${isEdit ? tl('edit') : tl('add')} - ${tl('menu_information')}`"
    top="5vh"
    width="900"
  >
    <ElCard
      v-loading="loading"
      class="max-h-[70vh] overflow-y-auto"
      shadow="never"
    >
      <ElTabs v-model="activeStep">
        <!-- 1. 語言/代理 -->
        <ElTabPane :label="`1. ${tl('language')}`" name="step1">
          <ElForm label-position="top">
            <ElFormItem
              :label="`${tl('please_choose')}${tl('language')}${tl('default_english')}`"
            >
              <ElSelect v-model="form.language" class="w-full" multiple>
                <ElOption
                  v-for="l in languages"
                  :key="l.id"
                  :label="l.name"
                  :value="l.id"
                />
              </ElSelect>
            </ElFormItem>
            <ElFormItem v-if="!isEdit" :label="tl('agent_name')">
              <ElSelect
                v-model="form.agents"
                class="w-full"
                filterable
                multiple
              >
                <ElOption
                  v-for="a in agents"
                  :key="a.id"
                  :label="a.username"
                  :value="a.id"
                />
              </ElSelect>
            </ElFormItem>
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
              <ElFormItem :label="tl('status')">
                <ElRadioGroup v-model="form.status">
                  <ElRadioButton :value="1">{{ tl('enable') }}</ElRadioButton>
                  <ElRadioButton :value="0">{{ tl('disable') }}</ElRadioButton>
                </ElRadioGroup>
              </ElFormItem>
              <ElFormItem :label="tl('sorts')">
                <ElInputNumber v-model="form.sort" :min="0" />
              </ElFormItem>
            </div>
          </ElForm>
        </ElTabPane>

        <!-- 2. 超連結/圖片/描述 -->
        <ElTabPane
          :label="`2. ${tl('hyperlink')}/${tl('upload_image')}`"
          name="step2"
        >
          <ElForm label-position="top">
            <ElFormItem :label="tl('hyperlink')">
              <ElSelect v-model="form.type" class="w-full">
                <ElOption
                  :label="tl('advertisement_type_not_link')"
                  :value="0"
                />
                <ElOption :label="tl('game_type')" :value="1" />
                <ElOption :label="tl('advertisement_type_game')" :value="2" />
                <ElOption :label="tl('advertisement_type_vendor')" :value="3" />
                <ElOption
                  :label="tl('advertisement_type_external')"
                  :value="4"
                />
                <ElOption
                  :label="tl('advertisement_type_internal')"
                  :value="5"
                />
              </ElSelect>
            </ElFormItem>

            <ElFormItem v-if="Number(form.type) === 1" :label="tl('game_type')">
              <ElSelect v-model="form.game_type_id" class="w-full" filterable>
                <ElOption
                  v-for="g in gameTypes"
                  :key="g.id"
                  :label="g.name"
                  :value="g.id"
                />
              </ElSelect>
            </ElFormItem>
            <!-- 連結至遊戲改兩段式（target/tickets#4167）：先選商縮範圍，遊戲遠端搜尋不再載全站 -->
            <template v-else-if="Number(form.type) === 2">
              <ElFormItem :label="tl('label_vendor')">
                <ElSelect
                  v-model="gameVendorId"
                  class="w-full"
                  filterable
                  @change="onGameVendorChange"
                >
                  <ElOption
                    v-for="v in vendors"
                    :key="v.id"
                    :label="v.name"
                    :value="v.id"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('game')">
                <ElSelect
                  v-model="form.game_id"
                  class="w-full"
                  :disabled="!gameVendorId"
                  :loading="gameSearching"
                  :remote-method="remoteSearchGames"
                  filterable
                  remote
                >
                  <ElOption
                    v-for="g in games"
                    :key="g.id"
                    :label="g.name"
                    :value="g.id"
                  />
                </ElSelect>
              </ElFormItem>
            </template>
            <ElFormItem
              v-else-if="Number(form.type) === 3"
              :label="tl('label_vendor')"
            >
              <ElSelect v-model="form.vendor_id" class="w-full" filterable>
                <ElOption
                  v-for="v in vendors"
                  :key="v.id"
                  :label="v.name"
                  :value="v.id"
                />
              </ElSelect>
            </ElFormItem>
            <template v-else-if="Number(form.type) === 4">
              <ElFormItem
                v-for="l in selectedLangs"
                :key="l.id"
                :label="l.name"
              >
                <ElInput v-model="form.external_url[l.id]" />
              </ElFormItem>
              <ElFormItem>
                <ElRadioGroup v-model="form.open_window">
                  <ElRadioButton :value="1">
                    {{ tl('open_window') }}
                  </ElRadioButton>
                  <ElRadioButton :value="0">-</ElRadioButton>
                </ElRadioGroup>
              </ElFormItem>
            </template>
            <ElFormItem
              v-else-if="Number(form.type) === 5"
              :label="tl('hyperlink')"
            >
              <ElSelect v-model="form.internal_type" class="w-full">
                <ElOption
                  v-for="it in internalTypes"
                  :key="it.parameter"
                  :label="
                    tl(
                      `advertise_internal_${String(it.parameter).toLowerCase()}`,
                    )
                  "
                  :value="it.parameter"
                />
              </ElSelect>
            </ElFormItem>

            <!-- 每語系：描述 + 手機/PC 圖片 -->
            <div
              v-for="l in selectedLangs"
              :key="l.id"
              class="mb-3 border-t pt-3"
            >
              <div class="mb-2 font-medium">{{ l.name }}</div>
              <ElFormItem :label="tl('content')">
                <ElInput
                  v-model="form.descriptions[l.id]"
                  :rows="2"
                  type="textarea"
                />
              </ElFormItem>
              <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                  <div class="text-muted-foreground mb-1 text-xs">
                    {{ tl('choose_banner') }}
                  </div>
                  <img
                    v-if="ensureImageSlot(l.id).image"
                    :src="ensureImageSlot(l.id).image"
                    class="mb-1 max-h-40 max-w-full object-contain"
                  />
                  <div class="flex gap-2">
                    <ElButton size="small" @click="uploadFile(l.id, 'image')">
                      {{ tl('upload_image') }}
                    </ElButton>
                    <ElButton size="small" @click="openPicker(l.id, 'image')">
                      {{ tl('choose_file') }}
                    </ElButton>
                  </div>
                  <div class="text-muted-foreground mt-1 text-xs">
                    {{ imageUploadText('image_upload_hint') }}
                  </div>
                </div>
                <div>
                  <div class="text-muted-foreground mb-1 text-xs">
                    {{ tl('choose_icon') }}
                  </div>
                  <img
                    v-if="ensureImageSlot(l.id).image_pc"
                    :src="ensureImageSlot(l.id).image_pc"
                    class="mb-1 max-h-40 max-w-full object-contain"
                  />
                  <div class="flex gap-2">
                    <ElButton
                      size="small"
                      @click="uploadFile(l.id, 'image_pc')"
                    >
                      {{ tl('upload_image') }}
                    </ElButton>
                    <ElButton
                      size="small"
                      @click="openPicker(l.id, 'image_pc')"
                    >
                      {{ tl('choose_file') }}
                    </ElButton>
                  </div>
                  <div class="text-muted-foreground mt-1 text-xs">
                    {{ imageUploadText('image_upload_hint') }}
                  </div>
                </div>
              </div>
            </div>
          </ElForm>
        </ElTabPane>
      </ElTabs>
    </ElCard>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton
        v-if="activeStep === 'step1'"
        type="primary"
        @click="activeStep = 'step2'"
      >
        {{ tl('next') }}
      </ElButton>
      <ElButton :loading="submitting" type="success" @click="submit">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>

  <ImageListDialog ref="picker" @select="onImageSelected" />
</template>
