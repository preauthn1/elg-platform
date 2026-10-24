<script lang="ts" setup>
import { computed, ref, watch } from 'vue';

import { Minus, Plus } from '@vben/icons';

import {
  ElButton,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { updateVendorCategoryApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'VendorCategoryPanel' });

const props = defineProps<{
  categoryName: string;
  gameTypes: Array<{ id: number; name: string }>;
  rows: number[][];
  typeId: number;
  vendors: Array<{ id: number; name: string }>;
}>();

const emit = defineEmits<{ saved: [] }>();

interface CategoryRow {
  game_type_id: number | string;
  id: number | string;
  vendor_id: number | string;
}

const editMode = ref(false);
const saving = ref(false);
const localRows = ref<CategoryRow[]>([]);
const newVendor = ref<number | string>('');
const newGameType = ref<number | string>('');

const vendorMap = computed(() => {
  const m: Record<string, string> = {};
  for (const v of props.vendors) m[v.id] = v.name;
  return m;
});
const gameTypeMap = computed(() => {
  const m: Record<string, string> = {};
  for (const g of props.gameTypes) m[g.id] = g.name;
  return m;
});

function resetRows() {
  localRows.value = (props.rows ?? []).map((r) => ({
    id: r[0] as number,
    vendor_id: r[1] as number,
    game_type_id: r[2] as number,
  }));
}

watch(() => props.rows, resetRows, { immediate: true });

function enterEdit() {
  editMode.value = true;
  newVendor.value = props.vendors[0]?.id ?? '';
  newGameType.value = props.gameTypes[0]?.id ?? '';
}

function cancelEdit() {
  editMode.value = false;
  resetRows();
}

function addRow() {
  if (newVendor.value === '' || newGameType.value === '') return;
  localRows.value.push({
    id: -1,
    vendor_id: newVendor.value,
    game_type_id: newGameType.value,
  });
}

function removeRow(index: number) {
  localRows.value.splice(index, 1);
}

async function save() {
  saving.value = true;
  try {
    const res = await updateVendorCategoryApi({
      type_id: props.typeId,
      ids: localRows.value.map((r) => r.id),
      vendors: localRows.value.map((r) => r.vendor_id),
      game_types: localRows.value.map((r) => r.game_type_id),
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message);
      editMode.value = false;
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
</script>

<template>
  <div>
    <div class="mb-3 flex items-center justify-between">
      <span class="text-lg font-bold">{{ categoryName }}</span>
      <div class="flex gap-2">
        <ElButton v-if="!editMode" type="primary" @click="enterEdit">
          {{ tl('edit') }}
        </ElButton>
        <template v-else>
          <ElButton :loading="saving" type="success" @click="save">
            {{ tl('btn_update') }}
          </ElButton>
          <ElButton @click="cancelEdit">{{ tl('cancel') }}</ElButton>
        </template>
      </div>
    </div>

    <div v-if="editMode" class="mb-3 flex flex-wrap items-end gap-2">
      <div>
        <div class="mb-1 text-sm text-gray-500">
          {{ tl('vendor_announcement') }}
        </div>
        <ElSelect v-model="newVendor" class="!w-48" filterable>
          <ElOption
            v-for="v in vendors"
            :key="v.id"
            :label="v.name"
            :value="v.id"
          />
        </ElSelect>
      </div>
      <div>
        <div class="mb-1 text-sm text-gray-500">{{ tl('game_type') }}</div>
        <ElSelect v-model="newGameType" class="!w-48" filterable>
          <ElOption
            v-for="g in gameTypes"
            :key="g.id"
            :label="g.name"
            :value="g.id"
          />
        </ElSelect>
      </div>
      <ElButton type="primary" @click="addRow">
        <Plus class="size-4" />
      </ElButton>
    </div>

    <ElTable :data="localRows" border size="small" stripe>
      <ElTableColumn :label="tl('vendor_announcement')" align="center">
        <template #default="{ row }">
          {{ vendorMap[row.vendor_id] ?? row.vendor_id }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('game_type')" align="center">
        <template #default="{ row }">
          {{ gameTypeMap[row.game_type_id] ?? row.game_type_id }}
        </template>
      </ElTableColumn>
      <ElTableColumn
        v-if="editMode"
        :label="tl('features')"
        align="center"
        width="100"
        fixed="right"
      >
        <template #default="{ $index }">
          <ElButton type="danger" size="small" @click="removeRow($index)">
            <Minus class="size-4" />
          </ElButton>
        </template>
      </ElTableColumn>
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>
  </div>
</template>
