<script lang="ts" setup>
import { computed, reactive, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElImage,
  ElInput,
  ElMessage,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
} from 'element-plus';

import { getBankViewApi, saveBankApi } from '#/api';
// 避開 report.ts / currency.ts(s1) 對 getCurrencyListApi 的重複 star export
import { getCurrencyListApi } from '#/api/report';
import { tl } from '#/locales';
import {
  IMAGE_ACCEPT,
  ImageUploadError,
  imageUploadText,
  readImageAsDataUrl,
} from '#/utils/image-upload';

const emit = defineEmits<{ success: [] }>();

/** 付款方式 1電子 2網路轉帳 3QR掃碼 4加密貨幣 */
const PAY_METHODS = [1, 2, 3, 4] as const;

const visible = ref(false);
const bankId = ref('');
const isEdit = computed(() => Boolean(bankId.value));

const currencies = ref<Array<{ id: number | string; name?: string }>>([]);
const submitting = ref(false);
const imagePreview = ref('');
let currenciesLoaded = false;

const form = reactive(makeDefaults());

function makeDefaults() {
  return {
    bank_code: '',
    bank_name: '',
    currency: '' as number | string,
    image: '',
    status: 1 as number | string,
    type: 1 as number | string,
  };
}

async function onFileChange(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0];
  if (!file) return;
  let b64: string;
  try {
    b64 = await readImageAsDataUrl(file);
  } catch (error) {
    if (error instanceof ImageUploadError) {
      ElMessage.error(error.message);
      return;
    }
    throw error;
  }
  form.image = b64;
  imagePreview.value = b64;
}

async function handleSave() {
  submitting.value = true;
  try {
    const res = await saveBankApi({
      bank_code: form.bank_code,
      bank_name: form.bank_name,
      currency: form.currency,
      ...(isEdit.value ? { id: bankId.value } : {}),
      image: form.image,
      status: form.status,
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

async function open(id: number | string = '') {
  bankId.value = id ? String(id) : '';
  Object.assign(form, makeDefaults());
  imagePreview.value = '';
  visible.value = true;
  if (!currenciesLoaded) {
    currencies.value = (await getCurrencyListApi().catch(() => [])) ?? [];
    currenciesLoaded = true;
  }
  if (isEdit.value) {
    const res = await getBankViewApi(bankId.value);
    if (Number(res.code) === 1000 && res.data) {
      const b = res.data;
      form.bank_name = b.bank_name ?? '';
      form.bank_code = b.bank_code ?? '';
      form.currency = b.currency_id ?? '';
      form.type = b.type ?? 1;
      form.status = Number(b.status) === 1 ? 1 : 0;
      imagePreview.value = b.image_path ?? '';
    }
  }
  if (!form.currency && currencies.value[0]) {
    form.currency = currencies.value[0].id;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog v-model="visible" :title="tl('edit_bank')" top="6vh" width="820">
    <ElForm label-width="120px" @submit.prevent="handleSave">
      <div class="grid grid-cols-1 gap-x-6 md:grid-cols-2">
        <ElFormItem :label="tl('bank_name')">
          <ElInput v-model="form.bank_name" />
        </ElFormItem>
        <ElFormItem :label="tl('bank_code')">
          <ElInput v-model="form.bank_code" />
        </ElFormItem>
        <ElFormItem :label="tl('currency')">
          <ElSelect v-model="form.currency" class="!w-full">
            <ElOption
              v-for="c in currencies"
              :key="c.id"
              :label="c.name"
              :value="c.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('pay_method')">
          <ElSelect v-model="form.type" class="!w-full">
            <ElOption
              v-for="m in PAY_METHODS"
              :key="m"
              :label="tl(`pay_method.${m}`)"
              :value="m"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('picture')">
          <div>
            <ElImage
              v-if="imagePreview"
              :src="imagePreview"
              fit="contain"
              style="max-width: 200px; max-height: 200px"
            />
            <div class="mt-2">
              <input
                type="file"
                :accept="IMAGE_ACCEPT"
                @change="onFileChange"
              />
            </div>
            <div class="text-muted-foreground mt-1 text-xs">
              {{ imageUploadText('image_upload_hint') }}
            </div>
          </div>
        </ElFormItem>
        <ElFormItem :label="tl('enable_status')">
          <ElRadioGroup v-model="form.status">
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
      </div>
    </ElForm>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="submitting" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
