<script lang="ts" setup>
import type { TagRow } from '#/api';

import { reactive, ref } from 'vue';

import {
  ElButton,
  ElColorPicker,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getTagListApi, saveTagApi } from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ closed: [] }>();

const visible = ref(false);
const loading = ref(false);
const list = ref<TagRow[]>([]);

async function loadData() {
  loading.value = true;
  try {
    list.value = (await getTagListApi()) ?? [];
  } finally {
    loading.value = false;
  }
}

async function open() {
  visible.value = true;
  await loadData();
}

/* ---- 新增/編輯標籤 ---- */
const editVisible = ref(false);
const saving = ref(false);
const editId = ref<'' | number>('');
const form = reactive({ background_color: '', name: '', text_color: '' });
const previewVisible = ref(false);

function openEdit(row?: TagRow) {
  editId.value = row?.id ?? '';
  form.name = row?.name ?? '';
  form.text_color = row?.text_color ?? '';
  form.background_color = row?.background_color ?? '';
  previewVisible.value = false;
  editVisible.value = true;
}

function handleResult(res: { code: number; data?: unknown; message: string }) {
  if (Number(res.code) === 1000) {
    editVisible.value = false;
    loadData();
    return true;
  }
  // 2002 顯示 data，其餘顯示 message
  ElMessage.error(
    Number(res.code) === 2002 ? String(res.data ?? res.message) : res.message,
  );
  return false;
}

async function handleSave() {
  saving.value = true;
  try {
    handleResult(
      await saveTagApi({
        background_color: form.background_color,
        id: editId.value,
        name: form.name,
        text_color: form.text_color,
      }),
    );
  } finally {
    saving.value = false;
  }
}

/** 啟用/停用切換 */
async function toggleStatus(row: TagRow) {
  handleResult(
    await saveTagApi({
      id: row.id,
      status: Number(row.status) === 1 ? 0 : 1,
    }),
  );
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('tag_setting')"
    align-center
    width="880"
    @closed="emit('closed')"
  >
    <div class="mb-3">
      <ElButton type="success" @click="openEdit()">{{ tl('add') }}</ElButton>
    </div>

    <ElTable
      v-loading="loading"
      :data="list"
      border
      max-height="60vh"
      size="small"
      stripe
    >
      <ElTableColumn label="ID" prop="id" width="90" />
      <ElTableColumn :label="tl('tag')" min-width="140">
        <template #default="{ row }">
          <span
            :style="{
              backgroundColor: row.background_color,
              color: row.text_color,
            }"
            class="rounded px-1"
          >
            {{ row.name }}
          </span>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('tag_text_color')" width="140">
        <template #default="{ row }">
          <span
            :style="{ backgroundColor: row.text_color }"
            class="mr-1.5 inline-block h-4 w-4 border align-middle"
          ></span>
          <span class="align-middle">{{ row.text_color }}</span>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('tag_background_color')" width="140">
        <template #default="{ row }">
          <span
            :style="{ backgroundColor: row.background_color }"
            class="mr-1.5 inline-block h-4 w-4 border align-middle"
          ></span>
          <span class="align-middle">{{ row.background_color }}</span>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('status')" width="100">
        <template #default="{ row }">
          <b
            :class="
              Number(row.status) === 1 ? 'text-green-600' : 'text-red-500'
            "
          >
            {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
          </b>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('features')" align="center" width="160">
        <template #default="{ row }">
          <ElButton
            size="small"
            type="primary"
            @click="openEdit(row as TagRow)"
          >
            {{ tl('edit') }}
          </ElButton>
          <ElButton
            size="small"
            :type="Number(row.status) === 1 ? 'danger' : 'success'"
            @click="toggleStatus(row as TagRow)"
          >
            {{ Number(row.status) === 1 ? tl('disable') : tl('enable') }}
          </ElButton>
        </template>
      </ElTableColumn>
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>

    <!-- 新增/編輯標籤 -->
    <ElDialog
      v-model="editVisible"
      :title="editId ? tl('edit') : tl('add')"
      align-center
      append-to-body
      width="560"
    >
      <ElForm label-width="auto" @submit.prevent="handleSave">
        <ElFormItem :label="tl('tag')">
          <ElInput v-model="form.name" maxlength="50" />
        </ElFormItem>
        <ElFormItem :label="tl('tag_text_color')">
          <div class="flex items-center gap-2">
            <ElColorPicker v-model="form.text_color" color-format="hex" />
            <ElInput
              v-model="form.text_color"
              class="flex-1"
              maxlength="7"
              placeholder="#FF0000"
            />
          </div>
        </ElFormItem>
        <ElFormItem :label="tl('tag_background_color')">
          <div class="flex items-center gap-2">
            <ElColorPicker v-model="form.background_color" color-format="hex" />
            <ElInput
              v-model="form.background_color"
              class="flex-1"
              maxlength="7"
              placeholder="#FFFF37"
            />
          </div>
        </ElFormItem>
        <ElFormItem>
          <ElButton @click="previewVisible = true">
            {{ tl('preview') }}
          </ElButton>
        </ElFormItem>
        <ElFormItem v-if="previewVisible" :label="tl('preview')">
          <span
            :style="{
              backgroundColor: form.background_color || undefined,
              color: form.text_color || undefined,
            }"
            class="rounded px-1"
          >
            {{ form.name.trim() || tl('tag') }}
          </span>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton :loading="saving" type="primary" @click="handleSave">
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>
  </ElDialog>
</template>
