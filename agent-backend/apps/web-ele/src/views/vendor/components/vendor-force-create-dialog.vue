<script lang="ts" setup>
import type { AgentVendorRow } from '#/api';

import { ref } from 'vue';

import { ElCard, ElDialog, ElEmpty, ElMessage, ElSwitch } from 'element-plus';

import {
  agentVendorForceCreateApi,
  getAgentVendorListApi,
  getAgentVendorTemplateApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'VendorForceCreateDialog' });

interface TemplateVendor {
  image?: string;
  name?: string;
  vendor_id: number;
}

const visible = ref(false);
const loading = ref(false);
const agentId = ref(0);
const templates = ref<TemplateVendor[]>([]);
/** 已設定遊戲商：vendor_id -> row */
const ownMap = ref<Record<number, AgentVendorRow>>({});

function match(vendorId: number) {
  return ownMap.value[vendorId];
}
function isEnable(vendorId: number) {
  return Number(match(vendorId)?.status) === 1;
}
function backendShow(vendorId: number) {
  return Number(match(vendorId)?.backend_show ?? 0);
}

async function load() {
  loading.value = true;
  try {
    const tpl = await getAgentVendorTemplateApi(agentId.value);
    templates.value =
      Number(tpl.code) === 1000 ? ((tpl.data as TemplateVendor[]) ?? []) : [];
    if (Number(tpl.code) !== 1000) ElMessage.warning(tpl.message);

    const own = (await getAgentVendorListApi(agentId.value)) ?? [];
    ownMap.value = {};
    for (const row of own) ownMap.value[Number(row.id)] = row;
  } finally {
    loading.value = false;
  }
}

function open(id: number) {
  agentId.value = id;
  visible.value = true;
  templates.value = [];
  ownMap.value = {};
  load();
}

/** type: 1=授權 2=後台顯示 */
async function toggle(vendorId: number, type: 1 | 2) {
  const enable = isEnable(vendorId);
  const show = backendShow(vendorId);
  const payload =
    type === 1
      ? {
          agent_id: agentId.value,
          id: vendorId,
          status: enable ? 0 : 1,
          backend_show: show,
        }
      : {
          agent_id: agentId.value,
          id: vendorId,
          status: enable ? 1 : 0,
          backend_show: show === 1 ? 0 : 1,
        };
  const res = await agentVendorForceCreateApi(payload);
  if (Number(res.code) === 1000) {
    await load();
  } else {
    ElMessage.error(Number(res.code) === 2002 ? String(res.data) : res.message);
    await load();
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('agent_vendor_force_create')"
    align-center
    width="900"
  >
    <div v-loading="loading">
      <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
        <ElCard
          v-for="vendor in templates"
          :key="vendor.vendor_id"
          body-class="p-3"
          shadow="hover"
        >
          <h5 class="mb-2 truncate font-medium">{{ vendor.name }}</h5>
          <div class="flex h-[120px] items-center justify-center">
            <img
              v-if="vendor.image"
              :src="vendor.image"
              class="max-h-[120px] max-w-full object-contain"
            />
          </div>
          <div class="mt-2 space-y-2">
            <div class="flex items-center justify-between">
              <span class="text-sm text-gray-600">{{
                tl('enable_status')
              }}</span>
              <ElSwitch
                :model-value="isEnable(vendor.vendor_id)"
                @change="toggle(vendor.vendor_id, 1)"
              />
            </div>
            <div class="flex items-center justify-between">
              <span class="text-sm text-gray-600">
                {{ tl('backend_show_enable') }}
              </span>
              <ElSwitch
                :model-value="backendShow(vendor.vendor_id) === 1"
                @change="toggle(vendor.vendor_id, 2)"
              />
            </div>
          </div>
        </ElCard>
      </div>
      <ElEmpty
        v-if="!loading && templates.length === 0"
        :description="tl('no_record')"
      />
    </div>
  </ElDialog>
</template>
