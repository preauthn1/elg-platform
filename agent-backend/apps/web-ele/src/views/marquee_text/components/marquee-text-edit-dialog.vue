<script lang="ts" setup>
import { computed, reactive, ref } from 'vue';

import { useUserStore } from '@vben/stores';

import dayjs from 'dayjs';
import {
  ElButton,
  ElDatePicker,
  ElDialog,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
} from 'element-plus';

import {
  getLanguagesApi,
  getMarqueeTextViewApi,
  saveMarqueeTextApi,
  searchAgentOptionsApi,
} from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const userStore = useUserStore();

const visible = ref(false);
const editId = ref<number | string>('');
const isEdit = computed(() => Boolean(editId.value));

const languages = ref<Array<{ id: number | string; name: string }>>([]);
const agentOptions = ref<Array<{ id: number | string; username: string }>>([]);
const excludeOptions = ref<Array<{ id: number | string; username: string }>>(
  [],
);
const searching = ref(false);
const saving = ref(false);
let languagesLoaded = false;

const form = reactive(makeDefaults());

function makeDefaults() {
  return {
    agent_id: '' as number | string,
    contents: {} as Record<string, string>,
    end_date: dayjs().add(8, 'hour').add(1, 'month').format('YYYY-MM-DD 23:59'),
    exclude_agent_id: 0 as number | string,
    location: 1 as number | string,
    start_date: dayjs().add(8, 'hour').format('YYYY-MM-DD 00:00'),
    status: 1 as number | string,
    type_id: 1 as number | string,
  };
}

async function remoteAgent(query: string, target: 'agent' | 'exclude') {
  if (!query) return;
  searching.value = true;
  try {
    const res = await searchAgentOptionsApi(query).catch(() => null);
    const data = (res?.data ?? []) as Array<{ id: number; username: string }>;
    if (target === 'agent') agentOptions.value = data;
    else excludeOptions.value = data;
  } finally {
    searching.value = false;
  }
}

async function handleSave() {
  saving.value = true;
  try {
    const res = await saveMarqueeTextApi({
      agent_id: form.agent_id,
      contents: form.contents,
      end_date: form.end_date,
      exclude_agent_id: form.exclude_agent_id,
      id: editId.value || undefined,
      location: form.location,
      start_date: form.start_date,
      status: form.status,
      type_id: form.type_id,
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
  Object.assign(form, makeDefaults());
  agentOptions.value = [];
  excludeOptions.value = [];
  visible.value = true;
  if (!languagesLoaded) {
    languages.value = (await getLanguagesApi().catch(() => [])) ?? [];
    languagesLoaded = true;
  }
  if (isEdit.value) {
    const d = await getMarqueeTextViewApi(editId.value).catch(() => null);
    if (d) {
      const m = d.marquees;
      form.agent_id = m.agent_id;
      agentOptions.value = [{ id: m.agent_id, username: m.agent_name }];
      form.exclude_agent_id = m.exclude_agent_id || 0;
      if (m.exclude_agent_id && Number(m.exclude_agent_id) > 0) {
        excludeOptions.value = [
          { id: m.exclude_agent_id, username: m.exclude_agent_name },
        ];
      }
      form.type_id = m.type ?? 1;
      form.location = m.location ?? 1;
      form.status = Number(m.status ?? 1);
      form.start_date = (m.start_time ?? '').slice(0, 16) || form.start_date;
      form.end_date = (m.end_time ?? '').slice(0, 16) || form.end_date;
      const c: Record<string, string> = {};
      for (const [lid, v] of Object.entries(d.contents ?? {})) {
        c[lid] = v?.content ?? '';
      }
      form.contents = c;
    }
  } else {
    const info = userStore.userInfo as any;
    const selfId = info?.host_id ?? info?.id ?? '';
    form.agent_id = selfId;
    agentOptions.value = [
      { id: selfId, username: info?.realName ?? info?.username ?? '' },
    ];
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('menu_marquee_text')} - ${isEdit ? tl('edit') : tl('add')}`"
    width="860"
  >
    <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
      <ElFormItem :label="tl('agent_account')" label-width="120">
        <ElSelect
          v-model="form.agent_id"
          :debounce="500"
          :loading="searching"
          :remote-method="(q) => remoteAgent(q, 'agent')"
          class="!w-full"
          filterable
          remote
        >
          <ElOption
            v-for="a in agentOptions"
            :key="a.id"
            :label="a.username"
            :value="a.id"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem :label="tl('exclude_agent')" label-width="120">
        <ElSelect
          v-model="form.exclude_agent_id"
          :debounce="500"
          :loading="searching"
          :remote-method="(q) => remoteAgent(q, 'exclude')"
          class="!w-full"
          filterable
          remote
        >
          <ElOption :label="tl('please_choose')" :value="0" />
          <ElOption
            v-for="a in excludeOptions"
            :key="a.id"
            :label="a.username"
            :value="a.id"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem :label="tl('type')" label-width="120">
        <ElSelect v-model="form.type_id" class="!w-full">
          <ElOption :label="tl('general_marquee')" :value="1" />
          <ElOption :label="tl('important_marquee')" :value="2" />
        </ElSelect>
      </ElFormItem>
      <ElFormItem :label="tl('maquee_location')" label-width="120">
        <ElSelect v-model="form.location" class="!w-full">
          <ElOption :label="tl('maquee_location_lobby')" :value="1" />
          <ElOption :label="tl('maquee_location_chatroom')" :value="2" />
          <ElOption :label="tl('maquee_location_agent_backend')" :value="3" />
        </ElSelect>
      </ElFormItem>
      <ElFormItem :label="tl('status')" label-width="120">
        <ElRadioGroup v-model="form.status">
          <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
          <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
        </ElRadioGroup>
      </ElFormItem>
      <div></div>
      <ElFormItem :label="tl('label_start_time')" label-width="120">
        <ElDatePicker
          v-model="form.start_date"
          class="!w-full"
          format="YYYY-MM-DD HH:mm"
          type="datetime"
          value-format="YYYY-MM-DD HH:mm"
        />
      </ElFormItem>
      <ElFormItem :label="tl('label_end_time')" label-width="120">
        <ElDatePicker
          v-model="form.end_date"
          class="!w-full"
          format="YYYY-MM-DD HH:mm"
          type="datetime"
          value-format="YYYY-MM-DD HH:mm"
        />
      </ElFormItem>
    </div>

    <div class="border-border mt-2 rounded border p-3">
      <ElFormItem
        v-for="lang in languages"
        :key="lang.id"
        :label="lang.name"
        label-width="120"
      >
        <ElInput v-model="form.contents[lang.id]" />
      </ElFormItem>
    </div>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
