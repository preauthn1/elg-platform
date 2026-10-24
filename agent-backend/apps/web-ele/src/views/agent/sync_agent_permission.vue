<script lang="ts" setup>
import type { AgentMenuNode } from '#/api';

import { onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import { ElButton, ElCard, ElCheckbox, ElMessage } from 'element-plus';

import {
  getAgentChildAccountsApi,
  getAgentMenuTreeApi,
  syncChildPermissionApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'AgentSyncPermission' });

const userStore = useUserStore();

const loading = ref(false);
const errorMsg = ref('');
const menuTree = ref<AgentMenuNode[]>([]);
const childs = ref<Array<{ id: number; username: string }>>([]);
const childCount = ref(0);
const saving = ref(false);

function menuTitle(node: AgentMenuNode) {
  return tl(`menu_${node.code}`, node.name);
}

async function load() {
  loading.value = true;
  try {
    const res = await getAgentChildAccountsApi();
    if (Number(res.code) !== 200) {
      errorMsg.value = res.message;
      return;
    }
    // 子帳號本身不可用此功能
    if (!res.data?.is_not_child_account) {
      errorMsg.value = tl('is_subaccount_and_is_not_available');
      return;
    }
    childs.value = res.data?.childs ?? [];
    childCount.value = Number(res.data?.childCount ?? 0);
    const agentId = Number((userStore.userInfo as any)?.id ?? 0);
    menuTree.value = (await getAgentMenuTreeApi(agentId)) ?? [];
  } finally {
    loading.value = false;
  }
}

/** 同步（無參數） */
async function handleSync() {
  saving.value = true;
  try {
    const res = await syncChildPermissionApi();
    if (Number(res.code) === 200) {
      ElMessage.success(res.message);
    } else {
      ElMessage.error(`${res.message}.${res.data ?? ''}`);
    }
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <Page
    :title="`${tl('agnet_management')} > ${tl('menu_agent_sync_permissiom')}`"
  >
    <ElCard v-loading="loading" shadow="never">
      <div v-if="errorMsg" class="py-6 text-center text-red-500">
        {{ errorMsg }}
      </div>
      <div v-else class="grid grid-cols-1 gap-6 md:grid-cols-2">
        <!-- 目前帳號擁有的權限（唯讀展示） -->
        <div>
          <h3 class="mb-2 border-b pb-2 text-base font-bold">
            {{ tl('permissions_owned_by_current_account') }}
          </h3>
          <div class="max-h-[600px] overflow-y-auto opacity-70">
            <div v-for="m1 in menuTree" :key="m1.id">
              <ElCheckbox :model-value="true" disabled>
                {{ menuTitle(m1) }}
              </ElCheckbox>
              <div v-for="m2 in m1.submenu ?? []" :key="m2.id" class="pl-4">
                <ElCheckbox :model-value="true" disabled>
                  {{ menuTitle(m2) }}
                </ElCheckbox>
                <div v-for="m3 in m2.submenu ?? []" :key="m3.id" class="pl-8">
                  <ElCheckbox :model-value="true" disabled>
                    {{ menuTitle(m3) }}
                  </ElCheckbox>
                </div>
              </div>
            </div>
          </div>
        </div>
        <!-- 子帳號列表 -->
        <div>
          <h3 class="mb-2 border-b pb-2 text-base font-bold">
            {{ tl('expected_to_all_subaccounts') }} ({{ childCount }})
          </h3>
          <div class="grid grid-cols-2 gap-1 text-sm">
            <template v-for="c in childs" :key="c.id">
              <b>{{ c.username }}</b>
              <span>{{ c.id }}</span>
            </template>
          </div>
          <div v-if="childCount > 0" class="mt-6">
            <ElButton :loading="saving" type="primary" @click="handleSync">
              {{ tl('sync') }}
            </ElButton>
          </div>
        </div>
      </div>
    </ElCard>
  </Page>
</template>
