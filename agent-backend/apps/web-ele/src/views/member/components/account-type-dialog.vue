<script lang="ts" setup>
import type { MemberRow } from '#/api';

import { ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
} from 'element-plus';

import { modifyMemberTypeApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'MemberAccountTypeDialog' });

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const submitting = ref(false);
const memberId = ref(0);
const typeText = ref('');
const newType = ref<number | string>('');
const remark = ref('');

function open(row: MemberRow) {
  memberId.value = row.id;
  typeText.value = row.account_type ?? '';
  // type==0 預選「正常/股東會員」(1)，type==2 預選「分紅會員」(2)
  if (row.type === 2) newType.value = 2;
  else if (row.type === 0) newType.value = 1;
  else newType.value = '';
  remark.value = '';
  visible.value = true;
}

async function submit() {
  if (!newType.value) {
    ElMessage.warning(`${tl('please_choose')} ${tl('account_type')}`);
    return;
  }
  if (!remark.value.trim()) {
    ElMessage.warning(tl('enter_memo'));
    return;
  }
  submitting.value = true;
  try {
    const res: any = await modifyMemberTypeApi({
      id: memberId.value,
      remark: remark.value.trim(),
      type: newType.value,
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
    :title="tl('account_type')"
    width="460"
    align-center
  >
    <div class="mb-3">
      <b>{{ tl('account_type') }}：</b>{{ typeText || '-' }}
    </div>
    <ElFormItem :label="tl('modify_account_type')" label-width="auto">
      <ElSelect v-model="newType" class="!w-full">
        <ElOption :label="tl('please_choose')" value="" />
        <ElOption
          :label="`${tl('normal_member')}/${tl('shareholder_member')}`"
          :value="1"
        />
        <ElOption :label="tl('profit_share_member')" :value="2" />
      </ElSelect>
    </ElFormItem>
    <ElFormItem :label="tl('memo')" label-width="auto">
      <ElInput
        v-model="remark"
        :placeholder="tl('enter_memo')"
        :rows="3"
        type="textarea"
      />
    </ElFormItem>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="submitting" type="primary" @click="submit">
        {{ tl('confirm') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
