<script lang="ts" setup>
import { ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElTree,
} from 'element-plus';

import { getAgentHierarchyApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'AgentHierarchy' });

interface TreeNode {
  children: TreeNode[];
  id: number;
  label: string;
}

const username = ref('');
const loading = ref(false);
const tree = ref<TreeNode[]>([]);

/** zTree simpleData（id/pId/name）→ ElTree 樹狀結構 */
function buildTree(
  rows: Array<{ id: number; name: string; pId: number }>,
): TreeNode[] {
  const map = new Map<number, TreeNode>();
  for (const r of rows) {
    map.set(r.id, { children: [], id: r.id, label: r.name });
  }
  const roots: TreeNode[] = [];
  for (const r of rows) {
    const node = map.get(r.id);
    if (!node) continue;
    const parent = map.get(r.pId);
    if (parent && r.pId !== r.id) {
      parent.children.push(node);
    } else {
      roots.push(node);
    }
  }
  return roots;
}

async function handleSearch() {
  loading.value = true;
  try {
    const res = await getAgentHierarchyApi(username.value);
    if (Number(res.code) === 1000) {
      tree.value = buildTree(res.data ?? []);
    } else {
      tree.value = [];
      // 2002 顯示 data，其餘顯示 message
      ElMessage.error(
        Number(res.code) === 2002 ? String((res as any).data) : res.message,
      );
    }
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <Page
    :title="`${tl('agnet_management')} > ${tl('menu_agent_hierarchy_tree')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <ElForm inline @submit.prevent="handleSearch">
        <ElFormItem :label="tl('agent_account')">
          <ElInput v-model="username" class="!w-60" clearable />
        </ElFormItem>
        <ElFormItem>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </ElFormItem>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTree
        v-loading="loading"
        :data="tree"
        :empty-text="tl('no_record')"
        default-expand-all
        :props="{ children: 'children', label: 'label' }"
      />
    </ElCard>
  </Page>
</template>
