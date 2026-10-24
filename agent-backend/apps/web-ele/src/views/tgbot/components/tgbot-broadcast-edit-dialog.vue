<script lang="ts" setup>
import { reactive, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
} from 'element-plus';

import {
  createTgbotBroadcastApi,
  getTgbotBrandListApi,
  updateTgbotBroadcastApi,
  uploadTgbotMediaApi,
} from '#/api';
import ImageInput from '#/components/image-input.vue';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

/** 訊息類型（voice 取代 audio） */
const MESSAGE_TYPES = ['text', 'image', 'animation', 'voice'] as const;
/** 群發固定 4 語系 */
const LANGS = [
  { code: 'en-us', name: 'English' },
  { code: 'zh-tw', name: 'Traditional Chinese' },
  { code: 'zh-cn', name: 'Simplified Chinese' },
  { code: 'ms-my', name: 'Bahasa Melayu' },
];

/** 各類型可接受的檔案 */
const ACCEPT: Record<string, string> = {
  animation: 'video/mp4',
  image: 'image/png,image/jpeg',
  voice: 'audio/ogg,audio/mpeg',
};

const visible = ref(false);
const brands = ref<
  Array<{ bot_id?: string; count?: number | string; id: number | string }>
>([]);

/** 有 id 代表編輯既有的未發送訊息 */
const editingId = ref<number | string>('');

const form = reactive({
  brand_id: '' as number | string,
  message_type: 'text',
});

/** 各語系內容 + 媒體 url + 預覽 */
const langData = reactive<
  Record<string, { content: string; mediaUrl: string }>
>({});

function resetLangData() {
  for (const l of LANGS) langData[l.code] = { content: '', mediaUrl: '' };
}
resetLangData();

/** 上傳媒體：回傳 file_url 給 ImageInput 綁定 */
async function uploadMedia(base64: string): Promise<string> {
  const res = await uploadTgbotMediaApi(base64);
  if (Number(res.code) === 1000 && res.data?.file_url) {
    ElMessage.success(tl('success'));
    return res.data.file_url;
  }
  ElMessage.error(res.message);
  return '';
}

const saving = ref(false);

async function handleSubmit() {
  const isText = form.message_type === 'text';
  // en-us 必填
  const en = langData['en-us'];
  if (!en) return;
  if (isText ? en.content.trim() === '' : en.mediaUrl.trim() === '') {
    ElMessage.warning(tl('please_provide_valid_input_for_english'));
    return;
  }
  const messages: Array<{
    content?: string;
    lang: string;
    media_url?: string;
  }> = [];
  for (const l of LANGS) {
    const d = langData[l.code];
    if (!d) continue;
    if (isText) {
      if (d.content.trim() !== '')
        messages.push({ content: d.content, lang: l.code });
    } else if (d.mediaUrl.trim() !== '') {
      messages.push({
        content: d.content,
        lang: l.code,
        media_url: d.mediaUrl,
      });
    }
  }
  saving.value = true;
  try {
    const payload = {
      brand_id: form.brand_id,
      message_type: form.message_type,
      messages,
    };
    const res = editingId.value
      ? await updateTgbotBroadcastApi({ id: editingId.value, ...payload })
      : await createTgbotBroadcastApi(payload);
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}

interface BroadcastRow {
  brand_id?: number | string;
  id: number | string;
  /** JSON 字串：[{lang, content, media_url?}] */
  message?: string;
  type?: string;
}

/** 帶 row 進來就是編輯（僅未發送可編輯），不帶就是新增 */
async function open(row?: BroadcastRow) {
  editingId.value = row?.id ?? '';
  form.brand_id = row?.brand_id ?? '';
  form.message_type = row?.type ?? 'text';
  resetLangData();

  if (row?.message) {
    try {
      const parsed = JSON.parse(row.message);
      if (Array.isArray(parsed)) {
        for (const m of parsed) {
          const d = langData[m.lang];
          if (d) {
            d.content = m.content ?? '';
            d.mediaUrl = m.media_url ?? '';
          }
        }
      }
    } catch {
      /* 內容壞掉就當空白，讓營運重填 */
    }
  }

  visible.value = true;
  const res = await getTgbotBrandListApi();
  if (Number(res.code) === 1000) {
    brands.value = res.data ?? [];
    if (!form.brand_id && brands.value.length > 0) {
      form.brand_id = brands.value[0]?.id ?? form.brand_id;
    }
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('menu_tgbot_telegram_broadcast_message')"
    top="5vh"
    width="900"
  >
    <ElForm label-position="top" @submit.prevent="handleSubmit">
      <ElFormItem :label="tl('brand')">
        <ElSelect v-model="form.brand_id" class="w-full" filterable>
          <ElOption
            v-for="b in brands"
            :key="b.id"
            :label="`${b.bot_id} (${b.count})`"
            :value="b.id"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem :label="tl('message_type')">
        <ElSelect v-model="form.message_type" class="w-full">
          <ElOption
            v-for="t in MESSAGE_TYPES"
            :key="t"
            :label="tl(t)"
            :value="t"
          />
        </ElSelect>
      </ElFormItem>

      <div v-for="l in LANGS" :key="l.code" class="mb-4 border-t pt-3">
        <div class="mb-2 font-medium">{{ l.name }}</div>
        <ElFormItem
          :label="`${tl('content')} (${l.name})${l.code === 'en-us' ? ' *' : ''}`"
        >
          <ElInput
            v-model="langData[l.code]!.content"
            :rows="3"
            type="textarea"
          />
        </ElFormItem>
        <ElFormItem
          v-if="form.message_type !== 'text'"
          :label="`${tl('media')} (${l.name})${l.code === 'en-us' ? ' *' : ''}`"
        >
          <ImageInput
            v-model="langData[l.code]!.mediaUrl"
            :accept="ACCEPT[form.message_type] ?? '*/*'"
            :max-size-mb="5"
            :upload-fn="uploadMedia"
            class="w-full"
          />
        </ElFormItem>
      </div>
    </ElForm>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSubmit">
        {{ tl('save') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
