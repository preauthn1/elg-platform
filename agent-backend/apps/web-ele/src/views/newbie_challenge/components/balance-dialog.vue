<script lang="ts" setup>
import { ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
} from 'element-plus';

import { updateNewbieChallengeWalletBalanceApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'NewbieChallengeBalanceDialog' });

const emit = defineEmits<{ saved: [] }>();

const visible = ref(false);
const saving = ref(false);
const progressId = ref<number | string>(0);
/** 1=加分 2=扣分 */
const operate = ref(1);
const point = ref('');

function open(id: number | string) {
  progressId.value = id;
  operate.value = 1;
  point.value = '';
  visible.value = true;
}

async function save() {
  const value = Number(point.value);
  if (!Number.isFinite(value) || value < 0) {
    ElMessage.warning(tl('please_enter'));
    return;
  }
  saving.value = true;
  try {
    const res = await updateNewbieChallengeWalletBalanceApi({
      balance: operate.value === 2 ? -value : value,
      progress_id: progressId.value,
    });
    if (Number(res.code) === 200) {
      ElMessage.success(tl('save_success'));
      visible.value = false;
      emit('saved');
      return;
    }
    ElMessage.error(res.message ?? '');
  } finally {
    saving.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('event_wallet_balance')}${tl('modify')}`"
    width="480"
    align-center
  >
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
      <div>
        <div class="text-muted-foreground mb-1 text-xs">
          {{ tl('operate') }}
        </div>
        <ElSelect v-model="operate" class="!w-full">
          <ElOption :label="tl('newbie_challenge_add_balance')" :value="1" />
          <ElOption :label="tl('newbie_challenge_reduce_balance')" :value="2" />
        </ElSelect>
      </div>
      <div>
        <div class="text-muted-foreground mb-1 text-xs">{{ tl('point') }}</div>
        <ElInput
          v-model="point"
          :placeholder="`${tl('please_enter')}${tl('modify')}${tl('point')}`"
          step="0.01"
          type="number"
        />
      </div>
    </div>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="save">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
