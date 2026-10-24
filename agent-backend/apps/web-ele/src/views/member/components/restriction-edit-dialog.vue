<script lang="ts" setup>
import { reactive, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
} from 'element-plus';

import {
  addMemberRestrictionApi,
  editMemberRestrictionApi,
  getMemberRestrictionViewApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'RestrictionEditDialog' });

const emit = defineEmits<{ saved: [] }>();

const visible = ref(false);
const loading = ref(false);
const saving = ref(false);
const editId = ref<null | number>(null);

const form = reactive({
  account_status: 1,
  remark: '',
  risk: 1,
  username: '',
});

/** 無 id 為新增，有 id 先 view 帶入 */
async function open(id?: number) {
  visible.value = true;
  editId.value = id ?? null;
  form.account_status = 1;
  form.remark = '';
  form.risk = 1;
  form.username = '';
  if (id) {
    loading.value = true;
    try {
      const data = await getMemberRestrictionViewApi(id);
      form.username = data?.username ?? '';
      form.account_status = Number(data?.account_status) === 2 ? 2 : 1;
      form.risk = Number(data?.risk) === 2 ? 2 : 1;
      form.remark = data?.remark ?? '';
    } finally {
      loading.value = false;
    }
  }
}

async function handleSave() {
  saving.value = true;
  try {
    const res = await (editId.value
      ? editMemberRestrictionApi({
          account_status: form.account_status,
          id: editId.value,
          remark: form.remark,
          risk: form.risk,
        })
      : addMemberRestrictionApi({
          account_status: form.account_status,
          remark: form.remark,
          risk: form.risk,
          username: form.username,
        }));
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message);
      visible.value = false;
      emit('saved');
    } else {
      // 2002 顯示 data，其餘顯示 message
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
    :title="editId ? tl('edit') : tl('add')"
    width="560"
    align-center
  >
    <ElForm
      v-loading="loading"
      :model="form"
      label-width="auto"
      @submit.prevent="handleSave"
    >
      <ElFormItem :label="tl('member_name')" required>
        <ElInput
          v-model="form.username"
          :disabled="!!editId"
          placeholder="Username"
        />
      </ElFormItem>
      <ElFormItem :label="tl('account_status')">
        <ElSelect v-model="form.account_status" class="w-full">
          <ElOption :label="tl('account_status.1')" :value="1" />
          <ElOption :label="tl('account_status.2')" :value="2" />
        </ElSelect>
      </ElFormItem>
      <ElFormItem :label="tl('risk')">
        <ElSelect v-model="form.risk" class="w-full">
          <ElOption :label="tl('risk.1')" :value="1" />
          <ElOption :label="tl('risk.2')" :value="2" />
        </ElSelect>
      </ElFormItem>
      <ElFormItem :label="tl('memo')">
        <div class="w-full">
          <div class="mb-1 text-xs text-red-500">
            ({{ tl('member_restriction_remark_1') }})
          </div>
          <ElInput v-model="form.remark" placeholder="Remark" />
        </div>
      </ElFormItem>
    </ElForm>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
