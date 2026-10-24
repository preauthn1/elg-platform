<script lang="ts" setup>
import { reactive, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
} from 'element-plus';

import { modifyMemberPasswordApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'MemberPasswordDialog' });

const emit = defineEmits<{ success: [] }>();

type PwdType = 'password' | 'payment_password';

const visible = ref(false);
const submitting = ref(false);
const memberId = ref(0);
const pwdType = ref<PwdType>('password');

const form = reactive({
  confirm_password: '',
  password: '',
  remark: '',
});

function open(id: number, type: PwdType) {
  memberId.value = id;
  pwdType.value = type;
  form.password = '';
  form.confirm_password = '';
  form.remark = '';
  visible.value = true;
}

async function submit() {
  if (!form.password || !form.confirm_password || !form.remark) {
    ElMessage.warning(tl('enter_memo'));
    return;
  }
  if (form.password !== form.confirm_password) {
    ElMessage.warning(
      tl(
        pwdType.value === 'password'
          ? 'password_confirm'
          : 'payment_password_confirm',
      ),
    );
    return;
  }
  submitting.value = true;
  try {
    const res: any = await modifyMemberPasswordApi({
      confirm_password: [REDACTED],
      member_id: memberId.value,
      password: [REDACTED],
      remark: form.remark,
      type: pwdType.value,
    });
    if (res.code === 1000) {
      ElMessage.success(res.message || tl('success'));
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
  <ElDialog
    v-model="visible"
    :title="
      pwdType === 'password'
        ? tl('modify_password')
        : tl('modify_payment_password')
    "
    width="480"
    align-center
  >
    <ElForm label-position="top">
      <ElFormItem
        :label="tl(pwdType === 'password' ? 'password' : 'payment_password')"
        required
      >
        <ElInput v-model="form.password" show-password type="password" />
      </ElFormItem>
      <ElFormItem
        :label="
          tl(
            pwdType === 'password'
              ? 'password_confirm'
              : 'payment_password_confirm',
          )
        "
        required
      >
        <ElInput
          v-model="form.confirm_password"
          show-password
          type="password"
        />
      </ElFormItem>
      <ElFormItem :label="tl('memo')" required>
        <ElInput v-model="form.remark" :rows="3" type="textarea" />
      </ElFormItem>
    </ElForm>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="submitting" type="primary" @click="submit">
        {{ tl('confirm') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
