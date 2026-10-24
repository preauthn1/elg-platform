<script lang="ts" setup>
import type { CsNote, CsNoteContent } from '#/api/cs';

import { reactive, ref, watch } from 'vue';

import { usePreferences } from '@vben/preferences';

import {
  ElButton,
  ElDialog,
  ElDrawer,
  ElEmpty,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import {
  addCsNoteApi,
  addCsNoteContentApi,
  deleteCsNoteContentApi,
  editCsNoteApi,
  editCsNoteContentApi,
  getCsNoteContentListApi,
  getCsNoteListApi,
} from '#/api/cs';
import AgentAccount from '#/components/agent-account.vue';
import { tl } from '#/locales';

defineOptions({ name: 'CsNotesDrawer' });

const props = defineProps<{
  modelValue: boolean;
  roomId: number | string;
}>();
const emit = defineEmits<{ 'update:modelValue': [boolean] }>();

const { isMobile } = usePreferences();

const STATUS_KEY: Record<number, string> = {
  0: 'cs.incomplete',
  1: 'complete',
};
function statusLabel(s?: number) {
  return tl(STATUS_KEY[Number(s)] ?? 'cs.incomplete');
}

const view = ref<'content' | 'title'>('title');
const notes = ref<CsNote[]>([]);
const loading = ref(false);

const activeNote = ref<CsNote | null>(null);
const contents = ref<CsNoteContent[]>([]);
const contentLoading = ref(false);
// 送出中：防連點重複送出
const saving = ref(false);

const titleDlg = reactive({
  id: '' as number | string,
  mode: 'add' as 'add' | 'edit',
  status: 0,
  title: '',
  visible: false,
});
const contentDlg = reactive({
  content: '',
  id: '' as number | string,
  mode: 'add' as 'add' | 'edit',
  visible: false,
});

async function loadNotes() {
  if (
    props.roomId === '' ||
    props.roomId === null ||
    props.roomId === undefined
  ) {
    notes.value = [];
    return;
  }
  loading.value = true;
  try {
    notes.value = await getCsNoteListApi(props.roomId).catch(() => []);
  } finally {
    loading.value = false;
  }
}

async function openNote(note: CsNote) {
  activeNote.value = note;
  view.value = 'content';
  contentLoading.value = true;
  try {
    contents.value = await getCsNoteContentListApi(note.id).catch(() => []);
  } finally {
    contentLoading.value = false;
  }
}
function backToTitles() {
  view.value = 'title';
  activeNote.value = null;
}

function openAddTitle() {
  titleDlg.mode = 'add';
  titleDlg.id = '';
  titleDlg.title = '';
  titleDlg.status = 0;
  titleDlg.visible = true;
}
function openEditTitle(note: CsNote) {
  titleDlg.mode = 'edit';
  titleDlg.id = note.id;
  titleDlg.title = note.title ?? '';
  titleDlg.status = Number(note.status ?? 0);
  titleDlg.visible = true;
}
async function saveTitle() {
  if (saving.value) return;
  if (!titleDlg.title.trim()) {
    ElMessage.warning(tl('cs.enter_title'));
    return;
  }
  saving.value = true;
  try {
    await (titleDlg.mode === 'add'
      ? addCsNoteApi({
          room_id: props.roomId,
          status: titleDlg.status,
          title: titleDlg.title.trim(),
        })
      : editCsNoteApi({
          note_id: titleDlg.id,
          status: titleDlg.status,
          title: titleDlg.title.trim(),
        }));
    titleDlg.visible = false;
    ElMessage.success(tl('cs.saved'));
    await loadNotes();
  } finally {
    saving.value = false;
  }
}

function openAddContent() {
  contentDlg.mode = 'add';
  contentDlg.id = '';
  contentDlg.content = '';
  contentDlg.visible = true;
}
function openEditContent(c: CsNoteContent) {
  contentDlg.mode = 'edit';
  contentDlg.id = c.id;
  contentDlg.content = c.content ?? '';
  contentDlg.visible = true;
}
async function saveContent() {
  if (saving.value) return;
  if (!activeNote.value) return;
  if (!contentDlg.content.trim()) {
    ElMessage.warning(tl('cs.enter_content'));
    return;
  }
  saving.value = true;
  try {
    await (contentDlg.mode === 'add'
      ? addCsNoteContentApi({
          content: contentDlg.content.trim(),
          note_id: activeNote.value.id,
        })
      : editCsNoteContentApi({
          content: contentDlg.content.trim(),
          content_id: contentDlg.id,
        }));
    contentDlg.visible = false;
    ElMessage.success(tl('cs.saved'));
    await openNote(activeNote.value);
  } finally {
    saving.value = false;
  }
}
async function removeContent(c: CsNoteContent) {
  if (saving.value) return;
  try {
    await ElMessageBox.confirm(
      tl('cs.confirm_delete_content'),
      tl('form.delete'),
      {
        type: 'warning',
      },
    );
  } catch {
    return;
  }
  saving.value = true;
  try {
    await deleteCsNoteContentApi(c.id);
    ElMessage.success(tl('cs.deleted'));
    if (activeNote.value) await openNote(activeNote.value);
  } finally {
    saving.value = false;
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      view.value = 'title';
      activeNote.value = null;
      loadNotes();
    }
  },
);
</script>

<template>
  <ElDrawer
    :model-value="modelValue"
    :size="isMobile ? '90%' : 480"
    direction="rtl"
    :title="tl('cs.customer_note')"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
  >
    <!-- 筆記標題列表 -->
    <template v-if="view === 'title'">
      <div class="mb-2 flex items-center justify-between">
        <span class="text-sm text-muted-foreground">
          {{ tl('cs.total') }} {{ notes.length }}
        </span>
        <ElButton size="small" type="primary" @click="openAddTitle">
          {{ tl('cs.add_note') }}
        </ElButton>
      </div>
      <ElTable v-loading="loading" :data="notes" border size="small">
        <ElTableColumn prop="created_at" :label="tl('list.date')" width="150" />
        <ElTableColumn :label="tl('list.title')" show-overflow-tooltip>
          <template #default="{ row }">
            <ElButton link type="primary" @click="openNote(row as CsNote)">
              {{ (row as CsNote).title }}
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('list.status')" width="80">
          <template #default="{ row }">
            <ElTag
              size="small"
              :type="(row as CsNote).status === 1 ? 'success' : 'info'"
            >
              {{ statusLabel((row as CsNote).status) }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="create_user" :label="tl('cs.creator')" width="100">
          <template #default="{ row }">
            <AgentAccount :username="row.create_user" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('action')" width="70">
          <template #default="{ row }">
            <ElButton link size="small" @click="openEditTitle(row as CsNote)">
              {{ tl('edit') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>
          <ElEmpty :description="tl('cs.no_notes')" />
        </template>
      </ElTable>
    </template>

    <!-- 筆記內容列表 -->
    <template v-else>
      <div class="mb-2 flex items-center gap-2">
        <ElButton link size="small" @click="backToTitles">
          {{ tl('cs.back') }}
        </ElButton>
        <span class="flex-1 truncate font-medium">{{ activeNote?.title }}</span>
        <ElButton size="small" type="primary" @click="openAddContent">
          {{ tl('cs.add_content') }}
        </ElButton>
      </div>
      <ElTable v-loading="contentLoading" :data="contents" border size="small">
        <ElTableColumn prop="created_at" :label="tl('list.date')" width="150" />
        <ElTableColumn
          prop="content"
          :label="tl('list.content')"
          show-overflow-tooltip
        />
        <ElTableColumn prop="create_user" :label="tl('cs.creator')" width="100">
          <template #default="{ row }">
            <AgentAccount :username="row.create_user" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('action')" width="110">
          <template #default="{ row }">
            <ElButton
              link
              size="small"
              @click="openEditContent(row as CsNoteContent)"
            >
              {{ tl('edit') }}
            </ElButton>
            <ElButton
              link
              size="small"
              type="danger"
              @click="removeContent(row as CsNoteContent)"
            >
              {{ tl('form.delete') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>
          <ElEmpty :description="tl('cs.no_content')" />
        </template>
      </ElTable>
    </template>

    <!-- 標題 新增/編輯 -->
    <ElDialog
      v-model="titleDlg.visible"
      append-to-body
      :title="titleDlg.mode === 'add' ? tl('cs.add_note') : tl('cs.edit_note')"
      :width="isMobile ? '92%' : '420'"
    >
      <ElForm label-width="60">
        <ElFormItem :label="tl('list.title')">
          <ElInput v-model="titleDlg.title" maxlength="100" />
        </ElFormItem>
        <ElFormItem :label="tl('list.status')">
          <ElSelect v-model="titleDlg.status" style="width: 100%">
            <ElOption :value="0" :label="tl('cs.incomplete')" />
            <ElOption :value="1" :label="tl('complete')" />
          </ElSelect>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="titleDlg.visible = false">
          {{ tl('form.cancel') }}
        </ElButton>
        <ElButton type="primary" :loading="saving" @click="saveTitle">
          {{ tl('form.save') }}
        </ElButton>
      </template>
    </ElDialog>

    <!-- 內容 新增/編輯 -->
    <ElDialog
      v-model="contentDlg.visible"
      append-to-body
      :title="
        contentDlg.mode === 'add' ? tl('cs.add_content') : tl('cs.edit_content')
      "
      :width="isMobile ? '92%' : '480'"
    >
      <ElInput v-model="contentDlg.content" :rows="5" type="textarea" />
      <template #footer>
        <ElButton @click="contentDlg.visible = false">
          {{ tl('form.cancel') }}
        </ElButton>
        <ElButton type="primary" :loading="saving" @click="saveContent">
          {{ tl('form.save') }}
        </ElButton>
      </template>
    </ElDialog>
  </ElDrawer>
</template>
