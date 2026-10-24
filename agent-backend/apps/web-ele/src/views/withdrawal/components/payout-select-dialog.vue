<script lang="ts" setup>
import type { PayoutChannel } from '#/api';

import { computed, ref } from 'vue';

import {
  ElAlert,
  ElButton,
  ElDialog,
  ElMessage,
  ElOption,
  ElSelect,
} from 'element-plus';

import { doWithdrawalPayoutApi, getWithdrawalChannelsApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'PayoutSelectDialog' });

const emit = defineEmits<{ done: [] }>();

const visible = ref(false);
const loading = ref(false);
const submitting = ref(false);
const orderId = ref<number | string>('');
const available = ref<PayoutChannel[]>([]);
const unavailable = ref<PayoutChannel[]>([]);
const selected = ref<number | string>('');

const selectedChannel = computed(() =>
  available.value.find((c) => c.agent_cash_flow_id === selected.value),
);

async function open(id: number | string) {
  orderId.value = id;
  visible.value = true;
  loading.value = true;
  available.value = [];
  unavailable.value = [];
  selected.value = '';
  try {
    const res = await getWithdrawalChannelsApi(id);
    if (Number(res.code) === 1000 || Number(res.code) === 200) {
      available.value = res.data?.available ?? [];
      unavailable.value = res.data?.unavailable ?? [];
      if (available.value.length > 0) {
        selected.value =
          available.value[0]?.agent_cash_flow_id ?? selected.value;
      }
    }
  } finally {
    loading.value = false;
  }
}

async function submit() {
  if (!selectedChannel.value) return;
  submitting.value = true;
  try {
    const res = await doWithdrawalPayoutApi({
      agent_cash_flow_id: selected.value,
      cash_flow_id: selectedChannel.value.cash_flow_id ?? '',
      order_id: orderId.value,
    });
    if (Number(res.code) === 1000 || Number(res.code) === 200) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('done');
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
    :title="tl('title_use_payout')"
    width="640"
    align-center
  >
    <div v-loading="loading" class="min-h-24">
      <div class="mb-3 text-center text-sm text-gray-500">
        {{ tl('ordernum') }} : {{ orderId }}
      </div>

      <ElAlert
        v-if="available.length === 0"
        :closable="false"
        :title="tl('msg_no_available_payout_vendors')"
        :description="tl('msg_amount_and_bank_not_support')"
        type="error"
        show-icon
      />
      <div v-else class="mb-4">
        <div class="mb-2 text-sm text-gray-600">
          {{ tl('please_select_payout_vendor') }}
        </div>
        <div class="flex gap-2">
          <ElSelect v-model="selected" class="flex-1">
            <ElOption
              v-for="c in available"
              :key="c.agent_cash_flow_id"
              :label="c.agent_cash_flow_name"
              :value="c.agent_cash_flow_id"
            />
          </ElSelect>
          <ElButton :loading="submitting" type="primary" @click="submit">
            {{ tl('submit') }}
          </ElButton>
        </div>
      </div>

      <div v-if="unavailable.length > 0" class="mt-2">
        <div class="mb-2 text-xs text-gray-400">
          {{ tl('msg_unavailable_payout_vendors') }}
        </div>
        <div class="flex flex-col gap-1">
          <div
            v-for="(c, i) in unavailable"
            :key="i"
            class="flex items-center justify-between rounded bg-gray-50 px-3 py-2 text-xs dark:bg-gray-800"
          >
            <span class="font-medium">{{ c.agent_cash_flow_name }}</span>
            <span :title="c.reason" class="truncate text-gray-500">
              {{ c.reason }}
            </span>
          </div>
        </div>
      </div>
    </div>
  </ElDialog>
</template>
