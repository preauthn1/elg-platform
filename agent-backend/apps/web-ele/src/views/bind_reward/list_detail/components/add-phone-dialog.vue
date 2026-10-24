<script lang="ts" setup>
import { ref } from 'vue';

import { ElButton, ElDialog, ElInput, ElMessage } from 'element-plus';

import { createBindRewardDetailApi } from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const MAX_PHONE_COUNT = 5000;

const visible = ref(false);
const saving = ref(false);
const phoneNumbers = ref('');
let bindRewardId: number | string = '';

function open(listId: number | string) {
  bindRewardId = listId;
  phoneNumbers.value = '';
  visible.value = true;
}

async function handleSave() {
  const commaCount = (phoneNumbers.value.match(/,/g) ?? []).length;
  if (commaCount > MAX_PHONE_COUNT) {
    ElMessage.warning(`Phone numbers exceed limit, max is ${MAX_PHONE_COUNT}`);
    return;
  }
  saving.value = true;
  try {
    const res = await createBindRewardDetailApi({
      bind_reward_id: bindRewardId,
      phone_numbers: phoneNumbers.value,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(String(res.data ?? res.message));
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
    :title="tl('bind_reward_list_detail_add_phone')"
    width="640"
  >
    <ElInput v-model="phoneNumbers" :rows="10" type="textarea" />
    <div class="text-muted-foreground mt-3 text-sm">
      <div class="font-medium">{{ tl('tips') }}：</div>
      <ul class="list-disc pl-5">
        <li>{{ tl('bind_reward_list_detail_add_phone.hint1') }}</li>
        <li>{{ tl('bind_reward_list_detail_add_phone.hint2') }}</li>
      </ul>
    </div>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
