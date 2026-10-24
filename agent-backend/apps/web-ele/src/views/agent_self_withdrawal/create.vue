<script lang="ts" setup>
import type { WithdrawalBankRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
} from 'element-plus';

import {
  createAgentSelfWithdrawalOrderApi,
  getAgentSelfWithdrawalTodayLimitApi,
  getAgentViewApi,
  getCustomizedSettingApi,
  getWithdrawalBankListApi,
} from '#/api';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

defineOptions({ name: 'AgentSelfWithdrawalCreate' });

const userStore = useUserStore();

const banks = ref<WithdrawalBankRow[]>([]);
const baseCurrencyCode = ref('MYR');
const realName = ref('');
const balance = ref(0);
const enableBsb = ref(false);
const bsbAlias = ref('BSB Code');
const enableModifyRealName = ref(false);
const withdrawalMinAmount = ref(0);

const today = reactive({
  dailyTimes: 0,
  dailyAmount: 0,
  currentTimes: 0,
  currentAmount: 0,
});

const form = reactive({
  currency_id: '' as number | string,
  bank_type: '' as number | string,
  bank_id: '' as number | string,
  bsb_code: '',
  account_no: '',
  real_name: '',
  amount: '' as number | string,
});

const submitting = ref(false);

const round = (v: number, d: number) =>
  Number(`${Math.round(Number(`${v}e${d}`))}e-${d}`);
const fmt = (v: number | string) => amountThousands(v, 2);

/** 幣別下拉（currency_id → currency_name，去重）。
 *  對齊舊版 Object.entries 行為：數字 id 升序在前，非數字（如 currency_id=null）排最後。 */
const currencyOptions = computed(() => {
  const map = new Map<string, string>();
  banks.value.forEach((b) => {
    map.set(String(b.currency_id), String(b.currency_name));
  });
  return [...map.entries()]
    .map(([id, name]) => ({ id, name }))
    .toSorted((a, b) => {
      const na = Number(a.id);
      const nb = Number(b.id);
      const fa = Number.isFinite(na);
      const fb = Number.isFinite(nb);
      if (fa && fb) return na - nb;
      if (fa) return -1;
      if (fb) return 1;
      return 0;
    });
});

/** 銀行類型下拉（依所選幣別去重） */
const typeOptions = computed(() => {
  const set = new Set<string>();
  banks.value
    .filter((b) => String(b.currency_id) === String(form.currency_id))
    .forEach((b) => set.add(String(b.bank_type)));
  return [...set];
});

/** 銀行下拉（依所選幣別＋類型，依 bank_id 去重）。
 *  後端 showBankList 的多語系 leftJoin 若同語系有重複列，同一銀行會出現多次，這裡去重防呆。 */
const bankOptions = computed(() => {
  const seen = new Set<string>();
  return banks.value.filter((b) => {
    if (
      String(b.currency_id) !== String(form.currency_id) ||
      String(b.bank_type) !== String(form.bank_type)
    ) {
      return false;
    }
    const key = String(b.bank_id);
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });
});

const selectedBank = computed(() =>
  banks.value.find(
    (b) =>
      String(b.currency_id) === String(form.currency_id) &&
      String(b.bank_type) === String(form.bank_type) &&
      String(b.bank_id) === String(form.bank_id),
  ),
);

/** 單筆範圍下限：min 設定>0 取 max(設定/匯率, 銀行下限)，否則銀行下限 */
function bankMinAmount(b: WithdrawalBankRow) {
  const rate = Number(b.bank_rate) || 1;
  return withdrawalMinAmount.value > 0
    ? Math.max(
        round(withdrawalMinAmount.value / rate, 4),
        round(Number(b.min_amount), 4),
      )
    : round(Number(b.min_amount), 4);
}

const info = computed(() => {
  const b = selectedBank.value;
  if (!b) return null;
  const code = String(b.currency_code ?? '');
  const rate = Number(b.bank_rate) || 1;
  const amount = Number(form.amount) || 0;
  const tax =
    Number(b.tax_type) === 0 ? Number(b.bank_tax) : Number(b.bank_tax) * amount;
  const changeBalance = round(amount * rate, 4);
  const afterBalance = round(balance.value - changeBalance, 2);
  return {
    code,
    singleRange: `${fmt(bankMinAmount(b))} - ${fmt(Number(b.max_amount))} ${code}`,
    exchangeCurrency: code,
    exchangeRate: `1${code} = ${round(rate, 4)}${baseCurrencyCode.value}`,
    withdrawalAmount: `${fmt(changeBalance)} ${baseCurrencyCode.value}`,
    fee: `${fmt(tax)} ${baseCurrencyCode.value}`,
    arrival: `${fmt(amount)} ${code}`,
    deduct: `-${fmt(changeBalance)} ${baseCurrencyCode.value}`,
    after: `${fmt(afterBalance)} ${baseCurrencyCode.value}`,
  };
});

const todayTimesText = computed(() =>
  today.dailyTimes > 0
    ? `${today.currentTimes}/${today.dailyTimes}`
    : tl('unlimited'),
);
const todayAmountText = computed(() =>
  today.dailyAmount > 0
    ? `${fmt(today.currentAmount)}/${fmt(today.dailyAmount)}`
    : tl('unlimited'),
);
const beforeBalanceText = computed(
  () => `${fmt(balance.value)} ${baseCurrencyCode.value}`,
);

/** 幣別變更→重置類型/銀行 */
function onCurrencyChange() {
  form.bank_type = '';
  form.bank_id = '';
}
function onTypeChange() {
  form.bank_id = '';
}
function onBankChange() {
  form.amount = '';
}

function validate(): boolean {
  if (/\s/.test(form.account_no)) {
    ElMessage.warning(tl('account_no_cannot_contain_spaces'));
    return false;
  }
  const b = selectedBank.value;
  if (!b) {
    ElMessage.warning(tl('bank_not_found'));
    return false;
  }
  const amountStr = String(form.amount);
  const amount = Number(form.amount);
  const min = bankMinAmount(b);
  const max = Number(b.max_amount);
  if (amount < min || (max !== 0 && amount > max)) {
    ElMessage.warning(
      `${tl('amount_is_out_of_range')} ${fmt(min)} ~ ${fmt(max)}`,
    );
    return false;
  }
  if (!/^\d+(\.\d{1,2})?$/.test(amountStr)) {
    ElMessage.warning(tl('amount_only_two_decimal_places'));
    return false;
  }
  const changeBalance = amount * (Number(b.bank_rate) || 1);
  if (changeBalance > balance.value) {
    ElMessage.warning(tl('balance_not_enough'));
    return false;
  }
  return true;
}

async function handleSave() {
  if (!validate()) return;
  try {
    await ElMessageBox.confirm(
      `${tl('amount')}: ${fmt(form.amount)}`,
      tl('btn_update'),
    );
  } catch {
    return;
  }
  submitting.value = true;
  try {
    const res = await createAgentSelfWithdrawalOrderApi({
      account_no: form.account_no,
      amount: form.amount,
      bank_id: form.bank_id,
      bsb_code: form.bsb_code,
      real_name: form.real_name,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('withdrawal_order_created_successfully'));
      form.amount = '';
    } else if (String(res.code) === '2002') {
      ElMessage.warning(String(res.data ?? ''));
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    submitting.value = false;
  }
}

onMounted(async () => {
  try {
    const res: any = await getWithdrawalBankListApi({ per_page: 1000 });
    if (Number(res.code) === 1000) {
      // currency_id 10 為 USDT，不開放代理自助提款
      banks.value = (res.data ?? []).filter(
        (b: WithdrawalBankRow) => Number(b.currency_id) !== 10,
      );
      baseCurrencyCode.value = res.base_currency_code || 'MYR';
    }
  } catch {
    banks.value = [];
  }
  getCustomizedSettingApi()
    .then((settings) => {
      const map = new Map(
        (settings ?? []).map((s: any) => [Number(s.setting_key), s.value]),
      );
      enableBsb.value = Number(map.get(4011)) === 1;
      bsbAlias.value = (map.get(3015) as string) || 'BSB Code';
      enableModifyRealName.value = Number(map.get(5007)) === 1;
      withdrawalMinAmount.value = Number(map.get(5008)) || 0;
    })
    .catch(() => {});
  const id = Number((userStore.userInfo as any)?.id ?? 0);
  if (id) {
    getAgentViewApi(id)
      .then((v: any) => {
        realName.value = v?.real_name ?? '';
        form.real_name = v?.real_name ?? '';
        balance.value = Number(v?.balance) || 0;
      })
      .catch(() => {});
  }
  getAgentSelfWithdrawalTodayLimitApi()
    .then((res) => {
      const d = res.data ?? {};
      today.dailyTimes = Number(d.dailyWithdrawalTimes) || 0;
      today.dailyAmount = Number(d.dailyWithdrawalAmount) || 0;
      today.currentTimes = Number(d.currentWithdrawalTimes) || 0;
      today.currentAmount = Number(d.currentWithdrawalAmount) || 0;
    })
    .catch(() => {});
});

/** 真實姓名是否唯讀（5007 關閉且已有姓名時唯讀） */
const realNameReadonly = computed(
  () => !enableModifyRealName.value && !!realName.value,
);
</script>

<template>
  <Page
    :title="`${tl('menu_agent_self_deposit_withdrawal')} > ${tl('menu_agent_self_withdrawal_create')}`"
    auto-content-height
    footer-class="border-t border-border"
  >
    <ElCard shadow="never">
      <ElForm label-width="120px" @submit.prevent="handleSave">
        <ElFormItem :label="tl('currency')">
          <ElSelect
            v-model="form.currency_id"
            :placeholder="tl('please_choose')"
            class="!w-full"
            @change="onCurrencyChange"
          >
            <ElOption
              v-for="c in currencyOptions"
              :key="c.id"
              :label="c.name"
              :value="c.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('type')">
          <ElSelect
            v-model="form.bank_type"
            :disabled="!form.currency_id"
            :placeholder="tl('please_choose')"
            class="!w-full"
            @change="onTypeChange"
          >
            <ElOption v-for="t in typeOptions" :key="t" :label="t" :value="t" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('bank')">
          <ElSelect
            v-model="form.bank_id"
            :disabled="!form.bank_type"
            :placeholder="tl('please_choose')"
            class="!w-full"
            @change="onBankChange"
          >
            <ElOption
              v-for="b in bankOptions"
              :key="b.bank_id"
              :label="b.bank_name"
              :value="b.bank_id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="enableBsb" :label="bsbAlias">
          <ElInput v-model="form.bsb_code" />
        </ElFormItem>
        <ElFormItem :label="tl('account_no')">
          <ElInput v-model="form.account_no" />
        </ElFormItem>
        <ElFormItem :label="tl('real_name')">
          <ElInput v-model="form.real_name" :readonly="realNameReadonly" />
        </ElFormItem>
        <ElFormItem :label="tl('amount')">
          <ElInput v-model="form.amount" type="number" />
        </ElFormItem>

        <div v-if="info" class="mt-2 rounded border p-4 text-sm">
          <div class="mb-2 font-semibold">
            {{ tl('withdrawal_rule_header') }}
          </div>
          <div class="grid grid-cols-1 gap-1 md:grid-cols-2">
            <div>
              {{ tl('today_withdrawal_times_limit') }}：{{ todayTimesText }}
            </div>
            <div>
              {{ tl('today_withdrawal_amount_limit') }}：{{ todayAmountText }}
            </div>
            <div>
              {{ tl('withdrawal_single_range') }}：{{ info.singleRange }}
            </div>
          </div>
          <div class="mb-2 mt-3 font-semibold">
            {{ tl('exchange_rate_fee_header') }}
          </div>
          <div class="grid grid-cols-1 gap-1 md:grid-cols-2">
            <div>
              {{ tl('withdrawal_apply_amount') }}：{{ info.withdrawalAmount }}
            </div>
            <div>
              {{ tl('withdrawal_exchange_currency') }}：{{
                info.exchangeCurrency
              }}
            </div>
            <div>
              {{ tl('current_exchange_rate') }}：{{ info.exchangeRate }}
            </div>
            <div>{{ tl('handling_fee') }}：{{ info.fee }}</div>
            <div>{{ tl('withdrawal_arrival_amount') }}：{{ info.arrival }}</div>
          </div>
          <div class="mb-2 mt-3 font-semibold">
            {{ tl('balance_change_header') }}
          </div>
          <div class="grid grid-cols-1 gap-1 md:grid-cols-2">
            <div>
              {{ tl('withdrawal_before_balance') }}：{{ beforeBalanceText }}
            </div>
            <div>{{ tl('withdrawal_apply_amount') }}：{{ info.deduct }}</div>
            <div>{{ tl('withdrawal_after_balance') }}：{{ info.after }}</div>
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
  </Page>
</template>
