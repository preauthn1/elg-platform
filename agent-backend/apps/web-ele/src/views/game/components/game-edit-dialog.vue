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
  ElRadio,
  ElRadioGroup,
  ElSelect,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  getGameTagDropdownApi,
  getGameTypeManageListApi,
  getGameViewApi,
  getLanguagesApi,
  getVendorDropdownByTypeApi,
  saveGameApi,
} from '#/api';
import { tl } from '#/locales';
import {
  IMAGE_ACCEPT,
  ImageUploadError,
  imageUploadText,
  readImageAsDataUrl,
} from '#/utils/image-upload';

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const gameId = ref<number | string>('');
const isEdit = computed(() => Boolean(gameId.value));

const languages = ref<Array<{ id: number | string; name: string }>>([]);
const vendors = ref<Array<{ id: number; name: string }>>([]);
const typeOptions = ref<Array<{ id: number | string; label: string }>>([]);
const tagOptions = ref<Array<{ id: number; tag_name: string }>>([]);
const vendorName = ref('');
const submitting = ref(false);
const activeLang = ref('default');
const imagePreview = ref('');
const imageInput = ref<HTMLInputElement>();
let dropdownsLoaded = false;
/** 開窗序號：快速切換編輯不同筆時，只採用最後一次 open() 的回應 */
let openSeq = 0;

const form = reactive(makeDefaults());

function makeDefaults() {
  return {
    game_type: '',
    image: '',
    memo: '',
    name: '',
    names: {} as Record<string, string>,
    remark: '',
    rtp: 0,
    sort: 0 as number | string,
    status: 1 as number | string,
    tags: [] as Array<number | string>,
    type_id: '' as number | string,
    vendor_id: '' as number | string,
  };
}

function buildTypeOptions(
  rows: Array<{ id: number | string; name?: string; parent?: number | string }>,
) {
  const nameById = new Map<string, string>();
  return rows.map((r) => {
    const base = r.name ?? '';
    const label =
      Number(r.parent) === 0
        ? base
        : `${nameById.get(String(r.parent)) ?? ''}>${base}`;
    nameById.set(String(r.id), label);
    return { id: r.id, label };
  });
}

async function onImageChange(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0];
  if (!file) return;
  let result: string;
  try {
    result = await readImageAsDataUrl(file);
  } catch (error) {
    if (error instanceof ImageUploadError) {
      ElMessage.error(error.message);
      return;
    }
    throw error;
  }
  form.image = result;
  imagePreview.value = result;
}

async function handleSave() {
  if (!form.name.trim()) {
    ElMessage.warning(tl('game_name'));
    return;
  }
  submitting.value = true;
  try {
    const names = Object.entries(form.names)
      .filter(([, v]) => v && v.trim())
      .map(([language_id, name]) => ({ language_id, name }));

    const payload: Record<string, any> = {
      ...(isEdit.value ? { id: gameId.value } : {}),
      game_type: form.game_type,
      memo: form.memo,
      name: form.name,
      remark: form.remark,
      rtp: form.rtp,
      sort: form.sort,
      status: form.status,
      tags: form.tags,
      type_id: form.type_id,
      vendor_id: form.vendor_id,
    };
    if (names.length > 0) payload.names = names;
    if (form.image) payload.image = form.image;

    const res = await saveGameApi(payload);
    if (Number(res.code) !== 1000) {
      ElMessage.error(res.message || tl('failure'));
      return;
    }

    ElMessage.success(tl('success'));
    visible.value = false;
    emit('success');
  } finally {
    submitting.value = false;
  }
}

async function loadDropdowns() {
  if (dropdownsLoaded) return;
  const [langs, vendorList, typeRows, tags] = await Promise.all([
    getLanguagesApi().catch(() => []),
    getVendorDropdownByTypeApi(1).catch(() => []),
    getGameTypeManageListApi().catch(() => []),
    getGameTagDropdownApi().catch(() => []),
  ]);
  languages.value = (langs as any) ?? [];
  vendors.value = (vendorList as any) ?? [];
  typeOptions.value = buildTypeOptions((typeRows as any) ?? []);
  tagOptions.value = (tags as any) ?? [];
  dropdownsLoaded = true;
}

async function open(id: number | string = '') {
  const seq = ++openSeq;
  gameId.value = id || '';
  Object.assign(form, makeDefaults());
  vendorName.value = '';
  imagePreview.value = '';
  activeLang.value = 'default';
  visible.value = true;
  await loadDropdowns();
  if (seq !== openSeq || !isEdit.value) return;

  const res = await getGameViewApi(gameId.value);
  if (seq !== openSeq) return;
  if (Number(res.code) !== 1000 || !res.data) {
    ElMessage.error(res.message || tl('failure'));
    visible.value = false;
    return;
  }
  const g = res.data as any;
  form.vendor_id = g.vendor_id ?? '';
  form.type_id = g.type_id ?? '';
  form.name = g.name ?? '';
  form.game_type = g.game_type ?? '';
  form.remark = g.remark ?? '';
  form.sort = g.sort ?? 0;
  form.memo = g.memo ?? '';
  form.status = Number(g.status) === 1 ? 1 : 0;
  form.rtp = Number(g.rtp ?? 0);
  form.image = '';
  imagePreview.value = g.image ?? '';
  form.tags = (g.tags ?? []).map(Number);
  for (const n of g.names ?? []) {
    form.names[String(n.language_id)] = n.name ?? '';
  }
  vendorName.value =
    vendors.value.find((v) => Number(v.id) === Number(g.vendor_id))?.name ?? '';
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('menu_game_management')} - ${isEdit ? tl('edit') : tl('add')}`"
    top="5vh"
    width="1000"
  >
    <div class="max-h-[74vh] overflow-y-auto pr-1">
      <ElCard class="mb-3" shadow="never">
        <ElForm label-position="top">
          <div class="grid grid-cols-1 gap-x-6 md:grid-cols-2">
            <ElFormItem :label="tl('label_vendor')">
              <p v-if="isEdit" class="text-green-600">{{ vendorName }}</p>
              <ElSelect
                v-else
                v-model="form.vendor_id"
                class="!w-full"
                filterable
              >
                <ElOption
                  v-for="v in vendors"
                  :key="v.id"
                  :label="v.name"
                  :value="v.id"
                />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="tl('game_type')">
              <ElSelect v-model="form.type_id" class="!w-full" filterable>
                <ElOption
                  v-for="t in typeOptions"
                  :key="t.id"
                  :label="t.label"
                  :value="t.id"
                />
              </ElSelect>
            </ElFormItem>
          </div>
        </ElForm>
      </ElCard>

      <ElCard class="mb-3" shadow="never">
        <template #header>
          <span class="font-medium">{{ tl('game_name') }}</span>
        </template>
        <ElTabs v-model="activeLang">
          <ElTabPane :label="tl('default')" name="default">
            <ElForm label-position="top">
              <ElFormItem>
                <template #label>
                  <span class="text-red-500">*</span> {{ tl('game_name') }}
                </template>
                <ElInput v-model="form.name" />
              </ElFormItem>
            </ElForm>
          </ElTabPane>
          <ElTabPane
            v-for="lang in languages"
            :key="lang.id"
            :label="lang.name"
            :name="`lang-${lang.id}`"
          >
            <ElForm label-position="top">
              <ElFormItem :label="lang.name">
                <ElInput v-model="form.names[String(lang.id)]" />
              </ElFormItem>
            </ElForm>
          </ElTabPane>
        </ElTabs>
      </ElCard>

      <ElCard shadow="never">
        <ElForm label-position="top">
          <div class="grid grid-cols-1 gap-x-6 md:grid-cols-3">
            <ElFormItem :label="tl('game_code')">
              <ElInput v-model="form.game_type" />
            </ElFormItem>
            <ElFormItem :label="`${tl('game_code')} 2`">
              <ElInput v-model="form.remark" />
            </ElFormItem>
            <ElFormItem :label="tl('show_sort')">
              <ElInput v-model="form.sort" maxlength="8" />
            </ElFormItem>
            <ElFormItem :label="tl('memo')">
              <ElInput v-model="form.memo" maxlength="50" />
            </ElFormItem>
            <ElFormItem :label="tl('status')">
              <ElRadioGroup v-model="form.status">
                <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
                <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
              </ElRadioGroup>
            </ElFormItem>
            <ElFormItem :label="tl('rtp')">
              <ElInputNumber
                v-model="form.rtp"
                :max="100"
                :min="0"
                :step="0.01"
                class="!w-full"
              />
            </ElFormItem>
          </div>
          <ElFormItem :label="tl('game_tags')">
            <ElSelect
              v-model="form.tags"
              class="!w-full"
              collapse-tags
              collapse-tags-tooltip
              filterable
              multiple
            >
              <ElOption
                v-for="t in tagOptions"
                :key="t.id"
                :label="t.tag_name"
                :value="t.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('picture')">
            <div class="flex flex-col gap-2">
              <img
                v-if="imagePreview"
                :src="imagePreview"
                style="max-width: 200px; max-height: 200px"
              />
              <div>
                <ElButton @click="imageInput?.click()">
                  {{ tl('choose_file') }}
                </ElButton>
              </div>
              <div class="text-muted-foreground mt-1 text-xs">
                {{ imageUploadText('image_upload_hint') }}
              </div>
              <input
                ref="imageInput"
                :accept="IMAGE_ACCEPT"
                class="hidden"
                type="file"
                @change="onImageChange"
              />
            </div>
          </ElFormItem>
        </ElForm>
      </ElCard>
    </div>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="submitting" type="primary" @click="handleSave">
        {{ tl('save') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
