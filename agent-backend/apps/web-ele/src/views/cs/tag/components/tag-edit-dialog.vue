<script lang="ts" setup>
import type { CsTag } from '#/api/cs-tag';

import { computed, reactive, ref } from 'vue';

import {
  ElButton,
  ElColorPicker,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElSwitch,
} from 'element-plus';

import { saveCsTagApi } from '#/api/cs-tag';
import { tl } from '#/locales';

const emit = defineEmits<{ saved: [] }>();

const visible = ref(false);
const saving = ref(false);
const editId = ref<'' | number | string>('');
// background_color 允許 null：ElColorPicker 的「清空」按鈕（clearable 預設開）
// emit 的是 null，型別要照實反映，否則下面的字串操作會在執行期炸掉
const form = reactive({
  background_color: '' as null | string,
  name: '',
  status: 1,
});

/**
 * 文字色由背景色亮度自動配黑/白（票面只有一個「顏色」欄位，
 * 但既有聊天室 chips 的渲染吃 background_color + text_color 雙色）。
 */
const textColor = computed(() => {
  const hex = (form.background_color ?? '').replace('#', '');
  if (!/^[0-9a-f]{6}$/i.test(hex)) return '#FFFFFF';
  const r = Number.parseInt(hex.slice(0, 2), 16);
  const g = Number.parseInt(hex.slice(2, 4), 16);
  const b = Number.parseInt(hex.slice(4, 6), 16);
  return 0.299 * r + 0.587 * g + 0.114 * b > 150 ? '#000000' : '#FFFFFF';
});

function open(row?: CsTag) {
  editId.value = row?.id ?? '';
  form.name = row?.name ?? '';
  form.background_color = row?.background_color ?? '#FF5722';
  form.status = row?.status === undefined ? 1 : Number(row.status);
  visible.value = true;
}

async function handleSave() {
  if (!form.name.trim()) {
    ElMessage.error(tl('list.label_name'));
    return;
  }
  saving.value = true;
  try {
    const res = await saveCsTagApi({
      background_color: form.background_color ?? '',
      id: editId.value === '' ? undefined : editId.value,
      name: form.name.trim(),
      status: form.status,
      text_color: textColor.value,
    });
    if (Number(res.code) === 1000) {
      visible.value = false;
      emit('saved');
    } else {
      // 2002 顯示 data，其餘顯示 message（比照 tag-setting-dialog）
      ElMessage.error(
        Number(res.code) === 2002
          ? String(res.data ?? res.message)
          : res.message,
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
    :title="editId === '' ? tl('add') : tl('edit')"
    align-center
    width="480"
  >
    <ElForm label-width="auto" @submit.prevent="handleSave">
      <ElFormItem :label="tl('list.label_name')" required>
        <!-- 191 而非 255：cs_tags.name 受 Schema::defaultStringLength(191) 限制 -->
        <ElInput v-model="form.name" maxlength="191" />
      </ElFormItem>
      <ElFormItem :label="tl('cs.tag_color')">
        <div class="flex items-center gap-2">
          <ElColorPicker v-model="form.background_color" color-format="hex" />
          <ElInput
            v-model="form.background_color"
            class="w-28"
            maxlength="7"
            placeholder="#FF5722"
          />
          <span
            v-if="form.background_color"
            :style="{
              backgroundColor: form.background_color,
              color: textColor,
            }"
            class="rounded px-1.5 py-0.5 text-xs"
          >
            {{ form.name.trim() || tl('tag') }}
          </span>
        </div>
      </ElFormItem>
      <ElFormItem :label="tl('status')">
        <ElSwitch
          v-model="form.status"
          :active-text="tl('enable')"
          :active-value="1"
          :inactive-text="tl('disable')"
          :inactive-value="0"
        />
      </ElFormItem>
    </ElForm>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
