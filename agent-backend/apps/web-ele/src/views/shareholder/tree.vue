<script lang="ts" setup>
import type { ShareholderTreeNode } from '#/api';

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

import { getShareholderHierarchyApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'ShareholderTree' });

interface TreeNode {
  children: TreeNode[];
  id: number;
  label: string;
}

const username = ref('');
const loading = ref(false);
const tree = ref<TreeNode[]>([]);

/** zTree simpleData（id/pId/name）→ ElTree 樹狀結構 */
function buildTree(rows: ShareholderTreeNode[]): TreeNode[] {
  const map = new Map<number, TreeNode>();
  for (const r of rows) {
    map.set(r.id, { children: [], id: r.id, label: r.name });
  }
  const roots: TreeNode[] = [];
  for (const r of rows) {
    const node = map.get(r.id);
    if (!node) continue;
    const parent = map.get(r.pId);
    if (parent && r.pId !== r.id) parent.children.push(node);
    else roots.push(node);
  }
  return roots;
}

async function handleSearch() {
  loading.value = true;
  try {
    const res = await getShareholderHierarchyApi(username.value);
    if (Number(res.code) === 1000) {
      tree.value = buildTree(res.data ?? []);
    } else {
      tree.value = [];
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
    :title="`${tl('menu_shareholder')} > ${tl('menu_agent_hierarchy_tree')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <ElForm inline @submit.prevent="handleSearch">
        <ElFormItem :label="tl('agent_account')">
          <ElInput v-model="username" class="!w-60" clearable />
        </ElFormItem>
        <ElFormItem>
          <ElButton :loading="loading" type="primary" @click="handleSearch">
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
        :props="{ children: 'children', label: 'label' }"
        default-expand-all
      />
    </ElCard>
  </Page>
</template>
