<script lang="ts" setup>
import type { AgentVendorRow } from '#/api';

import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import { ElButton, ElCard, ElEmpty, ElMessage, ElSwitch } from 'element-plus';

import {
  agentVendorSettingApi,
  getAgentVendorListApi,
  getAgentViewApi,
} from '#/api';
import { tl } from '#/locales';
import { hasMenuCode } from '#/router/agent-menu';

import VendorForceCreateDialog from './components/vendor-force-create-dialog.vue';

defineOptions({ name: 'VendorIndex' });

const route = useRoute();
const userStore = useUserStore();
const selfId = Number((userStore.userInfo as any)?.id ?? 0);

const queryId = computed(() =>
  route.query.id ? Number(route.query.id) : null,
);

const loading = ref(false);
const agentId = ref(0);
const username = ref('');
const parentUsername = ref('');
/** 上級各遊戲商授權狀態：vendor_id -> status */
const parentStatusMap = ref<Record<number, number>>({});
const list = ref<AgentVendorRow[]>([]);

const forceRef = ref<InstanceType<typeof VendorForceCreateDialog>>();

/** 強制設定按鈕：需 agent_vendor_force_create 權限 + 帶入指定代理 id */
const showForceCreate = computed(
  () => hasMenuCode('agent_vendor_force_create') && queryId.value !== null,
);

function isParentDisabled(row: AgentVendorRow) {
  return Number(parentStatusMap.value[row.id]) === 0;
}

async function loadData() {
  loading.value = true;
  try {
    const targetId = queryId.value ?? selfId;
    const agent = (await getAgentViewApi(targetId)) as any;
    agentId.value = Number(agent?.id ?? targetId);
    username.value = agent?.username ?? '';
    parentUsername.value = agent?.parent_username ?? '';
    const parent = Number(agent?.parent ?? 0);

    list.value = (await getAgentVendorListApi(agentId.value)) ?? [];

    parentStatusMap.value = {};
    if (parent > 0) {
      const parentList = (await getAgentVendorListApi(parent)) ?? [];
      for (const row of parentList) {
        parentStatusMap.value[row.id] = Number(row.status);
      }
    }
  } finally {
    loading.value = false;
  }
}

/** dataSwitch: 1=授權 2=自身授權 3=後台顯示 */
async function toggle(row: AgentVendorRow, dataSwitch: 1 | 2 | 3) {
  const payload: Parameters<typeof agentVendorSettingApi>[0] = {
    agent_id: agentId.value,
    id: row.id,
    status: Number(row.status),
    self_status: Number(row.self_status),
    backend_show: Number(row.backend_show),
    data_switch: dataSwitch,
  };
  if (dataSwitch === 1) payload.status = Number(row.status) === 1 ? 0 : 1;
  if (dataSwitch === 2)
    payload.self_status = Number(row.self_status) === 1 ? 0 : 1;
  if (dataSwitch === 3)
    payload.backend_show = Number(row.backend_show) === 1 ? 0 : 1;

  const res = await agentVendorSettingApi(payload);
  if (Number(res.code) === 1000) {
    await loadData();
  } else {
    ElMessage.error(Number(res.code) === 2002 ? String(res.data) : res.message);
    await loadData();
  }
}

onMounted(loadData);
</script>

<template>
  <Page :title="`${tl('system_management')} > ${tl('menu_vendor')}`">
    <ElCard v-loading="loading" shadow="never">
      <div class="mb-4 flex flex-wrap items-center gap-x-6 gap-y-2">
        <span>
          <span class="text-gray-500">{{ tl('username') }}：</span>
          <span class="font-medium">{{ username }}</span>
        </span>
        <span>
          <span class="text-gray-500">
            {{ tl('unlimit_parent_member_id') }}：
          </span>
          <span class="font-medium">{{ parentUsername }}</span>
        </span>
        <ElButton
          v-if="showForceCreate"
          type="success"
          @click="forceRef?.open(agentId)"
        >
          {{ tl('agent_vendor_force_create') }}
        </ElButton>
      </div>

      <div
        class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6"
      >
        <ElCard
          v-for="row in list"
          :key="row.id"
          :class="{ 'opacity-50': isParentDisabled(row) }"
          body-class="p-3"
          shadow="hover"
        >
          <h5 class="mb-2 truncate font-medium" :title="String(row.id)">
            {{ row.name }}
          </h5>
          <div class="flex h-[120px] items-center justify-center">
            <img
              v-if="row.image"
              :src="row.image"
              :title="String(row.id)"
              class="max-h-[120px] max-w-full object-contain"
            />
          </div>
          <div class="mt-2 space-y-2">
            <div class="flex items-center justify-between">
              <span class="text-sm text-gray-600">{{
                tl('enable_status')
              }}</span>
              <ElSwitch
                :disabled="isParentDisabled(row)"
                :model-value="Number(row.status) === 1"
                @change="toggle(row as AgentVendorRow, 1)"
              />
            </div>
            <div class="flex items-center justify-between">
              <span class="text-sm text-gray-600">
                {{ tl('enable_self_status') }}
              </span>
              <ElSwitch
                :disabled="isParentDisabled(row)"
                :model-value="Number(row.self_status) === 1"
                @change="toggle(row as AgentVendorRow, 2)"
              />
            </div>
            <div class="flex items-center justify-between">
              <span class="text-sm text-gray-600">
                {{ tl('backend_show_enable') }}
              </span>
              <ElSwitch
                :model-value="Number(row.backend_show) === 1"
                @change="toggle(row as AgentVendorRow, 3)"
              />
            </div>
          </div>
        </ElCard>
      </div>
      <ElEmpty
        v-if="!loading && list.length === 0"
        :description="tl('no_record')"
      />
    </ElCard>

    <VendorForceCreateDialog ref="forceRef" />
  </Page>
</template>
