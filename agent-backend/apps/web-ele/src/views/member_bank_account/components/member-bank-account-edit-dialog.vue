<script lang="ts" setup>
import { reactive, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
} from 'element-plus';

import {
  deleteMemberBankAccountApi,
  getMemberBankAccountViewApi,
  getWithdrawalBankListApi,
  saveMemberBankAccountApi,
} from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const submitting = ref(false);
const deleting = ref(false);
const banks = ref<Array<{ id: number | string; name: string }>>([]);
let banksLoaded = false;

const form = reactive({
  account_name: '',
  account_no: '',
  agent_username: '',
  bank_id: '' as number | string,
  binding_time: '',
  id: '' as number | string,
  member_username: '',
});

/** 銀行下拉（withdrawal/showBankList） */
async function loadBanks() {
  if (banksLoaded) return;
  const res = await getWithdrawalBankListApi({
    name: '',
    page: 1,
    per_page: 10_000,
    status: 1,
    type: -1,
  });
  if (Number(res.code) === 1000) {
    banks.value = (res.data ?? []).map((b: any) => ({
      id: b.id ?? b.bank_id,
      name: b.name ?? b.bank_name,
    }));
    banksLoaded = true;
  }
}

async function open(id: number | string) {
  visible.value = true;
  Object.assign(form, {
    account_name: '',
    account_no: '',
    agent_username: '',
    bank_id: '',
    binding_time: '',
    id,
    member_username: '',
  });
  await loadBanks();
  const res = await getMemberBankAccountViewApi(id);
  if (Number(res.code) === 1000 && res.data) {
    const d = res.data;
    form.agent_username = d.agent_username ?? '';
    form.member_username = d.member_username ?? '';
    form.bank_id = d.bank_id ?? '';
    form.account_name = d.account_name ?? '';
    form.account_no = d.account_no ?? '';
    form.binding_time = d.binding_time ?? '';
  } else {
    ElMessage.error(res.message || tl('failure'));
    visible.value = false;
  }
}

async function handleSave() {
  submitting.value = true;
  try {
    const res = await saveMemberBankAccountApi({
      account_name: form.account_name.trim(),
      account_no: form.account_no.trim(),
      bank_id: form.bank_id,
      id: form.id,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('operation_success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res.message || tl('operation_failed'));
    }
  } finally {
    submitting.value = false;
  }
}

async function handleDelete() {
  try {
    await ElMessageBox.confirm(tl('are_you_sure'), '', { type: 'warning' });
  } catch {
    return;
  }
  deleting.value = true;
  try {
    const res = await deleteMemberBankAccountApi(form.id);
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('operation_success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res.message || tl('operation_failed'));
    }
  } finally {
    deleting.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('edit')} - ${tl('bank_card_management')}`"
    width="600"
  >
    <ElForm label-position="top">
      <ElFormItem :label="tl('agent_account')">
        <ElInput :model-value="form.agent_username" disabled />
      </ElFormItem>
      <ElFormItem :label="tl('username')">
        <ElInput :model-value="form.member_username" disabled />
      </ElFormItem>
      <ElFormItem :label="tl('bank_name')" required>
        <ElSelect v-model="form.bank_id" class="!w-full" filterable>
          <ElOption
            v-for="b in banks"
            :key="b.id"
            :label="b.name"
            :value="b.id"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem :label="tl('account_name')" required>
        <ElInput v-model="form.account_name" />
      </ElFormItem>
      <ElFormItem :label="tl('account_no')" required>
        <ElInput v-model="form.account_no" />
      </ElFormItem>
      <ElFormItem :label="tl('binding_time')">
        <ElInput :model-value="form.binding_time" disabled />
      </ElFormItem>
    </ElForm>
    <template #footer>
      <div class="flex items-center">
        <ElButton
          :loading="deleting"
          class="mr-auto"
          type="danger"
          @click="handleDelete"
        >
          {{ tl('delete') }}
        </ElButton>
        <ElButton @click="visible = false">{{ tl('close') }}</ElButton>
        <ElButton :loading="submitting" type="primary" @click="handleSave">
          {{ tl('save') }}
        </ElButton>
      </div>
    </template>
  </ElDialog>
</template>
