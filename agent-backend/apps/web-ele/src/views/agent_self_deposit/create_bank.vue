<script lang="ts" setup>
import type { UploadFile } from 'element-plus';

import type { DepositBankAccount } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElImage,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
  ElUpload,
} from 'element-plus';

import {
  cancelDepositOrderApi,
  createDepositOrderBankApi,
  getAgentViewApi,
  getDepositBankAccountsApi,
  uploadDepositReceiptImageApi,
} from '#/api';
import { tl } from '#/locales';
import {
  IMAGE_ACCEPT,
  ImageUploadError,
  imageUploadText,
  readImageAsDataUrl,
} from '#/utils/image-upload';

defineOptions({ name: 'AgentSelfDepositCreateBank' });

const userStore = useUserStore();

const accounts = ref<DepositBankAccount[]>([]);
/** 1=無處理中訂單(可建單)、2=有處理中訂單(待上傳收據) */
const mode = ref<number>(1);
const order = ref<null | { amount?: number | string; id?: number | string }>(
  null,
);
const balance = ref(0);
const submitting = ref(false);

const form = reactive({
  currency: '' as number | string,
  bankKey: '',
  bank_account_id: '' as number | string,
  amount: '' as number | string,
  image: '',
});
const imagePreview = ref('');

const round = (v: number, d: number) =>
  Number(`${Math.round(Number(`${v}e${d}`))}e-${d}`);

const isUpload = computed(() => Number(mode.value) === 2);

const bankKeyOf = (a: DepositBankAccount) =>
  `${a.bank_name ?? ''}|${a.bank_code ?? ''}`;

/** 幣別下拉（currency，去重） */
const currencyOptions = computed(() => {
  const set = new Set<string>();
  accounts.value.forEach((a) => {
    if (a.currency) set.add(String(a.currency));
  });
  return [...set];
});

/** 銀行下拉（bank_name|bank_code 去重，依幣別） */
const bankOptions = computed(() => {
  const map = new Map<string, string>();
  accounts.value
    .filter((a) => String(a.currency) === String(form.currency))
    .forEach((a) => {
      const key = bankKeyOf(a);
      if (!map.has(key)) {
        map.set(
          key,
          `${a.bank_name ?? ''}${a.bank_code ? ` (${a.bank_code})` : ''}`,
        );
      }
    });
  return [...map.entries()].map(([key, label]) => ({ key, label }));
});

/** 帳號下拉（依幣別＋銀行） */
const accountOptions = computed(() =>
  accounts.value.filter(
    (a) =>
      String(a.currency) === String(form.currency) &&
      bankKeyOf(a) === form.bankKey,
  ),
);

const selectedAccount = computed(() =>
  accounts.value.find(
    (a) => String(a.bank_account_id) === String(form.bank_account_id),
  ),
);

const info = computed(() => {
  const a = selectedAccount.value;
  if (!a) return null;
  const rate = Number(a.rate) || 1;
  const amount = Number(form.amount) || 0;
  const changeBalance = amount * rate;
  return {
    accountNo: a.account_no ?? '',
    min: round(Number(a.min_amount) || 0, 2),
    max: round(Number(a.max_amount) || 0, 2),
    rate: round(rate, 4),
    net: round(amount, 2),
    before: round(balance.value, 4),
    change: `${amount} * ${round(rate, 4)} = ${round(changeBalance, 4)}`,
    after: `${round(balance.value, 4)} + ${round(changeBalance, 4)} = ${round(balance.value + changeBalance, 4)}`,
  };
});

function onCurrencyChange() {
  form.bankKey = '';
  form.bank_account_id = '';
  form.amount = '';
}
function onBankChange() {
  form.bank_account_id = '';
  form.amount = '';
}

async function copyAccountNo() {
  if (!info.value?.accountNo) return;
  try {
    await navigator.clipboard.writeText(info.value.accountNo);
    ElMessage.success(`${tl('copyText')}: ${info.value.accountNo}`);
  } catch {
    ElMessage.error('Failed to copy');
  }
}

/** 選檔 → 轉 base64 存入 form.image（非 multipart，走加密 POST 欄位） */
async function onFileChange(file: UploadFile) {
  const raw = file.raw;
  if (!raw) return;
  try {
    form.image = await readImageAsDataUrl(raw);
  } catch (error) {
    if (error instanceof ImageUploadError) {
      ElMessage.error(error.message);
      return;
    }
    throw error;
  }
  imagePreview.value = form.image;
}

function validateCreate(): boolean {
  const a = selectedAccount.value;
  if (!a) {
    ElMessage.warning(tl('bank_not_found'));
    return false;
  }
  const amountStr = String(form.amount);
  const amount = Number(form.amount) || 0;
  if (amount <= 0) {
    ElMessage.warning(tl('amount_is_out_of_range'));
    return false;
  }
  const min = Number(a.min_amount) || 0;
  const max = Number(a.max_amount) || 0;
  if (min > 0 && amount < min) {
    ElMessage.warning(`${tl('amount_below_minimum')} (${min})`);
    return false;
  }
  if (max > 0 && amount > max) {
    ElMessage.warning(`${tl('amount_above_maximum')} (${max})`);
    return false;
  }
  if (!/^\d+(\.\d{1,2})?$/.test(amountStr)) {
    ElMessage.warning(tl('amount_only_two_decimal_places'));
    return false;
  }
  return true;
}

async function loadData() {
  try {
    const res = await getDepositBankAccountsApi();
    if (Number(res.code) === 1000) {
      const d = res.data ?? {};
      accounts.value = d.bank_accounts ?? [];
      mode.value = Number(d.type) || 1;
      order.value = d.order ?? null;
      // type=2：鎖定並帶入處理中訂單的帳號＋金額
      if (Number(d.type) === 2 && accounts.value[0]) {
        const a = accounts.value[0];
        form.currency = a.currency ?? '';
        form.bankKey = bankKeyOf(a);
        form.bank_account_id = a.bank_account_id;
        form.amount = order.value?.amount ?? '';
      }
    }
  } catch {
    accounts.value = [];
  }
}

async function handleSave() {
  if (isUpload.value) {
    if (!form.image) {
      ElMessage.warning(tl('please_choose'));
      return;
    }
    submitting.value = true;
    try {
      const res = await uploadDepositReceiptImageApi({
        image: form.image,
        order_id: order.value?.id ?? '',
      });
      if (Number(res.code) === 1000) {
        ElMessage.success(tl('success'));
        form.image = '';
        imagePreview.value = '';
        loadData();
      } else {
        ElMessage.error(res.message);
      }
    } finally {
      submitting.value = false;
    }
    return;
  }
  if (!validateCreate()) return;
  submitting.value = true;
  try {
    const res = await createDepositOrderBankApi({
      amount: form.amount,
      bank_account_id: form.bank_account_id,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      loadData();
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    submitting.value = false;
  }
}

async function handleCancel() {
  try {
    await ElMessageBox.confirm(tl('btn_cancel'), tl('btn_cancel'), {
      type: 'warning',
    });
  } catch {
    return;
  }
  submitting.value = true;
  try {
    const res = await cancelDepositOrderApi({
      order_id: order.value?.id ?? '',
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      loadData();
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    submitting.value = false;
  }
}

onMounted(() => {
  loadData();
  const id = Number((userStore.userInfo as any)?.id ?? 0);
  if (id) {
    getAgentViewApi(id)
      .then((v: any) => {
        balance.value = Number(v?.balance) || 0;
      })
      .catch(() => {});
  }
});
</script>

<template>
  <Page
    :title="`${tl('menu_agent_self_deposit_withdrawal')} > ${tl('menu_agent_self_deposit_create_bank')}`"
    auto-content-height
    footer-class="border-t border-border"
  >
    <ElCard shadow="never">
      <ElForm label-width="120px" @submit.prevent="handleSave">
        <ElFormItem :label="tl('currency')">
          <ElSelect
            v-model="form.currency"
            :disabled="isUpload"
            :placeholder="tl('please_choose')"
            class="!w-full"
            @change="onCurrencyChange"
          >
            <ElOption
              v-for="c in currencyOptions"
              :key="c"
              :label="c"
              :value="c"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('bank')">
          <ElSelect
            v-model="form.bankKey"
            :disabled="isUpload || !form.currency"
            :placeholder="tl('please_choose')"
            class="!w-full"
            @change="onBankChange"
          >
            <ElOption
              v-for="b in bankOptions"
              :key="b.key"
              :label="b.label"
              :value="b.key"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('account')">
          <ElSelect
            v-model="form.bank_account_id"
            :disabled="isUpload || !form.bankKey"
            :placeholder="tl('please_choose')"
            class="!w-full"
          >
            <ElOption
              v-for="a in accountOptions"
              :key="a.bank_account_id"
              :label="`${a.account_name}${a.account_no ? ` - ${a.account_no}` : ''}`"
              :value="a.bank_account_id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="info" :label="tl('amount')">
          <ElInput v-model="form.amount" :readonly="isUpload" type="number" />
        </ElFormItem>

        <div v-if="info" class="mb-3 rounded border p-4 text-sm">
          <div class="mb-2 flex items-center gap-2">
            <strong>{{ tl('account_no') }}：</strong>
            <span>{{ info.accountNo }}</span>
            <ElButton size="small" @click="copyAccountNo">
              {{ tl('copyText') }}
            </ElButton>
          </div>
          <div class="grid grid-cols-1 gap-1 md:grid-cols-2">
            <div>{{ tl('deposit_min_amount') }}：{{ info.min }}</div>
            <div>{{ tl('deposit_max_amount') }}：{{ info.max }}</div>
            <div>{{ tl('deposit_exchange_rate') }}：{{ info.rate }}</div>
            <div>{{ tl('deposit_net_amount') }}：{{ example.invalid }}</div>
            <div>{{ tl('before_balance') }}：{{ info.before }}</div>
            <div>{{ tl('change_balance') }}：{{ info.change }}</div>
            <div>{{ tl('after_balance') }}：{{ info.after }}</div>
          </div>
        </div>

        <ElFormItem v-if="isUpload" :label="tl('upload_receipt')">
          <div>
            <ElUpload
              :auto-upload="false"
              :show-file-list="false"
              :accept="IMAGE_ACCEPT"
              @change="onFileChange"
            >
              <ElButton type="info">{{ tl('upload_receipt') }}</ElButton>
            </ElUpload>
            <div class="text-muted-foreground mt-1 text-xs">
              {{ imageUploadText('image_upload_hint') }}
            </div>
            <ElImage
              v-if="imagePreview"
              :src="imagePreview"
              class="mt-3"
              style="max-width: 400px; max-height: 400px"
            />
          </div>
        </ElFormItem>
      </ElForm>
    </ElCard>
    <template #footer>
      <div class="flex w-full gap-2">
        <ElButton
          v-if="isUpload"
          :loading="submitting"
          type="danger"
          @click="handleCancel"
        >
          {{ tl('btn_cancel') }}
        </ElButton>
        <ElButton :loading="submitting" type="primary" @click="handleSave">
          {{ tl('btn_update') }}
        </ElButton>
      </div>
    </template>
  </Page>
</template>
