<script lang="ts" setup>
import type { AgentGameListRow, VendorGameRow } from '#/api';

import { computed, ref } from 'vue';

import {
  ElButton,
  ElCheckbox,
  ElCheckboxGroup,
  ElDialog,
  ElMessage,
} from 'element-plus';

import { getAgentGameApi, saveAgentGameApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'AgentGameEditDialog' });

const emit = defineEmits<{ saved: [] }>();

interface Group {
  game_type_name: string;
  items: VendorGameRow[];
  type_id: number;
}

const visible = ref(false);
const loading = ref(false);
const saving = ref(false);

const current = ref<AgentGameListRow | null>(null);
const groups = ref<Group[]>([]);
const selected = ref<number[]>([]);

const selectedSet = computed(() => new Set(selected.value));

const allChecked = computed({
  get() {
    const all = groups.value.flatMap((g) => g.items.map((i) => i.id));
    return all.length > 0 && all.every((id) => selectedSet.value.has(id));
  },
  set(val: boolean) {
    selected.value = val
      ? groups.value.flatMap((g) => g.items.map((i) => i.id))
      : [];
  },
});

function groupCheckedCount(g: Group) {
  return g.items.filter((i) => selectedSet.value.has(i.id)).length;
}

function isGroupChecked(g: Group) {
  return g.items.length > 0 && groupCheckedCount(g) === g.items.length;
}

function isGroupIndeterminate(g: Group) {
  const n = groupCheckedCount(g);
  return n > 0 && n < g.items.length;
}

function toggleGroup(g: Group, val: boolean) {
  const ids = new Set(g.items.map((i) => i.id));
  selected.value = val
    ? [...new Set([...selected.value, ...ids])]
    : selected.value.filter((id) => !ids.has(id));
}

function buildGroups(games: VendorGameRow[]) {
  const map = new Map<number, Group>();
  const sel: number[] = [];
  for (const g of games) {
    const typeId = Number(g.type_id);
    if (!map.has(typeId)) {
      map.set(typeId, {
        game_type_name: g.game_type_name ?? '',
        items: [],
        type_id: typeId,
      });
    }
    map.get(typeId)?.items.push(g);
    if (Number(g.status) === 1) sel.push(g.id);
  }
  groups.value = [...map.values()];
  selected.value = sel;
}

async function open(row: AgentGameListRow) {
  visible.value = true;
  current.value = row;
  loading.value = true;
  groups.value = [];
  selected.value = [];
  try {
    const games =
      (await getAgentGameApi({
        agent_id: row.agent_id ?? 0,
        id: row.id,
        vendor_id: row.vendor_id ?? 0,
      })) ?? [];
    buildGroups(games);
  } finally {
    loading.value = false;
  }
}

async function handleSave() {
  if (!current.value) return;
  saving.value = true;
  try {
    const res = await saveAgentGameApi({
      agent_id: current.value.agent_id ?? 0,
      games: selected.value,
      vendor_id: current.value.vendor_id ?? 0,
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
  <ElDialog v-model="visible" :title="tl('edit')" align-center width="760">
    <div v-loading="loading">
      <div class="mb-3 grid grid-cols-1 gap-2 md:grid-cols-3">
        <div>
          <span class="text-gray-500">{{ tl('label_vendor') }}：</span>
          <span class="text-green-600">{{ current?.vendor_name }}</span>
        </div>
        <div>
          <span class="text-gray-500">{{ tl('vendor_info_name') }}：</span>
          <span class="text-green-600">{{ current?.vendor_info_name }}</span>
        </div>
        <div>
          <span class="text-gray-500">{{ tl('agent') }}：</span>
          <span class="text-green-600">{{ current?.agent_username }}</span>
        </div>
      </div>

      <div class="mb-2">
        <ElCheckbox v-model="allChecked">{{ tl('select_all') }}</ElCheckbox>
      </div>

      <div v-for="g in groups" :key="g.type_id" class="mb-4">
        <div class="mb-2 border-b pb-1">
          <ElCheckbox
            :indeterminate="isGroupIndeterminate(g)"
            :model-value="isGroupChecked(g)"
            @change="(val) => toggleGroup(g, Boolean(val))"
          >
            <span class="font-bold">{{ g.game_type_name }}</span>
          </ElCheckbox>
        </div>
        <ElCheckboxGroup v-model="selected">
          <div
            class="agent-game-checks grid grid-cols-2 gap-x-4 gap-y-1 md:grid-cols-4"
          >
            <ElCheckbox v-for="item in g.items" :key="item.id" :value="item.id">
              {{ item.name }}
            </ElCheckbox>
          </div>
        </ElCheckboxGroup>
      </div>
    </div>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>

<style scoped>
/* 長遊戲名換行，避免文字溢出格子 */
.agent-game-checks :deep(.el-checkbox) {
  align-items: flex-start;
  height: auto;
  white-space: normal;
}

.agent-game-checks :deep(.el-checkbox__label) {
  line-height: 1.4;
  overflow-wrap: break-word;
  white-space: normal;
}
</style>
