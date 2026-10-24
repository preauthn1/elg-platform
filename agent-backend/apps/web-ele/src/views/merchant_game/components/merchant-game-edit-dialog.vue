<script lang="ts" setup>
import { reactive, ref } from 'vue';

import {
  ElButton,
  ElCheckbox,
  ElDialog,
  ElFormItem,
  ElInput,
  ElMessage,
  ElRadio,
  ElRadioGroup,
} from 'element-plus';

import { getMerchantGameApi, saveMerchantGameApi } from '#/api/merchant-game';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const vigId = ref<number | string>('');
const groupId = ref<number | string>('');
const vendorName = ref('');
const gameName = ref('');
const submitting = ref(false);

const form = reactive({
  bettable: false,
  calc_flow_limit: false,
  is_hot: false,
  is_new: false,
  join_bet_event: false,
  join_win_event: false,
  sort: '' as number | string,
  status: 1 as number | string,
  valid_bet_rate: '' as number | string,
});

function resetForm() {
  form.status = 1;
  form.is_new = false;
  form.is_hot = false;
  form.join_bet_event = false;
  form.join_win_event = false;
  form.calc_flow_limit = false;
  form.bettable = false;
  form.sort = '';
  form.valid_bet_rate = '';
}

async function open(
  vigIdParam: number | string,
  groupIdParam: number | string,
) {
  vigId.value = vigIdParam;
  groupId.value = groupIdParam;
  vendorName.value = '';
  gameName.value = '';
  resetForm();
  visible.value = true;

  const res = await getMerchantGameApi({
    group_id: groupId.value || undefined,
    vig_id: vigId.value,
  });
  if (Number(res.code) !== 1000 || !res.data) {
    ElMessage.error(res.message || tl('failure'));
    visible.value = false;
    return;
  }
  const d = res.data;
  vendorName.value = d.vendor_name ?? '';
  gameName.value = d.game_name ?? '';
  form.status = Number(d.status) === 1 ? 1 : 0;
  form.is_new = Number(d.is_new) === 1;
  form.is_hot = Number(d.is_hot) === 1;
  form.join_bet_event = Number(d.join_bet_event) === 1;
  form.join_win_event = Number(d.join_win_event) === 1;
  form.calc_flow_limit = Number(d.calc_flow_limit) === 1;
  form.bettable = Number(d.bettable) === 1;
  form.sort = d.sort ?? '';
  form.valid_bet_rate = d.valid_bet_rate ?? '';
}

async function handleSave() {
  submitting.value = true;
  try {
    const res = await saveMerchantGameApi({
      bettable: form.bettable ? 1 : 0,
      calc_flow_limit: form.calc_flow_limit ? 1 : 0,
      group_id: groupId.value || undefined,
      is_hot: form.is_hot ? 1 : 0,
      is_new: form.is_new ? 1 : 0,
      join_bet_event: form.join_bet_event ? 1 : 0,
      join_win_event: form.join_win_event ? 1 : 0,
      sort: form.sort,
      status: Number(form.status) === 1 ? 1 : 0,
      valid_bet_rate: form.valid_bet_rate,
      vig_id: vigId.value,
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

defineExpose({ open });
</script>

<template>
  <ElDialog v-model="visible" :title="vendorName" width="760">
    <div class="grid grid-cols-1 gap-6 md:grid-cols-4">
      <div class="flex flex-col gap-3">
        <div>
          <div class="mb-1 text-gray-500">{{ tl('game_name') }}</div>
          <div class="font-bold">{{ gameName }}</div>
        </div>
        <ElFormItem :label="tl('status')">
          <ElRadioGroup v-model="form.status">
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
      </div>
      <div class="flex flex-col gap-3">
        <ElCheckbox v-model="form.is_new">{{ tl('new_game') }}</ElCheckbox>
        <ElCheckbox v-model="form.join_win_event">
          {{ tl('win_event') }}
        </ElCheckbox>
      </div>
      <div class="flex flex-col gap-3">
        <ElCheckbox v-model="form.is_hot">{{ tl('hot_game') }}</ElCheckbox>
        <ElCheckbox v-model="form.calc_flow_limit">
          {{ tl('flow_limit') }}
        </ElCheckbox>
        <ElFormItem :label="tl('sorts')" class="!mb-0">
          <ElInput v-model="form.sort" />
        </ElFormItem>
      </div>
      <div class="flex flex-col gap-3">
        <ElCheckbox v-model="form.join_bet_event">
          {{ tl('join_bet_event') }}
        </ElCheckbox>
        <ElCheckbox v-model="form.bettable">{{ tl('bettable') }}</ElCheckbox>
        <ElFormItem
          :label="tl('agent_unlimit_valid_bet_rate')"
          class="!mb-0"
          label-position="top"
        >
          <ElInput v-model="form.valid_bet_rate" />
        </ElFormItem>
      </div>
    </div>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="submitting" type="primary" @click="handleSave">
        {{ tl('save') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
