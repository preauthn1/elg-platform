<script lang="ts" setup>
import type { AutoDepositImage } from '#/api';

import { computed, ref } from 'vue';

import {
  ElButton,
  ElDescriptions,
  ElDescriptionsItem,
  ElDialog,
  ElImage,
  ElInput,
  ElMessage,
} from 'element-plus';

import { getAutoDepositImageApi, reviewAutoDepositApi } from '#/api';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

defineOptions({ name: 'OrderReviewDialog' });

const props = defineProps<{
  bsbAlias?: string;
  enableBsb?: boolean;
}>();

const emit = defineEmits<{ saved: [] }>();

const visible = ref(false);
const loading = ref(false);
const saving = ref(false);
const record = ref<AutoDepositImage | null>(null);
const errorMsg = ref('');
const remark = ref('');

const fmt = (v?: null | number | string) => amountThousands(v, 2);

/** 帳號代號欄位（啟用 BSB 時顯示「別名/帳號」） */
const accountNoLabel = computed(() =>
  props.enableBsb
    ? `${props.bsbAlias || 'BSB Code'}/${tl('account_no')}`
    : tl('account_no'),
);

/** 待審核才可填備註與送審 */
const canReview = computed(() => Number(record.value?.status) === 1);

const memberTags = computed(() =>
  (record.value?.member_tags ?? []).filter((t) => t && t.name && t.name !== ''),
);

/** 審核日期 / 操作人：僅拒絕(2)、通過(3) 才有值 */
const reviewed = computed(() => [2, 3].includes(Number(record.value?.status)));

function statusText(status?: number) {
  switch (Number(status)) {
    case 0: {
      return tl('order_create');
    }
    case 1: {
      return tl('pending_review');
    }
    case 2: {
      return tl('rejected');
    }
    default: {
      return tl('deposit_success');
    }
  }
}

/** remark 為 JSON 字串，取其中 message */
function remarkMessage(raw?: string) {
  if (!raw) return '';
  try {
    return JSON.parse(raw)?.message ?? '';
  } catch {
    return raw;
  }
}

async function open(orderId: number | string) {
  visible.value = true;
  loading.value = true;
  record.value = null;
  errorMsg.value = '';
  remark.value = '';
  try {
    const res = await getAutoDepositImageApi(orderId);
    if (Number(res.code) === 1000) {
      record.value = res.data;
    } else {
      errorMsg.value = res.message;
    }
  } finally {
    loading.value = false;
  }
}

/** 點擊圖片開新視窗檢視 */
function openImage() {
  const url = record.value?.image;
  if (url && url !== ' ') {
    window.open(url);
  }
}

async function reviewOrder(status: number) {
  if (!record.value) return;
  saving.value = true;
  try {
    const res = await reviewAutoDepositApi({
      id: record.value.id,
      remark: remark.value,
      status,
    });
    if (Number(res.code) === 1000) {
      visible.value = false;
      emit('saved');
    } else {
      ElMessage.error(Number(res.code) === 2002 ? res.data || '' : res.message);
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
    :title="tl('order_info')"
    width="720"
    align-center
  >
    <div v-if="errorMsg" class="py-6 text-center text-red-500">
      {{ errorMsg }}
    </div>
    <template v-else>
      <ElDescriptions v-loading="loading" :column="2" border>
        <ElDescriptionsItem :label="tl('bank_name')">
          {{ record?.bank_name || '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('account_code')">
          {{ record?.account_nickname || '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('account_name')">
          {{ record?.account_name || '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="accountNoLabel">
          <template v-if="enableBsb"> {{ record?.bsb_code }}<br /> </template>
          {{ record?.account_no }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('agent_name')">
          {{ record?.agent_username || '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('account_code')">
          {{ record?.username || '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('real_name')">
          {{ record?.first_name || '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('amount')">
          {{ fmt(record?.amount) }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('rate')">
          {{ record?.rate ?? '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('point')">
          {{ fmt(record?.point) }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('createdate')">
          {{ record?.create_at || '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('review_time')">
          {{ reviewed ? record?.pay_time : '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('operator')">
          {{ reviewed ? record?.operator : '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('status')">
          {{ statusText(record?.status) }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('menu_member_tag')" :span="2">
          <template v-if="memberTags.length > 0">
            <span
              v-for="(tag, i) in memberTags"
              :key="i"
              :style="{
                backgroundColor: tag.background_color,
                color: tag.text_color,
              }"
              class="mr-1 rounded px-1"
            >
              {{ tag.name }}
            </span>
          </template>
          <template v-else>-</template>
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('picture')" :span="2">
          <ElImage
            v-if="record?.image && record.image !== ' '"
            :src="record.image"
            class="h-[300px] cursor-pointer"
            fit="contain"
            @click="openImage"
          />
          <span v-else>-</span>
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('memo')" :span="2">
          <ElInput
            v-if="canReview"
            v-model="remark"
            :rows="3"
            type="textarea"
          />
          <span v-else>{{ remarkMessage(record?.remark) }}</span>
        </ElDescriptionsItem>
      </ElDescriptions>
      <div v-if="canReview" class="mt-4 text-center">
        <ElButton :loading="saving" type="success" @click="reviewOrder(3)">
          {{ tl('pass') }}
        </ElButton>
        <ElButton :loading="saving" type="danger" @click="reviewOrder(2)">
          {{ tl('reject') }}
        </ElButton>
      </div>
    </template>
  </ElDialog>
</template>
