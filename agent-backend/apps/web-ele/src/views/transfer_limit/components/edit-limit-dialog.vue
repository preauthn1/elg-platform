<script lang="ts" setup>
import type { TransferLimitRow } from '#/api';

import { computed, reactive, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
} from 'element-plus';

import { getTransferLimitInfoApi, updateTransferLimitApi } from '#/api';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const loading = ref(false);
const submitting = ref(false);
const info = ref<TransferLimitRow>({ id: '' });
const form = reactive({ amount: '', memo: '' });

const limitText = computed(() => amountThousands(info.value.limit, 2));

async function open(id: number | string) {
  form.amount = '';
  form.memo = '';
  info.value = { id };
  visible.value = true;
  loading.value = true;
  try {
    const res = await getTransferLimitInfoApi(id);
    if (Number(res.code) === 1000) info.value = res.data ?? { id };
  } finally {
    loading.value = false;
  }
}

/** type 0=加、1=減 */
async function adjust(type: number) {
  const amount = Number(form.amount);
  if (!form.amount || Number.isNaN(amount)) {
    ElMessage.warning(tl('required_column'));
    return;
  }
  const base = Number(info.value.limit);
  const result = type === 0 ? base + amount : base - amount;
  try {
    await ElMessageBox.confirm(
      `${tl('limit_amount')}: ${amountThousands(result, 2)}`,
      type === 0 ? tl('plus') : tl('reduce'),
      { type: 'warning' },
    );
  } catch {
    return;
  }
  submitting.value = true;
  try {
    const res = await updateTransferLimitApi({
      amount: form.amount,
      id: info.value.id,
      memo: form.memo,
      type,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    submitting.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('menu_agent_transfer_limit_management')"
    width="560"
  >
    <ElForm v-loading="loading" label-width="auto">
      <ElFormItem :label="tl('group_name')">
        <span>{{ info.name }}</span>
      </ElFormItem>
      <ElFormItem :label="tl('limit_amount')">
        <span>{{ limitText }}</span>
      </ElFormItem>
      <ElFormItem :label="tl('adjustment_amount')" required>
        <ElInput v-model="form.amount" />
      </ElFormItem>
      <ElFormItem :label="tl('memo')">
        <ElInput v-model="form.memo" type="textarea" />
      </ElFormItem>
    </ElForm>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="submitting" type="danger" @click="adjust(1)">
        {{ tl('reduce') }}
      </ElButton>
      <ElButton :loading="submitting" type="success" @click="adjust(0)">
        {{ tl('plus') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
