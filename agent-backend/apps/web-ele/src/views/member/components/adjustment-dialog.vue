<script lang="ts" setup>
import type { MemberRow } from '#/api';

import { reactive, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElInputNumber,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
} from 'element-plus';

import {
  getAdjustmentTypesApi,
  getMemberViewApi,
  memberAdjustmentApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'MemberAdjustmentDialog' });

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const loading = ref(false);
const submitting = ref(false);
const member = ref<MemberRow | null>(null);
const types = ref<Array<{ id: number; name: string }>>([]);

const form = reactive({ memo: '', point: 0, type: 0 });

async function open(row: MemberRow) {
  visible.value = true;
  loading.value = true;
  member.value = null;
  Object.assign(form, { memo: '', point: 0, type: 0 });
  try {
    const [view, typeList] = await Promise.all([
      getMemberViewApi(row.id),
      getAdjustmentTypesApi(),
    ]);
    member.value = view;
    types.value = typeList ?? [];
    form.type = types.value[0]?.id ?? 0;
  } finally {
    loading.value = false;
  }
}

async function submit() {
  if (!(Number(form.point) > 0)) {
    ElMessage.warning('Please enter a number greater than 0');
    return;
  }
  const typeName = types.value.find((t) => t.id === form.type)?.name ?? '';
  try {
    await ElMessageBox.confirm(
      [
        `${tl('member_account')}：${member.value?.username ?? ''}`,
        `${tl('category')}：${typeName}`,
        `${tl('point')}：${form.point}`,
        `${tl('memo')}：${form.memo}`,
      ].join('\n'),
      tl('confirm'),
      {
        cancelButtonText: tl('cancel'),
        confirmButtonText: tl('confirm'),
        customStyle: { whiteSpace: 'pre-line' } as any,
      },
    );
  } catch {
    return;
  }
  const m = member.value;
  if (!m) return;
  submitting.value = true;
  try {
    const res: any = await memberAdjustmentApi({
      member_id: m.id,
      memo: form.memo,
      point: form.point,
      type: form.type,
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
    :title="tl('transaction_type.33')"
    width="480"
    align-center
  >
    <template v-if="member">
      <div class="mb-4 space-y-1 text-sm">
        <div>{{ tl('member_account') }}：{{ member.username }}</div>
        <div>{{ tl('member_balance') }}：{{ member.balance }}</div>
      </div>
      <ElForm label-position="top">
        <div class="grid grid-cols-2 gap-x-4">
          <ElFormItem :label="tl('category')">
            <ElSelect v-model="form.type" class="w-full">
              <ElOption
                v-for="t in types"
                :key="t.id"
                :label="t.name"
                :value="t.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('point')">
            <ElInputNumber
              v-model="form.point"
              :min="0"
              :precision="2"
              class="!w-full"
              controls-position="right"
            />
          </ElFormItem>
        </div>
        <ElFormItem :label="tl('memo')">
          <ElInput v-model="form.memo" :rows="3" type="textarea" />
        </ElFormItem>
      </ElForm>
    </template>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="submitting" type="primary" @click="submit">
        {{ tl('confirm') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
