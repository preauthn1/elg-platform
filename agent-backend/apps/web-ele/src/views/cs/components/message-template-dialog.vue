<script lang="ts" setup>
import type { CsMessageTemplate } from '#/api/cs';

import { reactive, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
} from 'element-plus';

import {
  createCsMessageTemplateApi,
  updateCsMessageTemplateApi,
} from '#/api/cs';
import { tl } from '#/locales';

/** 快速回復訊息新增／編輯（target/tickets#4485） */
defineOptions({ name: 'CsMessageTemplateDialog' });

const emit = defineEmits<{ saved: [] }>();

const visible = ref(false);
const saving = ref(false);
const editId = ref<'' | number | string>('');
const form = reactive({
  content: '',
  memo: '',
  title: '',
});

/** 無 row＝新增；有 row＝編輯並帶入原值 */
function open(row?: CsMessageTemplate) {
  editId.value = row?.id ?? '';
  form.title = row?.title ?? '';
  form.content = row?.content ?? '';
  form.memo = row?.memo ?? '';
  visible.value = true;
}

/**
 * 錯誤訊息：requestClient 遇到 code ≠ 1000 會丟出後端回應 body，message 即後端訊息；
 * 但客服後端驗證失敗（2002）的 message 是陣列（validator errors()->all()），要串成字串。
 */
function errorText(error: any): string {
  const message = error?.message;
  if (Array.isArray(message)) return message.join('\n');
  return typeof message === 'string' ? message : '';
}

async function handleSave() {
  if (saving.value) return;
  const title = form.title.trim();
  const content = form.content.trim();
  if (!title || !content) {
    ElMessage.warning(tl('cs.enter_title_content'));
    return;
  }
  saving.value = true;
  try {
    await (editId.value === ''
      ? createCsMessageTemplateApi({ content, memo: form.memo, title })
      : updateCsMessageTemplateApi({
          content,
          id: editId.value,
          memo: form.memo,
          title,
        }));
    ElMessage.success(tl('cs.saved'));
    visible.value = false;
    emit('saved');
  } catch (error: any) {
    ElMessage.error(errorText(error) || tl('cs.add_failed'));
  } finally {
    saving.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="editId === '' ? tl('add') : tl('cs.edit_content')"
    align-center
    width="560"
  >
    <!-- 長度上限照客服後端 MessageTemplate::$rules_create／$rules_update -->
    <ElForm label-width="auto" @submit.prevent="handleSave">
      <ElFormItem :label="tl('list.label_name')" required>
        <ElInput v-model="form.title" maxlength="50" show-word-limit />
      </ElFormItem>
      <ElFormItem :label="tl('list.message_content')" required>
        <ElInput
          v-model="form.content"
          :rows="6"
          maxlength="1000"
          show-word-limit
          type="textarea"
        />
      </ElFormItem>
      <ElFormItem :label="tl('memo')">
        <ElInput v-model="form.memo" maxlength="50" show-word-limit />
      </ElFormItem>
    </ElForm>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('save') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
