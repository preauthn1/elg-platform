<script lang="ts" setup>
import type { RealnameValidateRow } from '#/api';

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

import {
  getMemberRealnameValidateViewApi,
  reviewMemberRealnameValidateApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'RealnameReviewDialog' });

defineProps<{
  bsbAlias: string;
  enableBsb: boolean;
}>();
const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const loading = ref(false);
const submitting = ref(false);
const record = ref<RealnameValidateRow>({ id: 0 });
const remark = ref('');

const isPending = computed(() => Number(record.value.status) === 0);

/** 證件照片：正反面都有值才顯示，供點擊放大 */
const photos = computed(() => {
  const list: string[] = [];
  if (record.value.id_front_image) list.push(record.value.id_front_image);
  if (record.value.id_back_image) list.push(record.value.id_back_image);
  return list;
});

async function open(requestId: number) {
  visible.value = true;
  loading.value = true;
  remark.value = '';
  record.value = { id: 0 };
  try {
    record.value = (await getMemberRealnameValidateViewApi(requestId)) ?? {
      id: 0,
    };
    remark.value = record.value.remark ?? '';
  } finally {
    loading.value = false;
  }
}

async function review(status: number) {
  submitting.value = true;
  try {
    const res: any = await reviewMemberRealnameValidateApi({
      id: record.value.id,
      remark: remark.value,
      status,
    });
    if (Number(res?.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res?.message || tl('failure'));
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
    :title="isPending ? tl('review') : tl('view')"
    width="640"
    align-center
  >
    <ElDescriptions v-loading="loading" :column="2" border>
      <ElDescriptionsItem :label="tl('num')">
        {{ record.id || '-' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('username')">
        {{ record.username || '-' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('realname')">
        {{ record.realname || '-' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('birthday')">
        {{ record.birthday || '-' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('account_name')">
        {{ record.bank_name || '-' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('account_no')">
        {{ record.bank_account || '-' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem v-if="enableBsb" :label="bsbAlias">
        {{ record.bsb_code || '-' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('id_number')">
        {{ record.id_number || '-' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('id_photo')" :span="2">
        <span v-if="photos.length === 0">-</span>
        <div v-else class="flex gap-3">
          <div v-if="record.id_front_image">
            <div class="text-muted-foreground mb-1 text-xs">
              {{ tl('id_front_image') }}
            </div>
            <ElImage
              :preview-src-list="photos"
              :src="record.id_front_image"
              :initial-index="0"
              class="h-24 w-36"
              fit="cover"
              preview-teleported
            />
          </div>
          <div v-if="record.id_back_image">
            <div class="text-muted-foreground mb-1 text-xs">
              {{ tl('id_back_image') }}
            </div>
            <ElImage
              :preview-src-list="photos"
              :src="record.id_back_image"
              :initial-index="photos.length - 1"
              class="h-24 w-36"
              fit="cover"
              preview-teleported
            />
          </div>
        </div>
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('memo')" :span="2">
        <ElInput v-if="isPending" v-model="remark" :rows="2" type="textarea" />
        <span v-else>{{ record.remark || '-' }}</span>
      </ElDescriptionsItem>
    </ElDescriptions>
    <template v-if="isPending" #footer>
      <ElButton :loading="submitting" type="success" @click="review(1)">
        {{ tl('pass') }}
      </ElButton>
      <ElButton :loading="submitting" type="danger" @click="review(2)">
        {{ tl('reject') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
