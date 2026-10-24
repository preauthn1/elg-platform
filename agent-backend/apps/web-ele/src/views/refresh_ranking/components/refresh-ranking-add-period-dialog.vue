<script lang="ts" setup>
import { computed, reactive, ref } from 'vue';

import dayjs from 'dayjs';
import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
} from 'element-plus';

import {
  addRefreshRankingPeriodApi,
  getRefreshRankingEventInfoApi,
} from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const eventId = ref('');
const startTime = ref('');
const saving = ref(false);

const form = reactive({
  period: '1',
  point: '',
});

/** 結束時間 = 起始日 +（期數-1）天，當天 23:59:59 */
const endTime = computed(() => {
  if (!startTime.value) return '';
  const days = Math.max(0, Number(form.period) - 1) || 0;
  return dayjs(startTime.value).add(days, 'day').format('YYYY-MM-DD 23:59:59');
});

async function handleSave() {
  if (!form.point) {
    ElMessage.warning(tl('before_point'));
    return;
  }
  saving.value = true;
  try {
    const res = await addRefreshRankingPeriodApi({
      event_id: eventId.value,
      period: form.period,
      point: form.point,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}

async function open(id: number | string) {
  eventId.value = String(id ?? '');
  form.period = '1';
  form.point = '';
  startTime.value = '';
  visible.value = true;
  const res = await getRefreshRankingEventInfoApi(eventId.value).catch(
    () => null,
  );
  if (res && Number(res.code) === 1000 && res.data) {
    startTime.value = dayjs(res.data)
      .add(8, 'hour')
      .add(1, 'second')
      .format('YYYY-MM-DD HH:mm:ss');
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('menu_refresh_ranking_list')} - ${tl('add')}${tl('period')}`"
    width="800"
  >
    <ElForm label-position="top" @submit.prevent="handleSave">
      <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
        <ElFormItem>
          <template #label>
            <span class="text-red-500">*</span> {{ tl('before_point') }}
          </template>
          <ElInput v-model="form.point" type="number" />
        </ElFormItem>
        <ElFormItem>
          <template #label>
            <span class="text-red-500">*</span> {{ tl('label_start_time') }}
          </template>
          <ElInput :model-value="startTime" disabled />
        </ElFormItem>
        <ElFormItem>
          <template #label>
            <span class="text-red-500">*</span> {{ tl('period') }}
          </template>
          <ElInput v-model="form.period" type="number" />
        </ElFormItem>
        <ElFormItem>
          <template #label>
            <span class="text-red-500">*</span> {{ tl('label_end_time') }}
          </template>
          <ElInput :model-value="endTime" disabled />
        </ElFormItem>
      </div>
    </ElForm>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="saving" type="success" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
