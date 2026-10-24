<script lang="ts" setup>
import type { ThreeHierarchyTreeNode } from '#/api';

import { ref } from 'vue';
import { useRouter } from 'vue-router';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElTree,
} from 'element-plus';

import { getThreeHierarchyMemberTreeApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'ThreeHierarchySearchHierarchy' });

interface TreeNode {
  children: TreeNode[];
  id: number;
  is_agent: number;
  label: string;
  name: string;
}

const router = useRouter();
const username = ref('');
const loading = ref(false);
const tree = ref<TreeNode[]>([]);

function buildTree(rows: ThreeHierarchyTreeNode[]): TreeNode[] {
  const map = new Map<number, TreeNode>();
  for (const r of rows) {
    map.set(r.id, {
      children: [],
      id: r.id,
      is_agent: Number(r.is_agent ?? 0),
      label: r.name,
      name: r.name,
    });
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
    const res = await getThreeHierarchyMemberTreeApi(username.value);
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

/** 點節點 → 開業績查詢；時段：14 點前算前兩日、後算前一日 */
function handleNodeClick(node: TreeNode) {
  const now = dayjs();
  const time = now
    .subtract(now.hour() >= 14 ? 1 : 2, 'day')
    .format('YYYY-MM-DD');
  const query =
    node.is_agent === 1
      ? {
          agent_username: node.name,
          end_time: time,
          start_time: time,
          username: '',
        }
      : {
          agent_username: '',
          end_time: time,
          start_time: time,
          username: node.name,
        };
  const route = router.resolve({ path: '/three_hierarchy/performance', query });
  window.open(route.href, '_blank');
}
</script>

<template>
  <Page
    :title="`${tl('menu_three_layers_agents_mode_including_agent')} > ${tl('menu_search_agent_hierarchy')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <ElForm inline @submit.prevent="handleSearch">
        <ElFormItem :label="tl('member_account')">
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
        :props="{ children: 'children', label: 'label' }"
        default-expand-all
        node-key="id"
        @node-click="handleNodeClick"
      />
    </ElCard>
  </Page>
</template>
