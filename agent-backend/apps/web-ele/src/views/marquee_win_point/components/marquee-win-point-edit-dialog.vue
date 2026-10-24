<script lang="ts" setup>
import { computed, reactive, ref } from 'vue';

import { useUserStore } from '@vben/stores';

import {
  ElButton,
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
  getMarqueeWinPointViewApi,
  getVendorDropdownByTypeApi,
  saveMarqueeWinPointApi,
  searchAgentOptionsApi,
} from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const userStore = useUserStore();

const visible = ref(false);
const editId = ref<number | string>('');
const isEdit = computed(() => Boolean(editId.value));

const vendors = ref<Array<{ id: number | string; name: string }>>([]);
const agentOptions = ref<Array<{ id: number | string; username: string }>>([]);
const excludeOptions = ref<Array<{ id: number | string; username: string }>>(
  [],
);
const searching = ref(false);
const saving = ref(false);
let vendorsLoaded = false;

const form = reactive(makeDefaults());

function makeDefaults() {
  return {
    agent_id: '' as number | string,
    exclude_agent_id: 0 as number | string,
    status: 1 as number | string,
    trigger_amount: '' as number | string,
    vendor_id: '' as number | string,
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
    const res = await saveMarqueeWinPointApi({
      agent_id: form.agent_id,
      exclude_agent_id: form.exclude_agent_id,
      id: editId.value || undefined,
      status: form.status,
      trigger_amount: form.trigger_amount,
      vendor_id: form.vendor_id,
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
  if (!vendorsLoaded) {
    vendors.value = (await getVendorDropdownByTypeApi(1).catch(() => [])) ?? [];
    vendorsLoaded = true;
  }
  if (isEdit.value) {
    const d = await getMarqueeWinPointViewApi(editId.value).catch(() => null);
    if (d) {
      const m = d.marquee_win_points;
      form.agent_id = m.agent_id;
      agentOptions.value = [{ id: m.agent_id, username: m.agent_name }];
      form.exclude_agent_id = m.exclude_agent_id || 0;
      if (m.exclude_agent_id && Number(m.exclude_agent_id) > 0) {
        excludeOptions.value = [
          { id: m.exclude_agent_id, username: m.exclude_agent_name },
        ];
      }
      form.vendor_id = m.vendor_id ?? '';
      form.trigger_amount = m.trigger_amount ?? '';
      form.status = Number(m.status ?? 1) === 1 ? 1 : 0;
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
    :title="`${tl('menu_marquee_win_point')} - ${isEdit ? tl('edit') : tl('add')}`"
    width="560"
  >
    <ElFormItem label-width="120" required>
      <template #label>{{ tl('agent_account') }}</template>
      <ElSelect
        v-model="form.agent_id"
        :debounce="500"
        :loading="searching"
        :remote-method="(q: string) => remoteAgent(q, 'agent')"
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
        :remote-method="(q: string) => remoteAgent(q, 'exclude')"
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
    <ElFormItem :label="tl('label_vendor')" label-width="120">
      <ElSelect v-model="form.vendor_id" class="!w-full">
        <ElOption :label="tl('please_choose')" value="" />
        <ElOption
          v-for="v in vendors"
          :key="v.id"
          :label="v.name"
          :value="v.id"
        />
      </ElSelect>
    </ElFormItem>
    <ElFormItem :label="tl('trigger_amount')" label-width="120">
      <ElInput v-model="form.trigger_amount" placeholder="1.0000" />
    </ElFormItem>
    <ElFormItem :label="tl('status')" label-width="120">
      <ElRadioGroup v-model="form.status">
        <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
        <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
      </ElRadioGroup>
    </ElFormItem>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
