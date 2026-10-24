<script lang="ts" setup>
import type { AdvertiseImageItem, BankDropdownItem } from '#/api';

import { reactive, ref } from 'vue';

import { useUserStore } from '@vben/stores';

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

import {
  getAdvertiseImageApi,
  getBankDropdownListApi,
  getCustomizedSettingApi,
  saveBankAccountApi,
  uploadBase64ImageApi,
} from '#/api';
import ImageInput from '#/components/image-input.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const userStore = useUserStore();

const visible = ref(false);

const enableBsb = ref(false);
const bsbAlias = ref('BSB Code');
const banks = ref<BankDropdownItem[]>([]);
const submitting = ref(false);
let settingsLoaded = false;

const form = reactive(makeDefaults());

function makeDefaults() {
  return {
    account_name: '',
    account_nickname: '',
    account_no: '',
    amount_threshold: '' as number | string,
    bank: '' as number | string,
    bsb_code: '',
    count_threshold: '' as number | string,
    currency: '',
    is_deposit_bank: 1 as number | string,
    is_withdrawal_bank: 0 as number | string,
    max_amount: '' as number | string,
    min_amount: '' as number | string,
    qrcode: '',
    rate: '' as number | string,
    status: 1 as number | string,
  };
}

function syncCurrencyByBank() {
  const b = banks.value.find((x) => Number(x.id) === Number(form.bank));
  form.currency = b?.currency ?? '';
}

/* ---- QR 圖片 ---- */
async function uploadQr(base64: string): Promise<string> {
  const res = await uploadBase64ImageApi({
    agent_id: (userStore.userInfo as any)?.host_id ?? 0,
    base64Image: base64,
    type: 1,
  });
  if (Number(res.code) === 1000 && res.data?.path) return res.data.path;
  ElMessage.error(res.message || tl('failure'));
  return '';
}

function clearQr() {
  form.qrcode = '';
}

/* ---- 圖庫選擇 modal ---- */
const imgDialog = reactive({ page: 1, total: 0, visible: false });
const imgList = ref<AdvertiseImageItem[]>([]);
const imgLoading = ref(false);

async function loadImages() {
  imgLoading.value = true;
  try {
    const res = await getAdvertiseImageApi({ page: imgDialog.page });
    if (Number(res.code) === 1000) {
      imgList.value = res.data ?? [];
      imgDialog.total = res.pagination?.total ?? 0;
    } else {
      imgList.value = [];
      imgDialog.total = 0;
    }
  } finally {
    imgLoading.value = false;
  }
}

function openImgPicker() {
  imgDialog.page = 1;
  imgDialog.visible = true;
  loadImages();
}

function pickImage(item: AdvertiseImageItem) {
  form.qrcode = item.image_url ?? '';
  imgDialog.visible = false;
}

async function handleSave() {
  if (!form.account_nickname.trim()) {
    ElMessage.warning(`${tl('please_enter')} ${tl('account_code')}`);
    return;
  }
  if (!String(form.rate).trim()) {
    ElMessage.warning(`${tl('please_enter')} ${tl('rate')}`);
    return;
  }
  submitting.value = true;
  try {
    const res = await saveBankAccountApi({
      account_name: form.account_name,
      account_nickname: form.account_nickname,
      account_no: form.account_no,
      amount_threshold: form.amount_threshold,
      bsb_code: form.bsb_code,
      count_threshold: form.count_threshold,
      order_type_id: form.bank,
      is_deposit_bank: form.is_deposit_bank,
      is_withdrawal_bank: form.is_withdrawal_bank,
      max_amount: form.max_amount,
      min_amount: form.min_amount,
      qrcode: form.qrcode,
      rate: form.rate,
      status: form.status,
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

/**
 * 4545：戶口建立後不再開放編輯（後端 bank/editAccount 已棄用），本對話框只用於新增。
 * 要改戶口資料請新增一筆、停用舊的。
 */
async function open() {
  Object.assign(form, makeDefaults());
  visible.value = true;

  if (!settingsLoaded) {
    const settings = await getCustomizedSettingApi().catch(() => []);
    const map = new Map(
      (settings ?? []).map((s: any) => [Number(s.setting_key), s.value]),
    );
    enableBsb.value = Number(map.get(4011) ?? 0) === 1;
    if (map.get(3015)) bsbAlias.value = String(map.get(3015));
    settingsLoaded = true;
  }

  const res = await getBankDropdownListApi();
  if (Number(res.code) === 1000) {
    banks.value = res.data ?? [];
    if (banks.value[0]) {
      form.bank = banks.value[0].id;
      syncCurrencyByBank();
    }
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('menu_bank_account_management')} - ${tl('add_bank_account')}`"
    top="5vh"
    width="900"
  >
    <ElForm
      class="max-h-[72vh] overflow-y-auto pr-1"
      label-position="top"
      @submit.prevent="handleSave"
    >
      <div class="grid grid-cols-1 items-start gap-x-6 md:grid-cols-2">
        <ElFormItem :label="tl('bank_name')">
          <ElSelect
            v-model="form.bank"
            class="!w-full"
            filterable
            @change="syncCurrencyByBank"
          >
            <ElOption
              v-for="b in banks"
              :key="b.id"
              :label="b.bank_name"
              :value="b.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('currency')">
          <ElInput v-model="form.currency" disabled />
        </ElFormItem>
        <ElFormItem :label="tl('account_code')">
          <ElInput v-model="form.account_nickname" />
        </ElFormItem>
        <ElFormItem :label="tl('account_name')">
          <ElInput v-model="form.account_name" />
        </ElFormItem>
        <ElFormItem :label="tl('account_no')">
          <ElInput v-model="form.account_no" />
        </ElFormItem>
        <ElFormItem v-if="enableBsb" :label="bsbAlias">
          <ElInput v-model="form.bsb_code" />
        </ElFormItem>
        <ElFormItem>
          <template #label>
            {{ tl('min_amount') }}
            <span class="text-red-500">({{ tl('depoist_bank') }})</span>
          </template>
          <ElInput v-model="form.min_amount" />
        </ElFormItem>
        <ElFormItem>
          <template #label>
            {{ tl('max_amount') }}
            <span class="text-red-500">({{ tl('depoist_bank') }})</span>
          </template>
          <ElInput v-model="form.max_amount" />
        </ElFormItem>
        <ElFormItem :label="tl('rate')" required>
          <ElInput v-model="form.rate" />
        </ElFormItem>
        <ElFormItem :label="tl('status')">
          <ElRadioGroup v-model="form.status">
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem :label="tl('is_deposit_bank')">
          <ElRadioGroup v-model="form.is_deposit_bank">
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem>
          <template #label>
            {{ tl('is_withdrawal_bank') }}
            <span class="text-red-500">
              ({{ tl('contact_administrator_for_withdrawal') }})
            </span>
          </template>
          <ElRadioGroup v-model="form.is_withdrawal_bank">
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem>
          <template #label>
            {{ tl('notify_amount_threshold') }}
            <span class="text-red-500">(0: {{ tl('disable') }})</span>
          </template>
          <ElInput v-model="form.amount_threshold" />
        </ElFormItem>
        <ElFormItem>
          <template #label>
            {{ tl('notify_count_threshold') }}
            <span class="text-red-500">(0: {{ tl('disable') }})</span>
          </template>
          <ElInput v-model="form.count_threshold" />
        </ElFormItem>
      </div>

      <ElFormItem :label="`QR Code ${tl('picture')}`">
        <ImageInput v-model="form.qrcode" :upload-fn="uploadQr" class="w-full">
          <template #actions>
            <ElButton size="small" @click="openImgPicker">
              {{ tl('choose_file') }}
            </ElButton>
            <ElButton size="small" type="danger" @click="clearQr">
              {{ tl('delete') }}
            </ElButton>
          </template>
        </ImageInput>
      </ElFormItem>
    </ElForm>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="submitting" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>

  <!-- 圖庫選擇 -->
  <ElDialog
    v-model="imgDialog.visible"
    :title="tl('upload_image')"
    width="680px"
  >
    <div v-loading="imgLoading" class="grid grid-cols-3 gap-3">
      <div
        v-for="img in imgList"
        :key="img.id"
        class="cursor-pointer rounded border p-1 hover:border-blue-400"
        @click="pickImage(img)"
      >
        <ElImage :src="img.image_url" fit="contain" style="width: 100%" />
      </div>
      <div v-if="imgList.length === 0" class="col-span-3 text-center">
        {{ tl('no_record') }}
      </div>
    </div>
    <TablePagination
      :current-page="imgDialog.page"
      :page-size="6"
      :total="imgDialog.total"
      layout="total, prev, pager, next"
      @current-change="
        (p: number) => {
          imgDialog.page = p;
          loadImages();
        }
      "
    />
  </ElDialog>
</template>
