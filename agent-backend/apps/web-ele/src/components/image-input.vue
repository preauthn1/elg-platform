<script lang="ts" setup>
import { ref } from 'vue';

import { ElInput, ElMessage } from 'element-plus';

import { uploadImageApi } from '#/api/upload';
import { tl } from '#/locales';
import {
  IMAGE_ACCEPT,
  ImageUploadError,
  imageUploadText,
  readImageAsDataUrl,
} from '#/utils/image-upload';

// 圖片欄位：URL 輸入框 + 上傳按鈕 + 即時預覽。
// 選檔後自動上傳並回填網址；也能直接貼網址（上傳失敗不卡死）。
// 預設走通用上傳（/api/image/base64/upload → path）；需要特殊端點/參數才傳 uploadFn 覆寫。
defineOptions({ name: 'ImageInput' });

const props = withDefaults(
  defineProps<{
    /** 檔案 accept，預設圖片 */
    accept?: string;
    /** 非圖片檔的大小上限（MB），0 = 不限；圖片超過上限會自動壓縮 */
    maxSizeMb?: number;
    /** 自訂上傳：吃 base64、回傳圖片網址；不傳則用通用上傳 */
    uploadFn?: (base64: string) => Promise<string>;
  }>(),
  {
    accept: IMAGE_ACCEPT,
    maxSizeMb: 0,
    uploadFn: undefined,
  },
);

const model = defineModel<string>({ default: '' });
const uploading = ref(false);

function readFileAsDataURL(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.addEventListener('load', () => resolve(String(reader.result)));
    reader.addEventListener('error', reject);
    reader.readAsDataURL(file);
  });
}

async function onFileChange(e: Event) {
  const input = e.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  const isImage = !file.type || file.type.startsWith('image/');
  if (
    !isImage &&
    props.maxSizeMb > 0 &&
    file.size > props.maxSizeMb * 1024 * 1024
  ) {
    input.value = '';
    ElMessage.error(tl('file_size_exceed'));
    return;
  }
  let base64: string;
  try {
    base64 = isImage
      ? await readImageAsDataUrl(file)
      : await readFileAsDataURL(file);
  } catch (error) {
    if (error instanceof ImageUploadError) {
      ElMessage.error(error.message);
      return;
    }
    throw error;
  } finally {
    input.value = '';
  }
  uploading.value = true;
  try {
    const url = await (props.uploadFn ?? uploadImageApi)(base64);
    if (url) model.value = url;
    else ElMessage.error(tl('failure'));
  } catch (error: any) {
    ElMessage.error(error?.message || tl('failure'));
  } finally {
    uploading.value = false;
  }
}
</script>

<template>
  <div class="flex w-full flex-col gap-2">
    <div class="flex items-center gap-2">
      <ElInput
        v-model="model"
        :placeholder="`${tl('image')} URL`"
        class="min-w-0 flex-1"
      />
      <label
        :class="{ 'is-disabled': uploading }"
        class="el-button el-button--small cursor-pointer"
      >
        {{ tl('upload_image') }}
        <input
          :accept="accept"
          :disabled="uploading"
          class="hidden"
          type="file"
          @change="onFileChange"
        />
      </label>
      <slot name="actions"></slot>
    </div>
    <div
      v-if="accept === IMAGE_ACCEPT"
      class="text-muted-foreground mt-1 text-xs"
    >
      {{ imageUploadText('image_upload_hint') }}
    </div>
    <img
      v-if="model"
      :src="model"
      class="max-h-[200px] max-w-full self-start rounded border object-contain"
    />
  </div>
</template>
