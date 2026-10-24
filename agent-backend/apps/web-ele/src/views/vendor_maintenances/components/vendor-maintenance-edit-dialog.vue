<script lang="ts" setup>
import { reactive, ref } from 'vue';

import { useUserStore } from '@vben/stores';

import dayjs from 'dayjs';
import {
  ElButton,
  ElDatePicker,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
} from 'element-plus';

import {
  getAgentViewApi,
  getVendorDropdownByTypeApi,
  getVendorMaintenancesViewApi,
  saveVendorMaintenancesApi,
  searchAgentOptionsApi,
  vendorNameWithId,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'VendorMaintenanceEditDialog' });

const emit = defineEmits<{ saved: [] }>();

const userStore = useUserStore();

const visible = ref(false);
const loading = ref(false);
const saving = ref(false);
const editId = ref<null | number>(null);

const vendors = ref<Array<{ id: number; name: string }>>([]);
const agentOptions = ref<Array<{ id: number; username: string }>>([]);
const excludeOptions = ref<Array<{ id: number; username: string }>>([]);
const agentLoading = ref(false);
const excludeLoading = ref(false);

const form = reactive({
  agent_id: 0 as number | string,
  end_time: '',
  exclude_agent_id: 0 as number | string,
  memo: '',
  start_time: '',
  status: 1 as number | string,
  type: '' as number | string,
  vendor_id: '' as number | string,
});

async function searchAgent(query: string) {
  if (!query) return;
  agentLoading.value = true;
  try {
    const res = await searchAgentOptionsApi(query);
    if (Number(res.code) === 1000) agentOptions.value = res.data ?? [];
  } finally {
    agentLoading.value = false;
  }
}

async function searchExclude(query: string) {
  if (!query) return;
  excludeLoading.value = true;
  try {
    const res = await searchAgentOptionsApi(query);
    if (Number(res.code) === 1000) excludeOptions.value = res.data ?? [];
  } finally {
    excludeLoading.value = false;
  }
}

async function open(id?: number) {
  visible.value = true;
  editId.value = id ?? null;
  loading.value = true;
  agentOptions.value = [];
  excludeOptions.value = [];
  try {
    if (vendors.value.length === 0) {
      vendors.value = (await getVendorDropdownByTypeApi(1)) ?? [];
    }
    if (id) {
      const v = (await getVendorMaintenancesViewApi(id)) as any;
      form.vendor_id = v?.vendor_id ?? '';
      form.type = v?.type ?? '';
      form.start_time = v?.start_time
        ? dayjs(v.start_time).add(8, 'hour').format('YYYY-MM-DD HH:mm')
        : '';
      form.end_time = v?.end_time
        ? dayjs(v.end_time).add(8, 'hour').format('YYYY-MM-DD HH:mm')
        : '';
      form.status = Number(v?.status) === 1 ? 1 : 0;
      form.memo = v?.memo ?? '';
      form.agent_id = v?.agent_id ?? 0;
      form.exclude_agent_id = Number(v?.exclude_agent_id) || 0;
      if (v?.agent_id) {
        agentOptions.value = [
          { id: Number(v.agent_id), username: v?.agent_name ?? '' },
        ];
      }
      if (Number(v?.exclude_agent_id) > 0) {
        excludeOptions.value = [
          {
            id: Number(v.exclude_agent_id),
            username: v?.exclude_agent_name ?? '',
          },
        ];
      }
    } else {
      // 新增預設值：維護代理帶入目前後台帳號、區間預設今日~隔日（+8 時區）
      form.vendor_id = '';
      form.type = '';
      form.start_time = `${dayjs().add(8, 'hour').format('YYYY-MM-DD')} 00:00`;
      form.end_time = `${dayjs().add(32, 'hour').format('YYYY-MM-DD')} 00:00`;
      form.status = 1;
      form.memo = '';
      form.exclude_agent_id = 0;
      const hostId = Number((userStore.userInfo as any)?.host_id ?? 0);
      form.agent_id = hostId;
      if (hostId) {
        const host = (await getAgentViewApi(hostId)) as any;
        agentOptions.value = [{ id: hostId, username: host?.username ?? '' }];
      }
    }
  } finally {
    loading.value = false;
  }
}

async function handleSave() {
  saving.value = true;
  try {
    const res = await saveVendorMaintenancesApi({
      agent_id: form.agent_id,
      end_time: form.end_time,
      exclude_agent_id: form.exclude_agent_id || 0,
      memo: form.memo,
      start_time: form.start_time,
      status: form.status,
      type: form.type,
      update_id: editId.value ?? '',
      vendor_id: form.vendor_id,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message);
      visible.value = false;
      emit('saved');
    } else {
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
    :title="tl('menu_vendor_maintenances')"
    align-center
    width="640"
  >
    <ElForm v-loading="loading" :model="form" label-width="120px">
      <ElFormItem :label="tl('label_vendor')">
        <ElSelect v-model="form.vendor_id" class="w-full" filterable>
          <ElOption
            v-for="v in vendors"
            :key="v.id"
            :label="vendorNameWithId(v)"
            :value="v.id"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem :label="tl('type')">
        <ElSelect v-model="form.type" class="w-full">
          <ElOption :label="tl('vendor_maintenances_type_1')" :value="1" />
          <ElOption :label="tl('vendor_maintenances_type_2')" :value="2" />
          <ElOption :label="tl('vendor_maintenances_type_3')" :value="3" />
        </ElSelect>
      </ElFormItem>
      <ElFormItem :label="tl('label_start_time')">
        <ElDatePicker
          v-model="form.start_time"
          class="!w-full"
          format="YYYY-MM-DD HH:mm"
          type="datetime"
          value-format="YYYY-MM-DD HH:mm"
        />
      </ElFormItem>
      <ElFormItem :label="tl('label_end_time')">
        <ElDatePicker
          v-model="form.end_time"
          class="!w-full"
          format="YYYY-MM-DD HH:mm"
          type="datetime"
          value-format="YYYY-MM-DD HH:mm"
        />
      </ElFormItem>
      <ElFormItem :label="tl('maintenance_agent')">
        <ElSelect
          v-model="form.agent_id"
          :debounce="500"
          :loading="agentLoading"
          :remote-method="searchAgent"
          class="w-full"
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
      <ElFormItem :label="tl('exclude_agent')">
        <ElSelect
          v-model="form.exclude_agent_id"
          :debounce="500"
          :loading="excludeLoading"
          :remote-method="searchExclude"
          class="w-full"
          clearable
          filterable
          remote
        >
          <ElOption
            v-for="a in excludeOptions"
            :key="a.id"
            :label="a.username"
            :value="a.id"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem :label="tl('memo')">
        <ElInput v-model="form.memo" :rows="3" type="textarea" />
      </ElFormItem>
      <ElFormItem :label="tl('enable_status')">
        <ElRadioGroup v-model="form.status">
          <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
          <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
        </ElRadioGroup>
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
