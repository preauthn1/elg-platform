<script lang="ts" setup>
import { computed, reactive, ref } from 'vue';

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

import { getVipRewardDetailApi, saveVipRewardApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'VipEditRewardDialog' });

const emit = defineEmits<{ saved: [] }>();

interface OpenParams {
  /** vip_content_reward_id；0 表示尚未設定（新增） */
  id: number | string;
  name: string;
  vip_content_id: number | string;
  vip_content_name: string;
  vip_reward_id: number | string;
}

const visible = ref(false);
const loading = ref(false);
const saving = ref(false);

const meta = reactive({
  id: 0 as number | string,
  name: '',
  vip_content_id: '' as number | string,
  vip_content_name: '',
  vip_reward_id: '' as number | string,
});

const form = reactive({
  condition: '0',
  expired_hour: '',
  flow_limit_multiple: '',
  max_point: '',
  point_type: '1' as number | string,
  reward_point: '',
  status: '1' as number | string,
  /** 0=金額、1=百分比 */
  type: 0 as number | string,
});

/** vip_reward_id==8 才可填兌換點數條件 */
const showCondition = computed(() => Number(meta.vip_reward_id) === 8);
/** vip_reward_id==7 不可填獎勵點數 */
const hideRewardPoint = computed(() => Number(meta.vip_reward_id) === 7);
/** 實體獎勵不可填點數 */
const isEntity = computed(() => Number(form.point_type) === 3);

async function open(params: OpenParams) {
  meta.id = params.id;
  meta.name = params.name;
  meta.vip_content_id = params.vip_content_id;
  meta.vip_content_name = params.vip_content_name;
  meta.vip_reward_id = params.vip_reward_id;

  form.condition = '0';
  form.expired_hour = '';
  form.flow_limit_multiple = '';
  form.max_point = '';
  form.point_type = '1';
  form.reward_point = '';
  form.status = '1';
  form.type = 0;

  visible.value = true;

  if (Number(params.id) !== 0) {
    loading.value = true;
    try {
      const res = await getVipRewardDetailApi(params.id);
      if (Number(res.code) === 1000 && res.data) {
        const d = res.data;
        meta.id = d.vip_content_reward_id ?? params.id;
        meta.vip_content_id = d.vip_content_id ?? params.vip_content_id;
        meta.vip_reward_id = d.vip_reward_id ?? params.vip_reward_id;
        meta.name = d.vip_reward_name ?? params.name;
        form.condition = String(d.condition ?? '0');
        form.point_type = String(d.point_type ?? 1);
        form.type = Number(d.type ?? 0);
        form.reward_point =
          Number(d.type) === 0
            ? String(d.point ?? '')
            : String(Number(d.point_percentage) * 100);
        form.expired_hour = String(d.expired_hour ?? '');
        form.flow_limit_multiple = String(d.flow_limit_multiple ?? '');
        form.max_point = String(d.max_point ?? '');
        form.status = String(d.status ?? 1);
      }
    } finally {
      loading.value = false;
    }
  }
}

function onPointTypeChange() {
  if (Number(form.point_type) === 3) {
    form.reward_point = '0';
    form.type = 0;
  }
}

async function handleSave() {
  saving.value = true;
  try {
    const payload: Record<string, any> = {
      condition: showCondition.value ? form.condition : '0',
      expired_hour: form.expired_hour,
      flow_limit_multiple: form.flow_limit_multiple,
      id: meta.id,
      max_point: form.max_point,
      point_type: form.point_type,
      status: form.status,
      vip_content_id: meta.vip_content_id,
      vip_reward_id: meta.vip_reward_id,
    };
    if (hideRewardPoint.value) {
      // vip_reward_id==7：點數固定、type=2
      payload.reward_point = form.reward_point;
      payload.type = 2;
    } else {
      payload.reward_point = form.reward_point;
      payload.type = form.type;
    }
    const res = await saveVipRewardApi(payload);
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
    :title="`${tl('reward')} > ${tl('edit')}`"
    width="720"
    align-center
  >
    <div class="mb-3">
      <b>{{ tl('name') }}</b
      >：{{ meta.vip_content_name }} ({{ meta.vip_content_id }})
    </div>
    <ElForm
      v-loading="loading"
      :model="form"
      label-position="top"
      @submit.prevent="handleSave"
    >
      <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
        <ElFormItem :label="tl('bind_reward_name')">
          <span class="text-green-600">{{ meta.name }}</span>
        </ElFormItem>
        <ElFormItem :label="tl('event_reward_points')">
          <ElInput v-if="showCondition" v-model="form.condition" />
          <span v-else><b>-</b></span>
        </ElFormItem>
        <ElFormItem :label="tl('reward_type')">
          <ElSelect
            v-model="form.point_type"
            class="w-full"
            @change="onPointTypeChange"
          >
            <ElOption :label="tl('platform_point')" value="1" />
            <ElOption :label="tl('rolling_chip')" value="2" />
            <ElOption :label="tl('entity_reward')" value="3" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('reward_point')">
          <span v-if="hideRewardPoint"><b>-</b></span>
          <div v-else class="flex w-full items-center gap-2">
            <ElInput
              v-model="form.reward_point"
              :disabled="isEntity"
              class="flex-1"
            />
            <ElRadioGroup v-model="form.type" :disabled="isEntity">
              <ElRadio :value="0">$</ElRadio>
              <ElRadio :value="1">%</ElRadio>
            </ElRadioGroup>
          </div>
        </ElFormItem>
        <ElFormItem :label="tl('event_expire_hour')">
          <ElInput v-model="form.expired_hour" />
        </ElFormItem>
        <ElFormItem :label="tl('event_flow_limit_multiple')">
          <ElInput v-model="form.flow_limit_multiple" />
        </ElFormItem>
        <ElFormItem :label="tl('max_amount')">
          <ElInput v-model="form.max_point" />
        </ElFormItem>
        <ElFormItem :label="tl('status')">
          <ElRadioGroup v-model="form.status">
            <ElRadio value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
      </div>
    </ElForm>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
