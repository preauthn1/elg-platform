<script lang="ts" setup>
import { onMounted, reactive, ref } from 'vue';

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

import { createTransferLimitApi, getTransferLimitDropdownApi } from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const submitting = ref(false);
const groups = ref<Array<{ id: number | string; name: string }>>([]);
const form = reactive({ id: '' as number | string, limit: '' });

function open() {
  form.id = '';
  form.limit = '';
  visible.value = true;
}

async function submit() {
  if (!form.id || form.limit === '') {
    ElMessage.warning(tl('required_column'));
    return;
  }
  submitting.value = true;
  try {
    const res = await createTransferLimitApi({
      id: form.id,
      limit: form.limit,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    submitting.value = false;
  }
}

onMounted(async () => {
  groups.value = (await getTransferLimitDropdownApi().catch(() => [])) ?? [];
});

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('add_agent_transfer_limit')"
    width="520"
  >
    <ElForm label-width="auto">
      <ElFormItem :label="tl('group_name')" required>
        <ElSelect v-model="form.id" class="w-full" filterable>
          <ElOption
            v-for="g in groups"
            :key="g.id"
            :label="g.name"
            :value="g.id"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem :label="tl('limit_amount')" required>
        <ElInput v-model="form.limit" />
      </ElFormItem>
    </ElForm>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="submitting" type="primary" @click="submit">
        {{ tl('add') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
