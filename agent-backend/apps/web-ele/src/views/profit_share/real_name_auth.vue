<script lang="ts" setup>
import type { ProfitShareBank } from '#/api';

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
  ElOption,
  ElSelect,
} from 'element-plus';

import {
  getAgentViewByIdApi,
  getCurrencyListApi,
  getProfitShareBankListApi,
  submitAgentRealNameAuthApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'ProfitShareRealNameAuth' });

const userStore = useUserStore();
const selfId = computed(() => Number((userStore.userInfo as any)?.id ?? 0));

const loading = ref(false);
const submitting = ref(false);
/** 0=可申請、1=已認證、2=申請中 */
const validateStatus = ref<number>(0);
const banks = ref<ProfitShareBank[]>([]);
const currencies = ref<Array<{ id: number | string; name: string }>>([]);

const form = reactive({
  bank_account: '',
  bank_id: '' as number | string,
  currency: '' as number | string,
  real_name: '',
});

/** 依選定幣別過濾銀行 */
const filteredBanks = computed(() =>
  banks.value.filter((b) => Number(b.currency_id) === Number(form.currency)),
);

/** 依 id 去重（後端偶爾會回傳同一筆重複多次） */
function dedupeById<T>(list: T[], idKey: keyof T) {
  const seen = new Set<string>();
  return list.filter((item) => {
    const key = String(item[idKey]);
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });
}

function syncBankByCurrency() {
  const first = filteredBanks.value[0];
  form.bank_id = first ? first.bank_id : '';
}

async function loadData() {
  loading.value = true;
  try {
    const [agent, bankList, currencyList] = await Promise.all([
      getAgentViewByIdApi(selfId.value),
      getProfitShareBankListApi().catch(() => []),
      getCurrencyListApi().catch(() => []),
    ]);
    validateStatus.value = Number((agent as any)?.realname_validate ?? 0);
    banks.value = dedupeById(bankList ?? [], 'bank_id');
    // 只保留有對應銀行的幣別
    currencies.value = dedupeById(
      (currencyList ?? []).filter((c) =>
        banks.value.some((b) => Number(b.currency_id) === Number(c.id)),
      ),
      'id',
    );
    if (currencies.value.length > 0) {
      form.currency = currencies.value[0]?.id ?? form.currency;
      syncBankByCurrency();
    }
  } finally {
    loading.value = false;
  }
}

function onAccountInput(v: string) {
  form.bank_account = v.replaceAll(/\D/g, '');
}

async function handleSubmit() {
  submitting.value = true;
  try {
    const res = await submitAgentRealNameAuthApi({
      bank_account: form.bank_account,
      bank_id: String(form.bank_id),
      real_name: form.real_name,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      loadData();
    } else if (Number(res.code) === 2002) {
      ElMessage.error(String(res.data ?? res.message));
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    submitting.value = false;
  }
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('menu_agent_profit_sharing_management')} > ${tl('realname_authentication')}`"
  >
    <ElCard
      v-loading="loading"
      class="mx-auto"
      shadow="never"
      style="max-width: 600px"
    >
      <div v-if="validateStatus === 1" class="py-12 text-center text-lg">
        {{ tl('realname_authenticated') }}
      </div>
      <div v-else-if="validateStatus === 2" class="py-12 text-center text-lg">
        {{ tl('realname_applied') }}
      </div>
      <ElForm v-else label-position="top" @submit.prevent="handleSubmit">
        <ElFormItem :label="tl('real_name')">
          <ElInput v-model="form.real_name" maxlength="60" />
        </ElFormItem>
        <ElFormItem :label="tl('currency')">
          <ElSelect
            v-model="form.currency"
            class="!w-full"
            @change="syncBankByCurrency"
          >
            <ElOption
              v-for="c in currencies"
              :key="c.id"
              :label="c.name"
              :value="c.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('bank_name')">
          <ElSelect v-model="form.bank_id" class="!w-full">
            <ElOption
              v-for="b in filteredBanks"
              :key="b.bank_id"
              :label="b.bank_name"
              :value="b.bank_id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('account_no')">
          <ElInput
            :model-value="form.bank_account"
            maxlength="40"
            @update:model-value="onAccountInput"
          />
        </ElFormItem>
        <div class="flex justify-end">
          <ElButton :loading="submitting" type="success" @click="handleSubmit">
            {{ tl('submit') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>
  </Page>
</template>
