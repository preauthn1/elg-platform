<script lang="ts" setup>
import { reactive, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
} from 'element-plus';

import { editWithdrawalBankNameApi, getWithdrawalBankNameApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'BankNameEditDialog' });

const emit = defineEmits<{ saved: [] }>();

const visible = ref(false);
const loading = ref(false);
const saving = ref(false);
const bankId = ref<number | string>('');
const defaultName = ref('');
const languages = ref<Array<{ id: number; name: string }>>([]);
const names = reactive<Record<number, string>>({});

async function open(id: number | string, name: string) {
  bankId.value = id;
  defaultName.value = name;
  languages.value = [];
  for (const k of Object.keys(names)) delete names[Number(k)];
  visible.value = true;
  loading.value = true;
  try {
    const res = await getWithdrawalBankNameApi(id);
    if (Number(res.code) === 1000) {
      languages.value = (res.data ?? []).map((r) => ({
        id: r.id,
        name: r.name,
      }));
      for (const r of res.data ?? []) names[r.id] = r.bank_name ?? '';
    }
  } finally {
    loading.value = false;
  }
}

async function handleSave() {
  saving.value = true;
  try {
    const bank: Record<number, string> = {};
    for (const lang of languages.value) bank[lang.id] = names[lang.id] ?? '';
    const res = await editWithdrawalBankNameApi({ bank, id: bankId.value });
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
    :title="`${tl('bank_management')} > ${tl('edit_bank_name')}`"
    width="640"
    align-center
  >
    <ElForm v-loading="loading" label-width="120px">
      <ElFormItem :label="tl('default')">
        <span>{{ defaultName }}</span>
      </ElFormItem>
      <ElFormItem v-for="lang in languages" :key="lang.id" :label="lang.name">
        <ElInput v-model="names[lang.id]" />
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
