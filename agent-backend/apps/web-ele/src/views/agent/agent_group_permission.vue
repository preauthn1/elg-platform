<script lang="ts" setup>
import type { AgentGroupPermItem } from '#/api';

import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import { ElButton, ElCard, ElCheckbox, ElEmpty, ElMessage } from 'element-plus';

import {
  getAgentGroupPermissionsApi,
  updateAgentGroupPermissionsApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'AgentGroupPermission' });

const route = useRoute();
const userStore = useUserStore();
const targetId = computed(
  () => (route.query.agent_id as string) || (userStore.userInfo as any)?.id,
);

const loading = ref(false);
const saving = ref(false);
const editing = ref(false);
const groups = ref<AgentGroupPermItem[]>([]);
const checked = ref<Set<number | string>>(new Set());
const agentName = ref('');
const agentId = ref<number | string>('');

/** 依 group_type 分區 */
const grouped = computed(() => {
  const map = new Map<string, AgentGroupPermItem[]>();
  for (const g of groups.value) {
    const t = String(g.group_type ?? 'other');
    if (!map.has(t)) map.set(t, []);
    map.get(t)?.push(g);
  }
  return [...map.entries()].map(([type, items]) => ({ items, type }));
});

function typeLabel(type: string) {
  return tl(`agent_group_type_${type}`, tl('menu_agent_other_setting'));
}

function isChecked(id: number | string) {
  return checked.value.has(id);
}
function toggle(id: number | string, val: boolean) {
  const s = new Set(checked.value);
  if (val) s.add(id);
  else s.delete(id);
  checked.value = s;
}

async function load() {
  loading.value = true;
  try {
    const res = await getAgentGroupPermissionsApi(targetId.value);
    if (Number(res.code) === 200 && res.data) {
      groups.value = res.data.groups ?? [];
      checked.value = new Set(res.data.has_permissions);
      agentName.value = res.data.agent_name ?? '';
      agentId.value = res.data.agent_id ?? targetId.value;
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    loading.value = false;
  }
}

function cancel() {
  editing.value = false;
  load();
}

async function save() {
  saving.value = true;
  try {
    const res = await updateAgentGroupPermissionsApi({
      agent_id: agentId.value,
      group_permission: [...checked.value],
    });
    if (Number(res.code) === 200) {
      ElMessage.success(tl('success'));
      editing.value = false;
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <Page :title="`${tl('agnet_management')} > ${tl('agent_group_permission')}`">
    <div v-if="agentName" class="mb-3 text-base">
      <b>{{ tl('agent') }}</b
      >：{{ agentName }} ({{ agentId }})
    </div>
    <ElCard v-loading="loading" shadow="never">
      <template #header>
        <div class="flex items-center justify-between">
          <span class="text-lg font-bold">{{
            tl('agent_group_permission')
          }}</span>
          <div class="flex gap-2">
            <ElButton
              v-if="!editing"
              size="small"
              type="primary"
              @click="editing = true"
            >
              {{ tl('edit') }}
            </ElButton>
            <template v-else>
              <ElButton
                :loading="saving"
                size="small"
                type="success"
                @click="save"
              >
                {{ tl('btn_update') }}
              </ElButton>
              <ElButton size="small" @click="cancel">
                {{ tl('cancel') }}
              </ElButton>
            </template>
          </div>
        </div>
      </template>

      <div v-for="grp in grouped" :key="grp.type" class="mb-4">
        <h4 class="mb-2 border-b pb-1 font-bold">{{ typeLabel(grp.type) }}</h4>
        <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
          <ElCheckbox
            v-for="g in grp.items"
            :key="g.group_id"
            :disabled="!editing"
            :model-value="isChecked(g.group_id)"
            @update:model-value="(v: any) => toggle(g.group_id, Boolean(v))"
          >
            {{ g.group_id }} {{ g.group_name }}
          </ElCheckbox>
        </div>
      </div>
      <ElEmpty
        v-if="!loading && groups.length === 0"
        :description="tl('no_record')"
      />
    </ElCard>
  </Page>
</template>
