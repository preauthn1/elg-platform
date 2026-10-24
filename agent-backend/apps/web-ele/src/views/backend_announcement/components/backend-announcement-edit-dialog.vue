<script lang="ts" setup>
import type { AnnouncementAgent } from '#/api';

import { computed, ref } from 'vue';

import {
  ElButton,
  ElCheckbox,
  ElDialog,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
} from 'element-plus';

import {
  getAnnouncementAgentsApi,
  getBackendAnnouncementViewApi,
  saveBackendAnnouncementApi,
} from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const editId = ref<number | string>('');
const isEdit = computed(() => Boolean(editId.value));

const hosts = ref<AnnouncementAgent[]>([]);
const childMap = ref<Record<string, AnnouncementAgent[]>>({});
const selected = ref<Set<string>>(new Set());
const saving = ref(false);
let agentsLoaded = false;

const form = ref({
  content: '',
  is_top: 0 as number,
  type_id: 1 as number | string,
});

const allIds = computed(() => {
  const ids: string[] = [];
  for (const h of hosts.value) {
    ids.push(String(h.id));
    for (const c of childMap.value[String(h.id)] ?? []) ids.push(String(c.id));
  }
  return ids;
});

const selectAll = computed({
  get: () =>
    allIds.value.length > 0 &&
    allIds.value.every((id) => selected.value.has(id)),
  set: (val: boolean) => {
    selected.value = val ? new Set(allIds.value) : new Set();
  },
});

function isChecked(id: number | string) {
  return selected.value.has(String(id));
}

function toggleHost(host: AnnouncementAgent, val: boolean) {
  const next = new Set(selected.value);
  const ids = [
    String(host.id),
    ...(childMap.value[String(host.id)] ?? []).map((c) => String(c.id)),
  ];
  for (const id of ids) {
    if (val) next.add(id);
    else next.delete(id);
  }
  selected.value = next;
}

function toggleOne(id: number | string, val: boolean) {
  const next = new Set(selected.value);
  if (val) next.add(String(id));
  else next.delete(String(id));
  selected.value = next;
}

async function handleSave() {
  saving.value = true;
  try {
    const res = await saveBackendAnnouncementApi({
      agent_ids: [...selected.value],
      announcement_content: form.value.content,
      id: editId.value || undefined,
      is_top: form.value.is_top,
      type_id: form.value.type_id,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}

async function open(id: number | string = '') {
  editId.value = id || '';
  form.value = { content: '', is_top: 0, type_id: 1 };
  selected.value = new Set();
  visible.value = true;
  if (!agentsLoaded) {
    const agents = await getAnnouncementAgentsApi().catch(() => null);
    hosts.value = agents?.host ?? [];
    childMap.value = agents?.child ?? {};
    agentsLoaded = true;
  }
  if (isEdit.value) {
    const d = await getBackendAnnouncementViewApi(editId.value).catch(
      () => null,
    );
    if (d) {
      form.value.type_id = d.announcement?.type ?? 1;
      form.value.content = d.announcement?.content ?? '';
      form.value.is_top = Number(d.announcement?.is_top ?? 0);
      selected.value = new Set((d.agent_ids ?? []).map(String));
    }
  } else {
    selected.value = new Set(allIds.value);
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('menu_backend_announcement')} - ${
      isEdit ? tl('edit') : tl('add')
    }`"
    top="6vh"
    width="860"
  >
    <ElFormItem>
      <ElCheckbox v-model="form.is_top" :false-value="0" :true-value="1">
        {{ tl('is_top') }}
      </ElCheckbox>
    </ElFormItem>
    <ElFormItem :label="tl('choose_announcement_category')" label-width="160">
      <ElSelect v-model="form.type_id" class="!w-60">
        <ElOption :label="tl('general_announcement')" :value="1" />
        <ElOption :label="tl('vendor_announcement')" :value="2" />
        <ElOption :label="tl('emergency_announcement')" :value="3" />
        <ElOption :label="tl('important_announcement')" :value="4" />
        <ElOption :label="tl('system_announcement')" :value="5" />
      </ElSelect>
    </ElFormItem>
    <ElFormItem :label="tl('announcement_content')" label-width="160">
      <ElInput v-model="form.content" :rows="4" type="textarea" />
    </ElFormItem>

    <ElFormItem :label="tl('choose_agent_announcement')" label-width="160">
      <div class="w-full">
        <ElCheckbox v-model="selectAll">{{ tl('all') }}</ElCheckbox>
        <div
          class="border-border mt-2 max-h-72 overflow-y-auto rounded border p-3"
        >
          <div v-for="h in hosts" :key="h.id" class="mb-2">
            <ElCheckbox
              :model-value="isChecked(h.id)"
              @change="(v) => toggleHost(h, Boolean(v))"
            >
              <b>{{ h.username }}</b>
            </ElCheckbox>
            <div class="ml-6 flex flex-wrap gap-x-6">
              <ElCheckbox
                v-for="c in childMap[String(h.id)] ?? []"
                :key="c.id"
                :model-value="isChecked(c.id)"
                @change="(v) => toggleOne(c.id, Boolean(v))"
              >
                {{ c.username }}
              </ElCheckbox>
            </div>
          </div>
        </div>
      </div>
    </ElFormItem>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
