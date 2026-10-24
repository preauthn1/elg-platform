<script lang="ts" setup>
import type { DepositFastOrderType } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import { useQRCode } from '@vueuse/integrations/useQRCode';
import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElImage,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
} from 'element-plus';

import {
  createDepositFastOrderApi,
  getAgentViewApi,
  getDepositFastOrderTypeListApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'AgentSelfDepositCreateFast' });

const userStore = useUserStore();

/**
 * 代理不開放的代收金流商（cash_flow_id）。
 * CCPayment（129）是永久地址模式：錢包地址要存在持有者名下，會員有 member_crypto_wallets，
 * 代理沒有對應的資料表，建單接口會直接回錯誤（target/tickets#4419）。
 * 在清單源頭濾掉，幣別下拉也會跟著消失（該幣別只剩這些通道時）。
 */
const BLOCKED_CASH_FLOW_IDS = new Set([129]);

const orderTypes = ref<DepositFastOrderType[]>([]);
const balance = ref(0);
const submitting = ref(false);

const form = reactive({
  currency: '' as number | string,
  type: '' as number | string,
  bank: '' as number | string,
});
const dynamicValues = reactive<Record<string, string>>({});

const round = (v: number, d: number) =>
  Number(`${Math.round(Number(`${v}e${d}`))}e-${d}`);

/** 幣別下拉（currency + currency_id，去重） */
const currencyOptions = computed(() => {
  const map = new Map<string, number | string>();
  orderTypes.value.forEach((o) => {
    if (
      o.currency &&
      o.currency_id !== null &&
      o.currency_id !== undefined &&
      !map.has(String(o.currency))
    ) {
      map.set(String(o.currency), o.currency_id);
    }
  });
  return [...map.keys()];
});

/** 第二層（parent==0）依幣別 */
const typeOptions = computed(() =>
  orderTypes.value.filter(
    (o) =>
      Number(o.parent) === 0 && String(o.currency) === String(form.currency),
  ),
);

const selectedType = computed(() =>
  orderTypes.value.find(
    (o) =>
      Number(o.parent) === 0 && String(o.order_type_id) === String(form.type),
  ),
);

/** 第三層（parent==選定第二層 且 幣別相符）；choose_bank==1 時才用 */
const bankOptions = computed(() => {
  const t = selectedType.value;
  if (!t || Number(t.choose_bank) !== 1) return [];
  return orderTypes.value.filter(
    (o) =>
      Number(o.parent) > 0 &&
      String(o.parent) === String(form.type) &&
      String(o.currency) === String(form.currency),
  );
});

const needBank = computed(() => bankOptions.value.length > 0);

/** 最終選定的 order_type：choose_bank==0 或無子層→第二層本身，否則第三層 */
const finalOrderType = computed<DepositFastOrderType | null>(() => {
  const t = selectedType.value;
  if (!t) return null;
  if (Number(t.choose_bank) === 0 || !needBank.value) return t;
  return (
    orderTypes.value.find(
      (o) => String(o.order_type_id) === String(form.bank),
    ) ?? null
  );
});

/** 動態欄位（第三層無 info 時取父層 info） */
const dynamicFields = computed<
  Array<{
    display?: string;
    name: string;
    placeholder?: string;
    require?: boolean | string;
    type?: string;
  }>
>(() => {
  const ot = finalOrderType.value;
  if (!ot) return [];
  let target = ot;
  if (Number(ot.parent) > 0 && !ot.order_type_info) {
    target =
      orderTypes.value.find(
        (o) => String(o.order_type_id) === String(ot.parent),
      ) ?? ot;
  }
  if (!target.order_type_info) return [];
  try {
    const info = JSON.parse(target.order_type_info);
    return Array.isArray(info?.data) ? info.data : [];
  } catch {
    return [];
  }
});

const isRequired = (f: { require?: boolean | string }) =>
  f.require === true || f.require === 'true';

const info = computed(() => {
  const ot = finalOrderType.value;
  if (!ot) return null;
  const rate = Number(ot.rate) || 1;
  const amount = Number(dynamicValues.amount) || 0;
  const changeBalance = amount * rate;
  return {
    min: round(Number(ot.min_amount) || 0, 2),
    max: round(Number(ot.max_amount) || 0, 2),
    rate: round(rate, 4),
    net: round(amount, 2),
    changeBalance: `${amount} * ${round(rate, 4)} = ${round(changeBalance, 4)}`,
    afterBalance: `${round(balance.value, 4)} + ${round(changeBalance, 4)} = ${round(balance.value + changeBalance, 4)}`,
  };
});

/** 連動重置 */
function onCurrencyChange() {
  form.type = '';
  form.bank = '';
}
function onTypeChange() {
  form.bank = '';
}

// 結果彈窗（QR / 圖片）
const resultDialog = reactive({
  visible: false,
  mode: '' as '' | 'image' | 'qr',
  image: '',
});
const qrText = ref('');
const qrDataUrl = useQRCode(qrText, { width: 300 });

function validate(): boolean {
  const ot = finalOrderType.value;
  if (!ot) {
    ElMessage.warning(tl('bank_not_found'));
    return false;
  }
  for (const f of dynamicFields.value) {
    if (isRequired(f) && !String(dynamicValues[f.name] ?? '').trim()) {
      ElMessage.warning(tl('please_fill_in_all_required_fields'));
      return false;
    }
  }
  if ('amount' in dynamicValues) {
    const amountStr = String(dynamicValues.amount ?? '');
    const amount = Number(amountStr) || 0;
    if (amount <= 0) {
      ElMessage.warning(tl('amount_is_out_of_range'));
      return false;
    }
    if (!/^\d+(\.\d{1,2})?$/.test(amountStr)) {
      ElMessage.warning(tl('amount_only_two_decimal_places'));
      return false;
    }
    const min = Number(ot.min_amount) || 0;
    const max = Number(ot.max_amount) || 0;
    if (min > 0 && amount < min) {
      ElMessage.warning(`${tl('amount_below_minimum')} (${min})`);
      return false;
    }
    if (max > 0 && amount > max) {
      ElMessage.warning(`${tl('amount_above_maximum')} (${max})`);
      return false;
    }
  }
  return true;
}

/**
 * 依回應 type 分流。
 * type 0 是 APIs 的「訂單沒有進到支付頁」：三方同步就回了結果，message 是要給代理看的原因。
 * 例如 TNGPIN 的 PIN 碼儲值，三方驗證失敗（PIN 長度不對）回 code 998、要留在原頁改了再送；
 * 三方受理、等回調決定成敗時回 code 999，訂單已建立，只需提示處理中。
 */
function handleResult(data: {
  code?: number | string;
  html?: string;
  message?: string;
  type?: number | string;
  url?: string;
}) {
  switch (Number(data.type)) {
    case 0: {
      const message = data.message || tl('error');
      if (Number(data.code) === 999) {
        example.invalid(message);
      } else {
        ElMessage.error(message);
      }
      break;
    }
    case 1: {
      if (data.url) window.location.href = data.url;
      break;
    }
    case 3: {
      const w = window.open('', '_blank');
      if (w && data.html) {
        w.document.write(data.html);
        w.document.close();
      }
      break;
    }
    case 4: {
      ElMessage.success(tl('success'));
      break;
    }
    case 9: {
      example.invalid(data.message ?? '');
      break;
    }
    case 11: {
      qrText.value = String(data.code ?? '');
      resultDialog.mode = 'qr';
      resultDialog.visible = true;
      break;
    }
    case 12: {
      resultDialog.image = data.url ?? '';
      resultDialog.mode = 'image';
      resultDialog.visible = true;
      break;
    }
    default: {
      ElMessage.error(tl('unsupported_deposit_type'));
    }
  }
}

async function handleSave() {
  if (!validate()) return;
  const ot = finalOrderType.value;
  if (!ot) return;
  submitting.value = true;
  try {
    const payload: Record<string, unknown> = {
      action: 'agent_self_deposit_create_order_fast',
      return_url: window.location.href,
      order_type_id: ot.order_type_id,
      ...dynamicValues,
    };
    if (ot.cash_flow_id) payload.cash_flow_id = ot.cash_flow_id;
    const res = await createDepositFastOrderApi(payload);
    if (Number(res.code) === 1000 || Number(res.code) === 200) {
      handleResult(res.data ?? {});
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    submitting.value = false;
  }
}

onMounted(async () => {
  try {
    const res = await getDepositFastOrderTypeListApi();
    if (Number(res.code) === 1000) {
      orderTypes.value = (res.data?.order_type_list ?? []).filter(
        (o) => !BLOCKED_CASH_FLOW_IDS.has(Number(o.cash_flow_id)),
      );
    }
  } catch {
    orderTypes.value = [];
  }
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
    :title="`${tl('menu_agent_self_deposit_withdrawal')} > ${tl('menu_agent_self_deposit_create_fast')}`"
    auto-content-height
    footer-class="border-t border-border"
  >
    <ElCard shadow="never">
      <ElForm label-width="120px" @submit.prevent="handleSave">
        <ElFormItem :label="tl('currency')">
          <ElSelect
            v-model="form.currency"
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
        <ElFormItem :label="tl('type')">
          <ElSelect
            v-model="form.type"
            :disabled="!form.currency"
            :placeholder="tl('please_choose')"
            class="!w-full"
            @change="onTypeChange"
          >
            <ElOption
              v-for="t in typeOptions"
              :key="t.order_type_id"
              :label="t.display_name"
              :value="t.order_type_id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="needBank" :label="tl('bank')">
          <ElSelect
            v-model="form.bank"
            :placeholder="tl('please_choose')"
            class="!w-full"
          >
            <ElOption
              v-for="b in bankOptions"
              :key="b.order_type_id"
              :label="b.display_name"
              :value="b.order_type_id"
            />
          </ElSelect>
        </ElFormItem>

        <ElFormItem
          v-for="f in dynamicFields"
          :key="f.name"
          :label="f.display || f.name"
          :required="isRequired(f)"
        >
          <ElInput
            v-model="dynamicValues[f.name]"
            :placeholder="f.placeholder"
            :type="f.type === 'number' ? 'number' : 'text'"
          />
        </ElFormItem>

        <div v-if="info" class="mt-2 rounded border p-4 text-sm">
          <div class="grid grid-cols-1 gap-1 md:grid-cols-2">
            <div>{{ tl('deposit_min_amount') }}：{{ info.min }}</div>
            <div>{{ tl('deposit_max_amount') }}：{{ info.max }}</div>
            <div>{{ tl('deposit_exchange_rate') }}：{{ info.rate }}</div>
            <div>{{ tl('deposit_net_amount') }}：{{ example.invalid }}</div>
            <div>{{ tl('change_balance') }}：{{ info.changeBalance }}</div>
            <div>{{ tl('after_balance') }}：{{ info.afterBalance }}</div>
          </div>
        </div>
      </ElForm>
    </ElCard>

    <template #footer>
      <div class="flex w-full gap-2">
        <ElButton :loading="submitting" type="primary" @click="handleSave">
          {{ tl('btn_update') }}
        </ElButton>
      </div>
    </template>

    <ElDialog
      v-model="resultDialog.visible"
      :title="tl('success')"
      width="380px"
    >
      <div class="flex justify-center">
        <img
          v-if="resultDialog.mode === 'qr'"
          :src="qrDataUrl"
          alt="qrcode"
          height="300"
          width="300"
        />
        <ElImage
          v-else-if="resultDialog.mode === 'image'"
          :src="resultDialog.image"
          style="max-width: 100%; max-height: 400px"
        />
      </div>
    </ElDialog>
  </Page>
</template>
