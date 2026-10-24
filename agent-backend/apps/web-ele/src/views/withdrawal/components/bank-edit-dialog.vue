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
  ElRadio,
  ElRadioGroup,
  ElSelect,
} from 'element-plus';

import {
  getWithdrawalBankInfoApi,
  getWithdrawalCurrencyListApi,
  getWithdrawalTypesApi,
  saveWithdrawalBankApi,
} from '#/api';
import { tl } from '#/locales';
import {
  IMAGE_ACCEPT,
  ImageUploadError,
  imageUploadText,
  readImageAsDataUrl,
} from '#/utils/image-upload';

defineOptions({ name: 'BankEditDialog' });

const emit = defineEmits<{ saved: [] }>();

const visible = ref(false);
const loading = ref(false);
const saving = ref(false);
const editId = ref<null | number | string>(null);
const types = ref<Array<{ id: number; name: string }>>([]);
const currencies = ref<Array<{ id: number; name?: string }>>([]);
const previewUrl = ref('');

const form = reactive({
  bank_code: '',
  bank_name: '',
  bank_rate: '',
  bank_tax: '',
  bank_type: '' as number | string,
  currency_id: '' as number | string,
  /** 上傳的新圖 base64，空表示不變更 */
  image_url: '',
  max_amount: '',
  min_amount: '',
  sort: '',
  status: '1' as number | string,
  /** 0=金額、1=百分比 */
  tax_type: 0 as number | string,
});

function resetForm() {
  form.bank_code = '';
  form.bank_name = '';
  form.bank_rate = '';
  form.bank_tax = '';
  form.bank_type = types.value[0]?.id ?? '';
  form.currency_id = currencies.value[0]?.id ?? '';
  form.image_url = '';
  form.max_amount = '';
  form.min_amount = '';
  form.sort = '';
  form.status = '1';
  form.tax_type = 0;
  previewUrl.value = '';
}

async function open(id?: number | string) {
  visible.value = true;
  editId.value = id ?? null;
  loading.value = true;
  try {
    if (types.value.length === 0) {
      types.value = (await getWithdrawalTypesApi().catch(() => [])) ?? [];
    }
    if (currencies.value.length === 0) {
      currencies.value =
        (await getWithdrawalCurrencyListApi().catch(() => [])) ?? [];
    }
    resetForm();
    if (id) {
      const res = await getWithdrawalBankInfoApi(id);
      if (Number(res.code) === 1000 && res.data) {
        const d = res.data;
        form.bank_type = d.bank_type ?? form.bank_type;
        form.tax_type = Number(d.tax_type) === 1 ? 1 : 0;
        form.bank_tax = String(
          Number(d.tax_type) === 0
            ? (d.bank_tax ?? '')
            : Number(d.bank_tax) * 100,
        );
        form.bank_name = d.bank_name ?? '';
        form.bank_code = d.bank_code ?? '';
        form.min_amount = String(d.min_amount ?? '');
        form.max_amount = String(d.max_amount ?? '');
        form.sort = String(d.sort ?? '');
        form.bank_rate = String(d.bank_rate ?? '');
        form.currency_id = d.currency_id ?? form.currency_id;
        form.status = Number(d.status) === 1 ? '1' : '0';
        previewUrl.value = d.image_url ?? '';
      }
    }
  } finally {
    loading.value = false;
  }
}

function pickImage() {
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
    previewUrl.value = base64;
    form.image_url = base64;
  });
  input.click();
}

async function handleSave() {
  saving.value = true;
  try {
    const data: Record<string, any> = {
      bank_code: form.bank_code,
      bank_name: form.bank_name,
      bank_rate: form.bank_rate,
      bank_tax: form.bank_tax,
      bank_type: form.bank_type,
      max_amount: form.max_amount,
      min_amount: form.min_amount,
      sort: form.sort,
      status: form.status,
      tax_type: form.tax_type,
    };
    if (Number(form.currency_id) > 0) data.currency_id = form.currency_id;
    if (form.image_url) data.image_url = form.image_url;
    if (editId.value) data.id = editId.value;

    const res = await saveWithdrawalBankApi(data, Boolean(editId.value));
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
    :title="`${tl('bank_management')} > ${tl('edit_bank')}`"
    width="820"
    align-center
  >
    <ElForm v-loading="loading" :model="form" label-position="top">
      <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
        <ElFormItem :label="`${tl('bank')}${tl('type')}`">
          <ElSelect v-model="form.bank_type" class="w-full">
            <ElOption
              v-for="t in types"
              :key="t.id"
              :label="t.name"
              :value="t.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('handling_fee')">
          <div class="flex w-full items-center gap-2">
            <ElInput v-model="form.bank_tax" class="flex-1" />
            <ElRadioGroup v-model="form.tax_type">
              <ElRadio :value="0">$</ElRadio>
              <ElRadio :value="1">%</ElRadio>
            </ElRadioGroup>
          </div>
        </ElFormItem>
        <ElFormItem :label="tl('bank_name')">
          <ElInput v-model="form.bank_name" />
        </ElFormItem>
        <ElFormItem :label="tl('bank_code')">
          <ElInput v-model="form.bank_code" />
        </ElFormItem>
        <ElFormItem :label="tl('min_amount')">
          <ElInput v-model="form.min_amount" type="number" />
        </ElFormItem>
        <ElFormItem :label="tl('max_amount')">
          <ElInput v-model="form.max_amount" type="number" />
        </ElFormItem>
        <ElFormItem :label="tl('sorts')">
          <ElInput v-model="form.sort" type="number" />
        </ElFormItem>
        <ElFormItem :label="tl('rate')">
          <ElInput v-model="form.bank_rate" type="number" />
        </ElFormItem>
        <ElFormItem :label="tl('currency_name')">
          <ElSelect v-model="form.currency_id" class="w-full">
            <ElOption
              v-for="c in currencies"
              :key="c.id"
              :label="c.name"
              :value="c.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('enable_status')">
          <ElRadioGroup v-model="form.status">
            <ElRadio value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
      </div>
      <ElFormItem :label="tl('picture')">
        <div>
          <img
            v-if="previewUrl"
            :src="previewUrl"
            class="mb-2 max-h-[200px] max-w-[200px]"
          />
          <div>
            <ElButton @click="pickImage">{{ tl('choose_file') }}</ElButton>
          </div>
          <div class="text-muted-foreground mt-1 text-xs">
            {{ imageUploadText('image_upload_hint') }}
          </div>
        </div>
      </ElFormItem>
    </ElForm>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
