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
  getWithdrawalCurrencyListApi,
  updateWithdrawalRateByCurrencyApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'CurrencyRateDialog' });

const visible = ref(false);
const saving = ref(false);
const currencies = ref<Array<{ code?: string; id: number; name?: string }>>([]);

const form = reactive({
  currency: '' as number | string,
  rate: '',
  status: '2' as number | string,
});

async function open() {
  visible.value = true;
  form.rate = '';
  form.status = '2';
  if (currencies.value.length === 0) {
    currencies.value =
      (await getWithdrawalCurrencyListApi().catch(() => [])) ?? [];
  }
  if (currencies.value.length > 0 && !form.currency) {
    form.currency = currencies.value[0]?.id ?? form.currency;
  }
}

async function handleSave() {
  saving.value = true;
  try {
    const res = await updateWithdrawalRateByCurrencyApi({
      currency: form.currency,
      rate: form.rate,
      status: form.status,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
    } else {
      ElMessage.error(res.message);
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
    :title="tl('currency_rate_manage')"
    width="640"
    align-center
  >
    <ElForm :model="form" label-position="top">
      <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
        <ElFormItem :label="tl('currency')">
          <ElSelect v-model="form.currency" class="w-full">
            <ElOption
              v-for="c in currencies"
              :key="c.id"
              :label="c.code"
              :value="c.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="`${tl('rate')} (${tl('bulk_info')})`">
          <ElInput v-model="form.rate" :placeholder="tl('rate')" />
        </ElFormItem>
      </div>
      <ElFormItem :label="tl('status')">
        <ElRadioGroup v-model="form.status">
          <ElRadio value="2">{{ tl('no_adjustment') }}</ElRadio>
          <ElRadio value="1">{{ tl('bulk') }}{{ tl('enable') }}</ElRadio>
          <ElRadio value="0">{{ tl('bulk') }}{{ tl('disable') }}</ElRadio>
        </ElRadioGroup>
      </ElFormItem>
    </ElForm>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('submit') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
